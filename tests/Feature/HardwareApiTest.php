<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\RfidCard;
use App\Models\Fingerprint;
use App\Models\Setting;

class HardwareApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    public function test_get_hardware_settings_api()
    {
        $response = $this->getJson('/api/settings');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'data' => [
                    'door_unlock_duration',
                    'auto_lock',
                    'camera_enabled',
                    'capture_entry',
                    'lab_name',
                ]
            ]);
    }

    public function test_rfid_access_granted_api()
    {
        $card = RfidCard::where('status', 'active')->first();

        $response = $this->postJson('/api/access', [
            'auth_method' => 'rfid',
            'access_type' => 'entry',
            'card_uid' => $card->card_uid,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'granted',
            ]);
    }

    public function test_rfid_access_denied_api()
    {
        $response = $this->postJson('/api/access', [
            'auth_method' => 'rfid',
            'access_type' => 'entry',
            'card_uid' => 'INVALID_UID_99',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'denied',
            ]);
    }

    public function test_multi_factor_triple_auth_granted_api()
    {
        $card = RfidCard::where('status', 'active')->first();
        $fp   = Fingerprint::where('status', 'active')->where('user_id', $card->user_id)->first();

        $response = $this->postJson('/api/access', [
            'auth_method'    => 'multi_factor',
            'access_type'    => 'entry',
            'card_uid'       => $card->card_uid,
            'fingerprint_id' => $fp->fingerprint_id,
            'pin_code'       => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'granted',
                'message' => 'Access Granted',
            ])
            ->assertJsonStructure([
                'status',
                'access_log_id',
                'unlock_duration',
            ]);
    }

    public function test_multi_factor_triple_auth_denied_when_pin_invalid()
    {
        $card = RfidCard::where('status', 'active')->first();
        $fp   = Fingerprint::where('status', 'active')->where('user_id', $card->user_id)->first();

        $response = $this->postJson('/api/access', [
            'auth_method'    => 'multi_factor',
            'access_type'    => 'entry',
            'card_uid'       => $card->card_uid,
            'fingerprint_id' => $fp->fingerprint_id,
            'pin_code'       => '999999', // Incorrect PIN
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'denied',
            ]);
    }

    public function test_door_status_logging_api()
    {
        $response = $this->postJson('/api/door-status', [
            'status' => 'opened',
            'remarks' => 'Magnetic contact sensor triggered',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    public function test_offline_access_backfill_logs_event_without_reauthorizing()
    {
        $card = RfidCard::where('status', 'active')->first();

        $response = $this->postJson('/api/access/offline-log', [
            'device_id'      => 1,
            'user_id'        => $card->user_id,
            'card_uid'       => $card->card_uid,
            'fingerprint_id' => 1,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'access_log_id',
            ]);

        $this->assertDatabaseHas('access_logs', [
            'id'                     => $response->json('access_log_id'),
            'user_id'                => $card->user_id,
            'authentication_method'  => 'multi_factor_offline',
            'access_type'            => 'entry',
            'door_status'            => 'unlocked',
            'captured_image'         => null,
        ]);
    }

    public function test_offline_access_backfill_rejects_unknown_user()
    {
        $response = $this->postJson('/api/access/offline-log', [
            'device_id' => 1,
            'user_id'   => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_denied_multi_factor_records_reason_and_card_owner()
    {
        $card = RfidCard::where('status', 'active')->first();
        $fp   = Fingerprint::where('status', 'active')->where('user_id', $card->user_id)->first();

        $response = $this->postJson('/api/access', [
            'auth_method'    => 'multi_factor',
            'card_uid'       => $card->card_uid,
            'fingerprint_id' => $fp->fingerprint_id,
            'pin_code'       => '999999',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'denied',
                'reason' => 'pin_incorrect',
            ])
            ->assertJsonStructure(['access_log_id', 'attempts_left', 'camera_ip']);

        $this->assertDatabaseHas('access_logs', [
            'id'            => $response->json('access_log_id'),
            'user_id'       => $card->user_id,
            'access_type'   => 'denied',
            'denial_reason' => 'pin_incorrect',
        ]);
    }

    public function test_denied_multi_factor_with_unknown_card()
    {
        $response = $this->postJson('/api/access', [
            'auth_method'    => 'multi_factor',
            'card_uid'       => 'DE AD BE EF',
            'fingerprint_id' => 1,
            'pin_code'       => '1234',
        ]);

        $response->assertStatus(403)->assertJson(['reason' => 'rfid_not_recognized']);

        $this->assertDatabaseHas('access_logs', [
            'id'            => $response->json('access_log_id'),
            'user_id'       => null,
            'denial_reason' => 'rfid_not_recognized',
        ]);
    }

    public function test_local_reject_from_device_is_logged_and_counts_toward_lockout()
    {
        $response = $this->postJson('/api/access', [
            'auth_method'   => 'local_reject',
            'denial_reason' => 'fingerprint_not_recognized',
            'device_id'     => 1,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status'        => 'denied',
                'reason'        => 'fingerprint_not_recognized',
                'attempts_left' => config('trilock.max_failed_attempts') - 1,
            ]);

        $this->assertDatabaseHas('access_logs', [
            'id'            => $response->json('access_log_id'),
            'access_type'   => 'denied',
            'denial_reason' => 'fingerprint_not_recognized',
            'device_id'     => 1,
        ]);
    }

    public function test_local_reject_rejects_unknown_denial_reason()
    {
        $this->postJson('/api/access', [
            'auth_method'   => 'local_reject',
            'denial_reason' => 'something_made_up',
        ])->assertStatus(422);
    }

    public function test_attempts_during_lockout_are_still_logged()
    {
        for ($i = 0; $i < config('trilock.max_failed_attempts'); $i++) {
            $this->postJson('/api/access', [
                'auth_method' => 'pin',
                'pin_code'    => '9999',
                'device_id'   => 1,
            ]);
        }

        $response = $this->postJson('/api/access', [
            'auth_method' => 'pin',
            'pin_code'    => '9999',
            'device_id'   => 1,
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'status' => 'locked_out',
                'reason' => 'lockout_active',
            ])
            ->assertJsonStructure(['access_log_id', 'lockout_seconds', 'camera_ip']);

        $this->assertDatabaseHas('access_logs', [
            'id'            => $response->json('access_log_id'),
            'access_type'   => 'denied',
            'denial_reason' => 'lockout_active',
        ]);
    }
}
