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

    public function test_non_admin_cannot_delete_logs()
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $log = $this->makeLog();

        $this->actingAs($student)->delete("/access-logs/{$log->id}")->assertRedirect(route('login'));

        $this->assertDatabaseHas('access_logs', ['id' => $log->id]);
    }
}
