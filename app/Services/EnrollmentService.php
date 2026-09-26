<?php

namespace App\Services;

use App\Models\User;
use App\Models\RfidCard;
use App\Models\Fingerprint;
use App\Models\AccessPin;
use App\Models\EnrollmentLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * EnrollmentService
 *
 * Orchestrates the entire user enrollment workflow:
 *   1. Session management
 *   2. User info persistence (inside a DB transaction)
 *   3. PIN validation & storage
 *   4. Hardware credential synchronization (via Esp32EnrollmentClient)
 *   5. Atomic commit or full rollback
 *   6. Audit logging at every step
 */
class EnrollmentService
{
    private const SESSION_TTL = 3600; // 1 hour in seconds

    public function __construct(
        private readonly Esp32EnrollmentClient $esp32,
    ) {}

    // -------------------------------------------------------------------------
    // Session Management
    // -------------------------------------------------------------------------

    /**
     * Start a new enrollment session and return its unique token.
     *
     * $deviceId identifies which physical ESP32 main controller will perform the scans.
     * It matters as soon as more than one is registered: the poll cache key below is
     * scoped to it, so a second controller polling /api/esp32/enroll/poll never picks up
     * a command meant for a different device (see EnrollmentApiController::poll()).
     */
    public function startSession(int $adminId, ?int $deviceId = null): string
    {
        // The ESP32 only ever polls with its own device_id, so a session with no device
        // bound would queue commands nobody picks up - the wizard would just time out at
        // every step. Default to the only controller if there's exactly one.
        if (!$deviceId) {
            $controllers = \App\Models\Device::where('device_type', 'esp32_main')->pluck('id');
            if ($controllers->isEmpty()) {
                throw new \RuntimeException('No ESP32 main controller is registered. Add one under Devices first.');
            }
            if ($controllers->count() > 1) {
                throw new \RuntimeException('Select which ESP32 controller will perform the enrollment.');
            }
            $deviceId = $controllers->first();
        }

        $this->pruneAbandonedSessions();

        $token = Str::random(48);

        Cache::put("enrollment_session_{$token}", [
            'admin_id'          => $adminId,
            'device_id'         => $deviceId,
            'user_id'           => null,
            'fingerprint_id'    => null,
            'rfid_uid'          => null,
            'pin_hash'          => null,
            'photo_path'        => null,
            'started_at'        => now()->toDateTimeString(),
        ], self::SESSION_TTL);

        if ($deviceId) {
            Cache::put("active_enrollment_token_{$deviceId}", $token, self::SESSION_TTL);
        }

        $this->log($token, $adminId, null, 'session', 'started', [], 'Enrollment session created');

        return $token;
    }

    public function getSession(string $token): ?array
    {
        return Cache::get("enrollment_session_{$token}");
    }

