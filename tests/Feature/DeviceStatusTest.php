<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DoorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    private function dashboardStatus(): array
    {
        Cache::forget('trilock_dashboard_status');

        return $this->actingAs(User::first())->getJson('/dashboard/status')->assertOk()->json();
    }

    private function deviceStatus(array $status, int $id): string
    {
        return collect($status['devices'])->firstWhere('id', $id)['status'];
    }

    public function test_device_goes_offline_once_heartbeats_stop()
    {
        $main = Device::where('device_type', 'esp32_main')->first();

        $this->postJson('/api/heartbeat', ['device_id' => $main->id])->assertOk();
        $this->assertSame('online', $this->deviceStatus($this->dashboardStatus(), $main->id));

        $this->travel(Device::ONLINE_THRESHOLD_SECONDS + 1)->seconds();
        $this->assertSame('offline', $this->deviceStatus($this->dashboardStatus(), $main->id));
    }

    public function test_enrollment_poll_counts_as_a_check_in()
    {
        $main = Device::where('device_type', 'esp32_main')->first();
        $main->forceFill(['last_seen' => now()->subMinutes(10)])->save();

        $this->getJson("/api/esp32/enroll/poll?device_id={$main->id}")->assertOk();

        $this->assertTrue($main->fresh()->isOnline());
    }

    public function test_rapid_check_ins_skip_redundant_writes_but_ip_changes_still_save()
    {
        $main = Device::where('device_type', 'esp32_main')->first();
        $main->forceFill(['last_seen' => now()->subMinutes(10)])->save();
        $this->assertTrue($main->markSeen());

        $this->travel(5)->seconds();
        $this->assertFalse($main->fresh()->markSeen());
        $this->assertTrue($main->fresh()->markSeen('192.168.1.200'));
        $this->assertSame('192.168.1.200', $main->fresh()->ip_address);

        $this->travel(Device::SEEN_WRITE_INTERVAL_SECONDS + 1)->seconds();
        $this->assertTrue($main->fresh()->markSeen());
    }

    public function test_door_status_is_flagged_stale_while_main_controller_is_offline()
    {
        DoorLog::create(['status' => 'unlocked', 'remarks' => 'test']);
        $main = Device::where('device_type', 'esp32_main')->first();

        $main->forceFill(['last_seen' => now()])->save();
        $this->assertTrue($this->dashboardStatus()['door_live']);

        $main->forceFill(['last_seen' => now()->subMinutes(5)])->save();
        $status = $this->dashboardStatus();
        $this->assertFalse($status['door_live']);
        $this->assertSame('unlocked', $status['door_status']);
    }
}
