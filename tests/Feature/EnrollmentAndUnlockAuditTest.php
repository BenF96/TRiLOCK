<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\AccessPin;
use App\Models\Device;
use App\Models\EnrollmentLog;
use App\Models\Fingerprint;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\Esp32EnrollmentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrollmentAndUnlockAuditTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        Http::fake();
        $this->service = app(EnrollmentService::class);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->first();
    }

    // -------------------------------------------------------------------------
    // Enrollment
    // -------------------------------------------------------------------------

    public function test_session_without_device_binds_to_the_only_controller()
    {
        $main  = Device::where('device_type', 'esp32_main')->first();
        $token = $this->service->startSession($this->admin()->id);

        $this->assertSame($main->id, $this->service->getSession($token)['device_id']);
        $this->assertSame($token, Cache::get("active_enrollment_token_{$main->id}"));
    }

    public function test_session_without_any_controller_fails_loudly()
    {
        Device::where('device_type', 'esp32_main')->delete();

        $this->expectExceptionMessage('No ESP32 main controller is registered');
        $this->service->startSession($this->admin()->id);
    }

    public function test_steps_are_queued_for_polling_without_calling_the_device_directly()
    {
        $token = $this->service->startSession($this->admin()->id);
        $this->service->startFingerprintEnrollment($token);
        $this->service->startRfidEnrollment($token);

        Http::assertNothingSent();
        $this->assertSame('rfid', Cache::get(Esp32EnrollmentClient::commandCacheKey($token))['command']);
    }

    public function test_device_poll_records_pickup_and_a_new_step_clears_it()
    {
        $main  = Device::where('device_type', 'esp32_main')->first();
        $token = $this->service->startSession($this->admin()->id);
        $this->service->startPinEnrollment($token);

        $this->assertNull(Cache::get(Esp32EnrollmentClient::pickupCacheKey($token)));

        $this->getJson("/api/esp32/enroll/poll?device_id={$main->id}")
            ->assertJson(['status' => 'command_pending', 'command' => 'pin']);
        $this->assertSame('pin', Cache::get(Esp32EnrollmentClient::pickupCacheKey($token)));

        // Starting the next step must not inherit the previous step's pickup
        $this->service->startFingerprintEnrollment($token);
        $this->assertNull(Cache::get(Esp32EnrollmentClient::pickupCacheKey($token)));
    }

    public function test_abandoned_enrollment_user_is_removed_when_a_new_session_starts()
    {
        $token = $this->service->startSession($this->admin()->id);
        $user  = $this->service->saveUserInfo($token, [
            'employee_student_id' => 'ABANDON-1',
            'first_name' => 'Half', 'last_name' => 'Done',
            'email' => 'half@trilock.lab', 'role' => 'student', 'status' => 'active',
        ], null);

        // Session expires from the cache; its user_info log is older than the TTL
        Cache::forget("enrollment_session_{$token}");
        EnrollmentLog::where('session_token', $token)->update(['created_at' => now()->subHours(2)]);

        $this->service->startSession($this->admin()->id);

        $this->assertNull(User::find($user->id));
        $this->assertDatabaseHas('enrollment_logs', ['session_token' => $token, 'step' => 'cancel']);
    }

    public function test_cancel_is_recorded_in_the_audit_log()
    {
        $token = $this->service->startSession($this->admin()->id);
        $user  = $this->service->saveUserInfo($token, [
            'employee_student_id' => 'CANCEL-1',
            'first_name' => 'Never', 'last_name' => 'Mind',
            'email' => 'nevermind@trilock.lab', 'role' => 'student', 'status' => 'active',
        ], null);

        $this->service->cancel($token, 'Admin cancelled');

        $this->assertNull(User::find($user->id));
        $entry = EnrollmentLog::where('session_token', $token)->where('step', 'cancel')->first();
        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->payload['deleted_user_id']);
    }

    public function test_committed_user_is_never_pruned()
    {
        $token = $this->service->startSession($this->admin()->id);
        $user  = $this->service->saveUserInfo($token, [
            'employee_student_id' => 'DONE-1',
            'first_name' => 'Fully', 'last_name' => 'Done',
            'email' => 'done@trilock.lab', 'role' => 'student', 'status' => 'active',
        ], null);
        $this->service->receiveRfidResult($token, 'AA BB CC 01');
        $this->service->commit($token);

        EnrollmentLog::where('session_token', $token)->update(['created_at' => now()->subHours(2)]);
        $this->service->startSession($this->admin()->id);

        $this->assertNotNull(User::find($user->id));
    }

    public function test_duplicate_pin_detected_via_offline_hash()
    {
        $user = User::first();
        AccessPin::create([
            'user_id'      => $user->id,
            'pin_code'     => Hash::make('482913'),
            'offline_hash' => hash('sha256', '482913'.config('trilock.offline_pin_salt')),
            'status'       => 'active',
            'assigned_at'  => now(),
        ]);

        $token = $this->service->startSession($this->admin()->id);

        $this->postJson('/api/esp32/enroll/pin/result', [
            'session_token' => $token, 'status' => 'success', 'pin' => '482913',
        ])->assertStatus(422);

        $this->assertStringContainsString('already assigned',
            Cache::get(Esp32EnrollmentClient::pinCacheKey($token))['message']);
    }

    public function test_fingerprint_slot_still_registered_in_db_is_reported_immediately()
    {
        $existing = Fingerprint::first();
        $token    = $this->service->startSession($this->admin()->id);

        // Non-200 -> firmware deletes the template it just stored in that slot
        $this->postJson('/api/esp32/enroll/fingerprint/result', [
            'session_token' => $token, 'status' => 'success', 'fingerprint_id' => $existing->fingerprint_id,
        ])->assertStatus(500);

        $result = Cache::get(Esp32EnrollmentClient::fingerprintCacheKey($token));
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("slot #{$existing->fingerprint_id}", $result['message']);
        $this->assertNull($this->service->getSession($token)['fingerprint_id']);
    }

    // -------------------------------------------------------------------------
    // Credential unlock
    // -------------------------------------------------------------------------

    public function test_offline_denied_attempt_is_backfilled_and_attributed_to_card_owner()
    {
        $card = RfidCard::where('status', 'active')->first();

        $id = $this->postJson('/api/access/offline-log', [
            'device_id'     => 1,
            'card_uid'      => $card->card_uid,
            'denial_reason' => 'offline_no_match',
        ])->assertStatus(200)->json('access_log_id');

        $this->assertDatabaseHas('access_logs', [
            'id'            => $id,
            'user_id'       => $card->user_id,
            'access_type'   => 'denied',
            'denial_reason' => 'offline_no_match',
            'door_status'   => 'locked',
        ]);
    }

    public function test_offline_grant_still_requires_a_user()
    {
        $this->postJson('/api/access/offline-log', ['device_id' => 1])->assertStatus(422);
        $this->postJson('/api/access/offline-log', [
            'device_id' => 1, 'denial_reason' => 'made_up_reason',
        ])->assertStatus(422);
    }

    public function test_access_response_omits_ip_of_an_offline_camera()
    {
        $cam = Device::where('device_type', 'esp32_cam')->first();
        $cam->update(['ip_address' => '10.0.0.9', 'last_seen' => now()->subMinutes(10)]);

        $this->postJson('/api/access', ['auth_method' => 'rfid', 'card_uid' => 'DEADBEEF'])
            ->assertJson(['camera_ip' => null]);

        $cam->update(['last_seen' => now()]);
        $this->postJson('/api/access', ['auth_method' => 'rfid', 'card_uid' => 'DEADBEEF'])
            ->assertJson(['camera_ip' => '10.0.0.9']);
    }
}
