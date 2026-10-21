<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DoorLog;
use App\Models\Fingerprint;
use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoorStatusTest extends TestCase
{
    use RefreshDatabase;

    private Device $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        $this->main = Device::where('device_type', 'esp32_main')->first();
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    private function dashboardStatus(): string
    {
        return $this->actingAs(User::where('role', 'admin')->first())
            ->getJson('/dashboard/status')
            ->assertStatus(200)
            ->json('door_status');
    }

    private function heartbeat(array $snapshot): void
    {
        $this->postJson('/api/heartbeat', ['device_id' => $this->main->id] + $snapshot)->assertStatus(200);
    }

    public function test_heartbeat_snapshot_is_the_live_status()
    {
        $this->heartbeat(['door_closed' => true, 'lock' => 'locked', 'ajar_alarm' => false]);
        $this->assertSame('locked', $this->dashboardStatus());

        $this->heartbeat(['door_closed' => true, 'lock' => 'unlocked', 'ajar_alarm' => false]);
        $this->assertSame('unlocked', $this->dashboardStatus());

        $this->heartbeat(['door_closed' => false, 'lock' => 'unlocked', 'ajar_alarm' => false]);
        $this->assertSame('opened', $this->dashboardStatus());

        $this->heartbeat(['door_closed' => false, 'lock' => 'unlocked', 'ajar_alarm' => true]);
        $this->assertSame('left_open', $this->dashboardStatus());
    }

    public function test_denied_attempt_does_not_flip_an_open_door_to_locked()
    {
        $this->heartbeat(['door_closed' => false, 'lock' => 'unlocked', 'ajar_alarm' => false]);

        // Writes a 'locked' door_logs row - which used to become the "current" status
        $this->postJson('/api/access', [
            'auth_method' => 'rfid', 'card_uid' => 'DEADBEEF', 'device_id' => $this->main->id,
        ])->assertStatus(403);

        $this->assertSame('locked', DoorLog::latest('id')->value('status'));
        $this->assertSame('opened', $this->dashboardStatus());
    }

    public function test_granted_access_shows_unlocked_before_the_next_report()
    {
        $this->heartbeat(['door_closed' => true, 'lock' => 'locked', 'ajar_alarm' => false]);

        $card = RfidCard::where('status', 'active')->first();
        $this->postJson('/api/access', [
            'auth_method' => 'rfid', 'card_uid' => $card->card_uid, 'device_id' => $this->main->id,
        ])->assertJson(['status' => 'granted']);

        $this->assertSame('unlocked', $this->dashboardStatus());
    }

    public function test_queued_remote_unlock_is_not_shown_until_the_lock_receives_it()
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('unreachable'));
        $this->heartbeat(['door_closed' => true, 'lock' => 'locked', 'ajar_alarm' => false]);

        $this->actingAs(User::where('role', 'admin')->first())
            ->post('/door/unlock', ['device_id' => $this->main->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'next check-in'));

        // Only queued - the door hasn't moved
        $this->assertSame('locked', $this->dashboardStatus());

        // The lock's heartbeat picks it up (its snapshot predates executing the command)
        $this->postJson('/api/heartbeat', [
            'device_id' => $this->main->id, 'door_closed' => true, 'lock' => 'locked', 'ajar_alarm' => false,
        ])->assertJson(['pending_command' => 'unlock']);

        $this->assertSame('unlocked', $this->dashboardStatus());
    }

    public function test_door_events_update_live_status_and_snapshot_wins()
    {
        $this->heartbeat(['door_closed' => true, 'lock' => 'unlocked', 'ajar_alarm' => false]);

        $this->postJson('/api/door-status', [
            'status' => 'opened', 'device_id' => $this->main->id,
        ])->assertStatus(200);
        $this->assertSame('opened', $this->dashboardStatus());

        $this->postJson('/api/door-status', [
            'status' => 'left_open', 'device_id' => $this->main->id,
        ])->assertStatus(200);
        $this->assertSame('left_open', $this->dashboardStatus());

        // Closed then relocked (firmware sends "closed" then "locked")
        $this->postJson('/api/door-status', [
            'status' => 'closed', 'device_id' => $this->main->id,
            'door_closed' => true, 'lock' => 'unlocked', 'ajar_alarm' => false,
        ])->assertStatus(200);
        $this->assertSame('unlocked', $this->dashboardStatus());

        $this->postJson('/api/door-status', [
            'status' => 'locked', 'device_id' => $this->main->id,
            'door_closed' => true, 'lock' => 'locked', 'ajar_alarm' => false,
        ])->assertStatus(200);
        $this->assertSame('locked', $this->dashboardStatus());
    }

    public function test_falls_back_to_last_event_when_the_lock_never_reported_state()
    {
        DoorLog::create(['status' => 'closed', 'closed_at' => now(), 'remarks' => 'test']);

        $this->assertSame('closed', $this->dashboardStatus());
    }

    public function test_close_report_logs_the_device_time_and_full_open_cycle()
    {
        $closedAt = now()->subSeconds(20)->startOfSecond();

        $this->postJson('/api/door-status', [
            'status' => 'closed', 'device_id' => $this->main->id,
            'occurred_at' => $closedAt->timestamp, 'open_seconds' => 12,
        ])->assertStatus(200);

        $log = DoorLog::latest('id')->first();
        $this->assertTrue($log->closed_at->equalTo($closedAt));
        $this->assertTrue($log->opened_at->equalTo($closedAt->copy()->subSeconds(12)));
        $this->assertStringContainsString('(open 12s)', $log->remarks);
    }

    public function test_door_event_without_device_time_uses_server_time()
    {
        $this->postJson('/api/door-status', [
            'status' => 'locked', 'device_id' => $this->main->id, 'occurred_at' => 0,
        ])->assertStatus(200);

        $this->assertTrue(DoorLog::latest('id')->first()->locked_at->diffInSeconds(now(), true) < 5);
    }
}