    public function updateSession(string $token, array $data): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Enrollment session not found or expired.');
        }
        Cache::put("enrollment_session_{$token}", array_merge($session, $data), self::SESSION_TTL);
    }

    public function destroySession(string $token): void
    {
        $session = $this->getSession($token);
        if ($session && !empty($session['device_id'])) {
            // Only clear it if it's still pointing at this session — an already-newer
            // session for the same device must not have its active-token yanked out
            // from under it.
            if (Cache::get("active_enrollment_token_{$session['device_id']}") === $token) {
                Cache::forget("active_enrollment_token_{$session['device_id']}");
            }
        }

        Cache::forget("enrollment_session_{$token}");
        Cache::forget(Esp32EnrollmentClient::pinCacheKey($token));
        Cache::forget(Esp32EnrollmentClient::fingerprintCacheKey($token));
        Cache::forget(Esp32EnrollmentClient::rfidCacheKey($token));
        Cache::forget(Esp32EnrollmentClient::commandCacheKey($token));
    }

    // -------------------------------------------------------------------------
    // Step: Save User Info
    // -------------------------------------------------------------------------

    /**
     * Persist the user record (password is hashed by the User model cast).
     * Photo is stored to disk immediately; other credentials are added only on commit.
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \RuntimeException
     */
    public function saveUserInfo(string $token, array $data, ?string $photoPath): User
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Invalid or expired enrollment session.');
        }

        // If a user was previously saved for this session, clean it up first
        if ($session['user_id']) {
            User::find($session['user_id'])?->delete();
        }

        $user = User::create([
            'employee_student_id' => $data['employee_student_id'],
            'first_name'          => $data['first_name'],
            'middle_name'         => $data['middle_name'] ?? null,
            'last_name'           => $data['last_name'],
            'name'                => "{$data['first_name']} {$data['last_name']}",
            'email'               => $data['email'],
            'password'            => Hash::make($data['password'] ?? \Illuminate\Support\Str::random(32)),
            'role'                => $data['role'],
            'status'              => $data['status'],
            'photo'               => $photoPath,
        ]);

        $this->updateSession($token, [
            'user_id'    => $user->id,
            'photo_path' => $photoPath,
        ]);

        $this->log($token, $session['admin_id'], $user->id, 'user_info', 'completed',
            ['user_id' => $user->id], "User record created: {$user->full_name}");

        return $user;
    }

    // -------------------------------------------------------------------------
    // Step: PIN (via ESP32 keypad)
    // -------------------------------------------------------------------------

    /**
     * Command the ESP32 to enter PIN-input mode.
     * User types PIN on the hardware keypad; ESP32 POSTs it to /api/esp32/enroll/pin/result.
     */
    public function startPinEnrollment(string $token): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Invalid or expired enrollment session.');
        }

        // Clear any previous attempt results for this step
        Cache::forget(Esp32EnrollmentClient::pinCacheKey($token));

        $this->esp32->requestPinEnrollment($token);

        $this->log($token, $session['admin_id'], $session['user_id'],
            'pin', 'started', [], 'PIN enrollment command sent to ESP32 keypad');
    }

    /**
     * Called by EnrollmentApiController when ESP32 POSTs the keypad-entered PIN.
     */
    public function receivePinResult(string $token, string $plainPin): void
    {
        // Validate length
        if (strlen($plainPin) < 4 || strlen($plainPin) > 8 || !ctype_digit($plainPin)) {
            Cache::put(Esp32EnrollmentClient::pinCacheKey($token), [
                'status'  => 'error',
                'message' => 'PIN must be 4–8 numeric digits.',
            ], 300);
            throw new \RuntimeException('Invalid PIN format received from keypad.');
        }

        if ($this->pinInUse($plainPin)) {
            Cache::put(Esp32EnrollmentClient::pinCacheKey($token), [
                'status'  => 'error',
                'message' => 'This PIN is already assigned to another user.',
            ], 300);
            throw new \RuntimeException('PIN collision detected.');
        }

        $pinHash = Hash::make($plainPin);
        $pinOfflineHash = hash('sha256', $plainPin.config('trilock.offline_pin_salt'));

        Cache::put(Esp32EnrollmentClient::pinCacheKey($token), [
            'status'   => 'success',
            'received_at' => now()->toDateTimeString(),
        ], 300);

        $this->updateSession($token, ['pin_hash' => $pinHash, 'pin_offline_hash' => $pinOfflineHash]);

        $session = $this->getSession($token);
        $this->log($token, $session['admin_id'] ?? null, $session['user_id'] ?? null,
            'pin', 'completed', [], 'PIN received from ESP32 keypad and hashed');
    }

    // -------------------------------------------------------------------------
    // Step: Save PIN (web form — kept for backward compat, not used in HW mode)
    // -------------------------------------------------------------------------

    /**
     * Hash the plain-text PIN and store it in the session (not in DB yet).
     */
    public function savePin(string $token, string $plainPin): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Invalid or expired enrollment session.');
        }

        if ($this->pinInUse($plainPin)) {
            throw new \RuntimeException('This PIN is already assigned to another user.');
        }

        $this->updateSession($token, [
            'pin_hash' => Hash::make($plainPin),
            'pin_offline_hash' => hash('sha256', $plainPin.config('trilock.offline_pin_salt')),
        ]);
        $this->log($token, $session['admin_id'], $session['user_id'], 'pin', 'completed',
            [], 'PIN hashed and stored in session');
    }

    // -------------------------------------------------------------------------
    // Step: Fingerprint (via ESP32)
    // -------------------------------------------------------------------------

    public function startFingerprintEnrollment(string $token): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Invalid or expired enrollment session.');
        }

        // Clear any previous attempt results for this step
        Cache::forget(Esp32EnrollmentClient::fingerprintCacheKey($token));

        $this->esp32->requestFingerprintEnrollment($token);

        $this->log($token, $session['admin_id'], $session['user_id'],
            'fingerprint', 'started', [], 'Fingerprint enrollment command sent to ESP32');
    }

    /**
     * Called by EnrollmentApiController when ESP32 pushes back the fingerprint_id.
     */
    public function receiveFingerprintResult(string $token, int $fingerprintId): void
    {
        // The ESP32 picks the first slot that's empty ON THE SENSOR. If the DB still has a
        // record for that slot (sensor wiped/replaced, or a template purged by older
        // firmware), commit() would reject it - but only after the admin finished every
        // other step, and the device would keep choosing the same slot on each retry.
        // Say so now, with what to fix.
        $existing = Fingerprint::with('user')->where('fingerprint_id', $fingerprintId)->first();
        if ($existing) {
            $owner = $existing->user?->full_name ?? 'a deleted user';
            $message = "Sensor slot #{$fingerprintId} is empty on the device but still registered to {$owner} in the database. "
                     . "Delete that fingerprint record (Fingerprints page), then scan again.";
            Cache::put(Esp32EnrollmentClient::fingerprintCacheKey($token), [
                'status'  => 'error',
                'message' => $message,
            ], 300);
            throw new \RuntimeException($message);
        }

        // Store in cache — SSE stream will pick this up
        Cache::put(Esp32EnrollmentClient::fingerprintCacheKey($token), [
            'status'         => 'success',
            'fingerprint_id' => $fingerprintId,
            'received_at'    => now()->toDateTimeString(),
        ], 300);

        $this->updateSession($token, ['fingerprint_id' => $fingerprintId]);

        $session = $this->getSession($token);
        $this->log($token, $session['admin_id'] ?? null, $session['user_id'] ?? null,
            'fingerprint', 'completed', ['fingerprint_id' => $fingerprintId],
            "Fingerprint ID {$fingerprintId} received from ESP32");
    }

    // -------------------------------------------------------------------------
    // Step: RFID (via ESP32)
    // -------------------------------------------------------------------------

    public function startRfidEnrollment(string $token): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            throw new \RuntimeException('Invalid or expired enrollment session.');
        }

        // Clear any previous attempt results for this step
        Cache::forget(Esp32EnrollmentClient::rfidCacheKey($token));

        $this->esp32->requestRfidEnrollment($token);

        $this->log($token, $session['admin_id'], $session['user_id'],
            'rfid', 'started', [], 'RFID enrollment command sent to ESP32');
    }

    /**
     * Called by EnrollmentApiController when ESP32 pushes back the rfid_uid.
     */
    public function receiveRfidResult(string $token, string $rfidUid): void
    {
        $uid = RfidCard::normalizeUid($rfidUid);

        // Duplicate check
        if (RfidCard::where('card_uid', $uid)->exists()) {
            Cache::put(Esp32EnrollmentClient::rfidCacheKey($token), [
                'status' => 'error',
                'message' => "RFID UID {$uid} is already registered.",
            ], 300);
            throw new \RuntimeException("RFID UID {$uid} is already registered.");
        }

        Cache::put(Esp32EnrollmentClient::rfidCacheKey($token), [
            'status'   => 'success',
            'rfid_uid' => $uid,
            'received_at' => now()->toDateTimeString(),
        ], 300);

        $this->updateSession($token, ['rfid_uid' => $uid]);

        $session = $this->getSession($token);
        $this->log($token, $session['admin_id'] ?? null, $session['user_id'] ?? null,
            'rfid', 'completed', ['rfid_uid' => $uid], "RFID UID {$uid} received from ESP32");
    }

    // -------------------------------------------------------------------------
    // Step: Commit (atomic transaction)
    // -------------------------------------------------------------------------

    /**
     * Atomically write all enrollment data to the database.
     * If anything fails, the transaction is rolled back automatically.
     *
     * @throws \Throwable
     */
    public function commit(string $token): User
    {
        $session = $this->getSession($token);
        if (!$session || !$session['user_id']) {
            throw new \RuntimeException('No enrollment session data to commit.');
        }

        $user = User::findOrFail($session['user_id']);

        DB::transaction(function () use ($session, $user, $token) {
            // Fingerprint
            if ($session['fingerprint_id']) {
                // Guard against race-condition duplicates
                if (Fingerprint::where('fingerprint_id', $session['fingerprint_id'])->exists()) {
                    throw new \RuntimeException("Fingerprint ID {$session['fingerprint_id']} was registered by another user during enrollment.");
                }

                Fingerprint::create([
                    'user_id'        => $user->id,
                    'fingerprint_id' => $session['fingerprint_id'],
                    'status'         => 'active',
                    'registered_at'  => now(),
                ]);
            }

            // RFID
            if ($session['rfid_uid']) {
                if (RfidCard::where('card_uid', $session['rfid_uid'])->exists()) {
                    throw new \RuntimeException("RFID UID {$session['rfid_uid']} was registered by another user during enrollment.");
                }

                RfidCard::create([
                    'user_id'     => $user->id,
                    'card_uid'    => $session['rfid_uid'],
                    'status'      => 'active',
                    'assigned_at' => now(),
                ]);
            }

            // PIN
            if ($session['pin_hash']) {
                AccessPin::create([
                    'user_id'      => $user->id,
                    'pin_code'     => $session['pin_hash'],
                    'offline_hash' => $session['pin_offline_hash'] ?? null,
                    'status'       => 'active',
                    'assigned_at'  => now(),
                ]);
            }

            $this->log($token, $session['admin_id'], $user->id, 'commit', 'completed', [
                'fingerprint_id' => $session['fingerprint_id'],
                'rfid_uid'       => $session['rfid_uid'],
                'has_pin'        => !empty($session['pin_hash']),
            ], "Enrollment committed successfully for {$user->full_name}");
        });

        // Tell ESP32 enrollment is done
        try {
            $this->esp32->finishEnrollment($token);
        } catch (\Throwable) {
            // Non-fatal — DB transaction already committed
        }

        $this->destroySession($token);

        // Push the new user into the lock's offline cache now instead of on the next
        // 5-minute periodic sync.
        \App\Http\Controllers\UserController::notifyDevicesToSync();

        return $user->fresh(['activeRfidCard', 'activeFingerprint']);
    }

    // -------------------------------------------------------------------------
    // Step: Cancel / Rollback
    // -------------------------------------------------------------------------

    /**
     * Roll back any partial data created during the enrollment session.
     */
    public function cancel(string $token, string $reason = 'Admin cancelled'): void
    {
        $session = $this->getSession($token);
        if (!$session) {
            return;
        }

        if ($session['user_id']) {
            $user = User::find($session['user_id']);
            if ($user) {
                // Remove photo from storage
                if ($session['photo_path']) {
                    Storage::delete($session['photo_path']);
                }
                $user->delete(); // cascade deletes credentials via FK
            }
        }

        try {
            $this->esp32->cancelEnrollment($token);
        } catch (\Throwable) {
            // Non-fatal
        }

        // The user row is gone by now - enrolled_user_id is a foreign key to users, so
        // referencing it made this insert fail (silently, see log()) and every cancel went
        // unrecorded. Keep the id in the payload instead.
        $this->log($token, $session['admin_id'] ?? null, null,
            'cancel', 'rolled_back', ['deleted_user_id' => $session['user_id'] ?? null], $reason);

        $this->destroySession($token);
    }

    /**
     * Whether an active PIN already uses this code. Matches on the indexed SHA-256
     * offline_hash - one query - instead of bcrypt-checking every PIN in the system
     * (~250ms each), which with a few dozen users outlasted the ESP32's 8s timeout for
     * posting the result. Only legacy PINs saved before offline_hash existed still need
     * the slow bcrypt comparison.
     */
    private function pinInUse(string $plainPin): bool
    {
        $offlineHash = hash('sha256', $plainPin.config('trilock.offline_pin_salt'));

        if (AccessPin::where('status', 'active')->where('offline_hash', $offlineHash)->exists()) {
            return true;
        }

        return AccessPin::where('status', 'active')->whereNull('offline_hash')->get()
            ->contains(fn ($p) => Hash::check($plainPin, $p->pin_code));
    }

    /**
     * The user row is created at step 1, long before commit. A wizard abandoned after
     * that (tab closed, browser crash) left a credential-less user behind forever, since
     * its session simply expired from the cache. Roll those back - runs opportunistically
     * whenever a new session starts, so no scheduler is needed.
     */
    public function pruneAbandonedSessions(): int
    {
        $pruned = 0;

        $candidates = EnrollmentLog::where('step', 'user_info')
            ->where('status', 'completed')
            ->where('created_at', '<', now()->subSeconds(self::SESSION_TTL))
            ->whereNotNull('enrolled_user_id')
            ->whereNotIn('session_token', EnrollmentLog::whereIn('step', ['commit', 'cancel'])->select('session_token'))
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($candidates as $entry) {
            if ($this->getSession($entry->session_token)) {
                continue; // still live in the cache (TTL refreshed by recent activity)
            }

            $user = User::withCount(['rfidCards', 'fingerprints', 'accessPins'])->find($entry->enrolled_user_id);
            // Credentials are only ever written at commit - anything holding one was
            // committed (or edited by an admin since) and must not be touched.
            if ($user && $user->rfid_cards_count + $user->fingerprints_count + $user->access_pins_count === 0) {
                if ($user->photo) {
                    Storage::delete($user->photo);
                }
                $user->delete();
                $pruned++;
            }

            // enrolled_user_id must be null - it's a foreign key and the user may be gone
            $this->log($entry->session_token, $entry->admin_id, null,
                'cancel', 'rolled_back', ['deleted_user_id' => $entry->enrolled_user_id],
                'Enrollment abandoned (session expired before commit) - partial user removed');
        }

        return $pruned;
    }

    // -------------------------------------------------------------------------
    // Internal: Audit Logging
    // -------------------------------------------------------------------------

    private function log(
        string  $token,
        ?int    $adminId,
        ?int    $enrolledUserId,
        string  $step,
        string  $status,
        array   $payload = [],
        string  $remarks = ''
    ): void {
        try {
            EnrollmentLog::create([
                'admin_id'          => $adminId,
                'enrolled_user_id'  => $enrolledUserId,
                'session_token'     => $token,
                'step'              => $step,
                'status'            => $status,
                'payload'           => $payload,
                'remarks'           => $remarks,
            ]);
        } catch (\Throwable $e) {
            Log::error("EnrollmentService: failed to write audit log — {$e->getMessage()}");
        }
    }
}
