<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use App\Models\User;
use App\Models\AccessLog;

class AdminDeleteLogsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        Storage::fake();
        $this->admin = User::where('role', 'admin')->first();
    }

    private function makeLog(array $overrides = []): AccessLog
    {
        return AccessLog::create(array_merge([
            'user_id'               => $this->admin->id,
            'authentication_method' => 'multi_factor',
            'access_type'           => 'entry',
            'door_status'           => 'unlocked',
            'date'                  => now()->toDateString(),
            'time'                  => now()->toTimeString(),
        ], $overrides));
    }

    public function test_admin_deletes_audit_log_and_its_capture_file()
    {
        Storage::put('captures/snap.jpg', 'jpeg');
        $log = $this->makeLog(['captured_image' => 'captures/snap.jpg']);

        $this->actingAs($this->admin)->delete("/access-logs/{$log->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('access_logs', ['id' => $log->id]);
        Storage::assertMissing('captures/snap.jpg');
    }

    public function test_deleting_camera_capture_keeps_the_access_event()
    {
        Storage::put('captures/snap.jpg', 'jpeg');
        $log = $this->makeLog(['captured_image' => 'captures/snap.jpg']);

        $this->actingAs($this->admin)->delete("/camera/{$log->id}")->assertRedirect();

        $this->assertNull($log->fresh()->captured_image);
        Storage::assertMissing('captures/snap.jpg');
    }

    public function test_deleting_standalone_camera_capture_removes_the_record()
    {
        $log = $this->makeLog([
            'user_id'               => null,
            'authentication_method' => 'unauthorized',
            'access_type'           => 'denied',
            'denial_reason'         => 'unidentified_capture',
            'door_status'           => 'locked',
            'captured_image'        => 'captures/stranger.jpg',
        ]);

        $this->actingAs($this->admin)->delete("/camera/{$log->id}")->assertRedirect();

        $this->assertDatabaseMissing('access_logs', ['id' => $log->id]);
    }

    public function test_supabase_capture_is_deleted_from_bucket()
    {
        config([
            'services.supabase.url'              => 'https://proj.supabase.co',
            'services.supabase.service_role_key' => 'key',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        $url = 'https://proj.supabase.co/storage/v1/object/public/captures/2026-10-04/snap.jpg';
        $log = $this->makeLog(['captured_image' => $url]);

        $this->actingAs($this->admin)->delete("/access-logs/{$log->id}")->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://proj.supabase.co/storage/v1/object/captures/2026-10-04/snap.jpg');
    }

    public function test_delete_all_logs_without_filters_clears_everything()
    {
        Storage::put('captures/a.jpg', 'jpeg');
        $this->makeLog(['captured_image' => 'captures/a.jpg']);
        $this->makeLog(['access_type' => 'denied']);

        $this->actingAs($this->admin)->delete('/access-logs')
            ->assertRedirect(route('logs.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, AccessLog::count());
        Storage::assertMissing('captures/a.jpg');
    }

    public function test_delete_all_logs_only_removes_rows_matching_filters()
    {
        AccessLog::query()->delete();
        $entry = $this->makeLog(['access_type' => 'entry']);
        $denied = $this->makeLog(['access_type' => 'denied']);

        $this->actingAs($this->admin)->delete('/access-logs', ['access_type' => 'denied']);

        $this->assertDatabaseHas('access_logs', ['id' => $entry->id]);
        $this->assertDatabaseMissing('access_logs', ['id' => $denied->id]);
    }

    public function test_delete_all_captures_keeps_linked_events_and_drops_standalone_ones()
    {
        AccessLog::query()->delete();
        Storage::put('captures/a.jpg', 'jpeg');
        $linked = $this->makeLog(['captured_image' => 'captures/a.jpg']);
        $standalone = $this->makeLog([
            'user_id'               => null,
            'authentication_method' => 'unauthorized',
            'access_type'           => 'denied',
            'denial_reason'         => 'unidentified_capture',
            'captured_image'        => 'captures/b.jpg',
        ]);
        $noPhoto = $this->makeLog();

        $this->actingAs($this->admin)->delete('/camera')->assertRedirect(route('camera.index'));

        $this->assertNull($linked->fresh()->captured_image);
        $this->assertDatabaseMissing('access_logs', ['id' => $standalone->id]);
        $this->assertDatabaseHas('access_logs', ['id' => $noPhoto->id]);
        Storage::assertMissing('captures/a.jpg');
    }

    public function test_delete_all_supabase_captures_uses_one_bulk_request()
    {
        config([
            'services.supabase.url'              => 'https://proj.supabase.co',
            'services.supabase.service_role_key' => 'key',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        AccessLog::query()->delete();
        $prefix = 'https://proj.supabase.co/storage/v1/object/public/captures/';
        $this->makeLog(['captured_image' => $prefix . 'x.jpg']);
        $this->makeLog(['captured_image' => $prefix . 'y.jpg']);

        $this->actingAs($this->admin)->delete('/camera');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://proj.supabase.co/storage/v1/object/captures'
            && collect($request['prefixes'])->sort()->values()->all() === ['x.jpg', 'y.jpg']);
    }

    public function test_pages_show_delete_all_buttons()
    {
        $this->makeLog(['captured_image' => 'captures/a.jpg']);

        $this->actingAs($this->admin)->get('/access-logs')->assertOk()->assertSee('Delete All');
        $this->actingAs($this->admin)->get('/access-logs?access_type=entry')->assertOk()->assertSee('Delete Filtered');
        $this->actingAs($this->admin)->get('/camera')->assertOk()->assertSee('Delete All');
    }

    public function test_non_admin_cannot_delete_all_logs()
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $log = $this->makeLog();

        $this->actingAs($student)->delete('/access-logs')->assertRedirect(route('login'));

        $this->assertDatabaseHas('access_logs', ['id' => $log->id]);
    }

    public function test_non_admin_cannot_delete_logs()
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $log = $this->makeLog();

        $this->actingAs($student)->delete("/access-logs/{$log->id}")->assertRedirect(route('login'));

        $this->assertDatabaseHas('access_logs', ['id' => $log->id]);
    }
}
