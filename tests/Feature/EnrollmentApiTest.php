<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\Esp32EnrollmentClient;
use Illuminate\Support\Facades\Cache;

class EnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    public function test_esp32_enroll_poll_idle_when_no_active_session()
    {
        $response = $this->getJson('/api/esp32/enroll/poll');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'idle',
                'message' => 'No active enrollment command',
            ]);
    }

    public function test_esp32_enroll_poll_receives_queued_command()
    {
        $token = 'test_token_12345';
        $deviceId = 99;

        // Scoped per device so a different controller's poll can never pick this up —
        // see EnrollmentApiController::poll().
        Cache::put("active_enrollment_token_{$deviceId}", $token, 60);
        Esp32EnrollmentClient::queueCommand($token, 'fingerprint');

        $response = $this->getJson("/api/esp32/enroll/poll?device_id={$deviceId}");

        $response->assertStatus(200)
            ->assertJson([
                'status'        => 'command_pending',
                'session_token' => $token,
                'command'       => 'fingerprint',
            ]);
    }

    public function test_esp32_enroll_poll_ignores_command_queued_for_a_different_device()
    {
        $token = 'test_token_other_device';
        Cache::put('active_enrollment_token_1', $token, 60);
        Esp32EnrollmentClient::queueCommand($token, 'fingerprint');

        // A different device polling must never see device 1's pending command.
        $response = $this->getJson('/api/esp32/enroll/poll?device_id=2');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'idle',
                'message' => 'No active enrollment command',
            ]);
    }

    public function test_esp32_enroll_pin_result_stores_hashed_pin()
    {
        $admin = User::first();
        $service = app(EnrollmentService::class);
        $token = $service->startSession($admin->id);

        $response = $this->postJson('/api/esp32/enroll/pin/result', [
            'session_token' => $token,
            'pin'           => '5678',
            'status'        => 'success',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'ok',
                'message' => 'PIN credential received and hashed.',
            ]);

        $session = $service->getSession($token);
        $this->assertNotEmpty($session['pin_hash']);
    }

    public function test_esp32_enroll_rfid_result_stores_card_uid()
    {
        $admin = User::first();
        $service = app(EnrollmentService::class);
        $token = $service->startSession($admin->id);

        $response = $this->postJson('/api/esp32/enroll/rfid/result', [
            'session_token' => $token,
            'rfid_uid'      => 'A1 B2 C3 D4',
            'status'        => 'success',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'ok',
                'message' => 'RFID UID A1 B2 C3 D4 received.',
            ]);

        $session = $service->getSession($token);
        $this->assertEquals('A1 B2 C3 D4', $session['rfid_uid']);
    }

    public function test_esp32_enroll_fingerprint_result_stores_template_id()
    {
        $admin = User::first();
        $service = app(EnrollmentService::class);
        $token = $service->startSession($admin->id);

        $response = $this->postJson('/api/esp32/enroll/fingerprint/result', [
            'session_token'  => $token,
            'fingerprint_id' => 12,
            'status'         => 'success',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'ok',
                'message' => 'Fingerprint ID 12 received.',
            ]);

        $session = $service->getSession($token);
        $this->assertEquals(12, $session['fingerprint_id']);
    }
}
