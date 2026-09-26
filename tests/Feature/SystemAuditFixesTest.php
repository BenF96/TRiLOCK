<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\AccessLog;
use App\Models\Device;
use App\Models\Fingerprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('unreachable'));
    }

    public function test_per_device_token_cannot_speak_for_another_device()
    {
        $main = Device::where('device_type', 'esp32_main')->first();
        $cam  = Device::where('device_type', 'esp32_cam')->first();

        $this->withHeader('X-Device-Token', $cam->api_token)
            ->postJson('/api/heartbeat', ['device_id' => $main->id])
            ->assertStatus(403);

        $this->withHeader('X-Device-Token', $cam->api_token)
            ->postJson('/api/heartbeat', ['device_id' => $cam->id])
            ->assertStatus(200);
    }

    public function test_credential_sync_does_not_overwrite_pending_remote_unlock()
    {
        $admin = User::where('role', 'admin')->first();
        $main  = Device::where('device_type', 'esp32_main')->first();

        $this->actingAs($admin)->post('/door/unlock', ['device_id' => $main->id]);
        UserController::notifyDevicesToSync([7]);
        UserController::notifyDevicesToSync([9]);

        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));

        // Door command first...
        $this->postJson('/api/heartbeat', ['device_id' => $main->id])
            ->assertJson(['pending_command' => 'unlock']);

        // ...then the sync, with both deletions merged rather than the first one lost
        $sync = $this->postJson('/api/heartbeat', ['device_id' => $main->id])
            ->assertJson(['pending_command' => 'sync_offline']);
        $this->assertEqualsCanonicalizing([7, 9], $sync->json('deleted_fingerprint_ids'));
    }

    public function test_offline_sync_keeps_disabled_fingerprints_on_the_sensor()
    {
        $fp = Fingerprint::first();
        $fp->update(['status' => 'disabled']);

        $response = $this->withHeader('X-Device-Token', config('trilock.device_api_key'))
            ->getJson('/api/offline-sync')
            ->assertStatus(200);

        // Still in the purge-protection list, but not usable offline
        $this->assertContains($fp->fingerprint_id, $response->json('active_fingerprint_ids'));
        $this->assertNotContains($fp->fingerprint_id, array_column($response->json('data'), 'fingerprint_id'));
    }

    public function test_offline_backfill_uses_device_timestamp_when_plausible()
    {
        $user = User::first();
        $when = now()->subHours(3)->startOfSecond();

        $id = $this->withHeader('X-Device-Token', config('trilock.device_api_key'))
            ->postJson('/api/access/offline-log', [
                'device_id'   => 1,
                'user_id'     => $user->id,
                'occurred_at' => $when->timestamp,
            ])->assertStatus(200)->json('access_log_id');

        $log = AccessLog::find($id);
        $this->assertSame($when->toDateString(), $log->getRawOriginal('date') ? substr($log->getRawOriginal('date'), 0, 10) : null);
        $this->assertSame($when->toTimeString(), $log->getRawOriginal('time'));

        // Nonsense clocks (device never got NTP time) fall back to arrival time
        $id = $this->withHeader('X-Device-Token', config('trilock.device_api_key'))
            ->postJson('/api/access/offline-log', [
                'device_id'   => 1,
                'user_id'     => $user->id,
                'occurred_at' => 12345,
            ])->assertStatus(200)->json('access_log_id');
        $this->assertSame(now()->toDateString(), substr(AccessLog::find($id)->getRawOriginal('date'), 0, 10));
    }

    public function test_image_upload_never_overwrites_an_existing_capture()
    {
        $log = AccessLog::create([
            'user_id'               => null,
            'authentication_method' => 'unauthorized',
            'access_type'           => 'denied',
            'captured_image'        => 'captures/original.jpg',
            'door_status'           => 'locked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
        ]);

        $this->withHeader('X-Device-Token', config('trilock.device_api_key'))
            ->post('/api/upload-image', [
                'image'         => UploadedFile::fake()->create('capture.jpg', 20, 'image/jpeg'),
                'access_log_id' => $log->id,
            ])->assertStatus(200);

        $this->assertSame('captures/original.jpg', $log->fresh()->captured_image);
    }

    public function test_changing_password_requires_current_password()
    {
        $admin = User::where('role', 'admin')->first();
        $form  = [
            'first_name'            => $admin->first_name,
            'last_name'             => $admin->last_name,
            'email'                 => $admin->email,
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->actingAs($admin)->put('/profile', $form)->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('admin1234', $admin->fresh()->password));

        $this->actingAs($admin)->put('/profile', $form + ['current_password' => 'admin1234'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('newpassword123', $admin->fresh()->password));
    }

    public function test_profile_name_change_does_not_need_current_password()
    {
        $admin = User::where('role', 'admin')->first();

        $r = $this->actingAs($admin)->put('/profile', [
            'first_name' => 'Renamed',
            'last_name'  => $admin->last_name,
            'email'      => $admin->email,
        ]);
        $r->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $admin->fresh()->first_name);
    }
}
