<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use App\Models\User;
use App\Models\AccessLog;
use App\Models\DoorLog;
use App\Models\Device;

class CameraAndDoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        Storage::fake('public');
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
        ]);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    public function test_esp32_cam_upload_image_and_link_to_access_log()
    {
        $admin = User::first();
        $log = AccessLog::create([
            'user_id'               => $admin->id,
            'authentication_method' => 'rfid',
            'access_type'           => 'entry',
            'door_status'           => 'unlocked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
        ]);

        $file = UploadedFile::fake()->create('security_capture.jpg', 50, 'image/jpeg');

        $response = $this->postJson('/api/upload-image', [
            'image'         => $file,
            'access_log_id' => $log->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Image uploaded successfully',
            ]);

        $log->refresh();
        $this->assertNotNull($log->captured_image);
        if (str_starts_with($log->captured_image, 'http')) {
            $this->assertStringContainsString('supabase.co', $log->captured_image);
            $this->assertEquals($log->captured_image, $log->captured_image_url);
        } else {
            Storage::disk('public')->assertExists($log->captured_image);
        }
    }

    public function test_esp32_cam_standalone_upload_creates_new_access_log()
    {
        $file = UploadedFile::fake()->create('standalone_capture.jpg', 50, 'image/jpeg');

        $response = $this->postJson('/api/upload-image', [
            'image' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Image uploaded successfully',
            ]);

        $createdLogId = $response->json('access_log_id');
        $this->assertNotNull($createdLogId);

        $log = AccessLog::find($createdLogId);
        $this->assertNotNull($log);
        $this->assertNotNull($log->captured_image);
        $this->assertEquals('unauthorized', $log->authentication_method);
    }

    public function test_esp32_cam_upload_with_device_id_marks_camera_online()
    {
        $camDevice = Device::where('device_type', 'esp32_cam')->first();
        $this->assertNotNull($camDevice->last_seen);
        $staleTime = now()->subMinutes(10);
        $camDevice->forceFill(['last_seen' => $staleTime])->save();
        $this->assertFalse($camDevice->fresh()->isOnline());

        $file = UploadedFile::fake()->create('cam_heartbeat.jpg', 50, 'image/jpeg');

        $response = $this->postJson('/api/upload-image', [
            'image'     => $file,
            'device_id' => $camDevice->id,
        ]);

        $response->assertStatus(200);

        $camDevice->refresh();
        $this->assertTrue($camDevice->isOnline());
        $this->assertEquals($camDevice->id, AccessLog::find($response->json('access_log_id'))->device_id);
    }

    public function test_esp32_door_status_event_update()
    {
        $response = $this->postJson('/api/door-status', [
            'status'  => 'opened',
            'remarks' => 'Door opened via magnetic switch trigger',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Door status logged',
            ]);

        $this->assertDatabaseHas('door_logs', [
            'status'  => 'opened',
            'remarks' => 'Door opened via magnetic switch trigger',
        ]);
    }

    public function test_admin_remote_unlock_action()
    {
        $admin = User::first();

        $response = $this->actingAs($admin)
            ->post('/door/unlock');

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('door_logs', [
            'status' => 'unlocked',
        ]);
    }

    public function test_cloud_remote_unlock_command_queued_and_delivered_via_heartbeat()
    {
        $admin = User::first();
        $mainDevice = Device::where('device_type', 'esp32_main')->first();

        // 1. Admin triggers unlock on cloud portal
        $this->actingAs($admin)->post('/door/unlock', [
            'device_id' => $mainDevice->id,
        ]);

        // 2. ESP32 sends periodic heartbeat to cloud server
        $heartbeatResponse = $this->postJson('/api/heartbeat', [
            'device_id' => $mainDevice->id,
        ]);

        $heartbeatResponse->assertStatus(200)
            ->assertJson([
                'status'          => 'ok',
                'pending_command' => 'unlock',
            ]);

        // 3. Next heartbeat has no pending command (it was consumed)
        $secondHeartbeat = $this->postJson('/api/heartbeat', [
            'device_id' => $mainDevice->id,
        ]);

        $secondHeartbeat->assertStatus(200)
            ->assertJson([
                'status'          => 'ok',
                'pending_command' => null,
            ]);
    }
}
