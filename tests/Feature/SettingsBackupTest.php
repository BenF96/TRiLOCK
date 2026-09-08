<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_backup_route_never_returns_the_old_fake_success_message()
    {
        // pg_dump isn't available in the test environment, so this must
        // surface a real, honest failure — never the old canned
        // "Database backup snapshot created successfully" text.
        $response = $this->actingAs($this->admin())->post(route('settings.backup'));

        $response->assertRedirect();
        $this->assertNotNull(session('error'));
        $this->assertStringNotContainsString('created successfully', (string) session('error'));
    }

    public function test_download_rejects_path_traversal_and_unknown_filenames()
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/settings/backups/' . urlencode('../../../../etc/passwd'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/settings/backups/trilock_2020_01_01_000000.sql')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/settings/backups/hacked.sql')
            ->assertNotFound();
    }

    public function test_restore_rejects_an_unknown_backup_filename()
    {
        $response = $this->actingAs($this->admin())->post(route('settings.restore'), [
            'filename' => '../../etc/passwd',
        ]);

        $response->assertRedirect();
        $this->assertSame('Database restore failed: Unknown backup file.', session('error'));
    }

    public function test_service_lists_only_valid_backup_files_from_the_local_disk()
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/trilock_2026_01_01_000000.sql', '-- dump --');
        Storage::disk('local')->put('backups/not_a_backup.sql', 'ignored');

        $files = app(DatabaseBackupService::class)->listBackups();

        $this->assertCount(1, $files);
        $this->assertSame('trilock_2026_01_01_000000.sql', $files[0]['name']);
    }

    public function test_download_response_rejects_invalid_filenames_without_touching_storage()
    {
        Storage::fake('local');

        $service = app(DatabaseBackupService::class);

        $this->assertNull($service->downloadResponse('../../etc/passwd'));
        $this->assertNull($service->downloadResponse('trilock_2020_01_01_000000.sql'));
    }
}
