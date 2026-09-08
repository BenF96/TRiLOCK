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
}
