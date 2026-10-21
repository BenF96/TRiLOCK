<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use App\Models\User;
use App\Models\Device;

class DeviceAuthAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        Cache::flush();
    }

    public function test_api_rejects_requests_without_device_token()
    {
        $response = $this->getJson('/api/settings');

        $response->assertStatus(401)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Unauthorized: Missing X-Device-Token header.',
            ]);
    }

    public function test_api_rejects_requests_with_invalid_device_token()
    {
        $response = $this->withHeaders([
            'X-Device-Token' => 'wrong_token_12345',
        ])->getJson('/api/settings');

        $response->assertStatus(401)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Unauthorized: Invalid device authentication token.',
            ]);
    }

    public function test_api_accepts_requests_with_valid_master_device_token()
    {
        $response = $this->withHeaders([
            'X-Device-Token' => config('trilock.device_api_key'),
        ])->getJson('/api/settings');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    public function test_api_accepts_requests_with_database_device_token()
    {
        $device = Device::where('device_type', 'esp32_main')->first();

        $response = $this->withHeaders([
            'X-Device-Token' => $device->api_token,
        ])->getJson('/api/settings');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    public function test_repeated_failed_attempts_never_lock_out()
    {
        $token = config('trilock.device_api_key');

        for ($i = 1; $i <= 10; $i++) {
            $this->withHeaders(['X-Device-Token' => $token])
                ->postJson('/api/access', [
                    'auth_method' => 'pin',
                    'pin_code'    => '9999',
                    'device_id'   => 2,
                ])
                ->assertStatus(403)
                ->assertJson(['status' => 'denied']);
        }
    }
}
