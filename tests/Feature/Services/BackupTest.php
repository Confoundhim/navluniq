<?php

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (glob(BackupService::directory().'/navluniq-*.zip') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles(['super_admin']);

        return $user->fresh();
    }

    public function test_full_backup_contains_database_dump_env_and_files_and_old_ones_are_pruned(): void
    {
        $kyc = storage_path('app/kyc/users/test-backup');
        @mkdir($kyc, 0755, true);
        file_put_contents($kyc.'/belge.txt', 'kyc');
        try {
            $service = app(BackupService::class);
            $backup = $service->create('full');
            $this->assertSame('completed', $backup->status, (string) $backup->failure_message);
            $path = BackupService::path($backup);
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, $backup->size_bytes);
            $this->assertSame(hash_file('sha256', $path), $backup->sha256);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName('database.sql'));
            $this->assertNotFalse($zip->locateName('BENIOKU.txt'));
            $this->assertNotFalse($zip->locateName('storage/kyc/users/test-backup/belge.txt'));
            $this->assertStringContainsString('-- TABLE users', (string) $zip->getFromName('database.sql'));
            $zip->close();

            $db = $service->create('database');
            $this->assertSame('completed', $db->status);
            $this->assertStringEndsWith('-db.zip', $db->filename);

            $this->artisan('system:backup', ['--keep' => 2])->assertSuccessful(); // üçüncü yedek alınır, sınır 2 → en eski silinir
            $this->assertSame(2, Backup::where('status', 'completed')->count());
            $this->assertFileDoesNotExist($path, 'En eski yedek dosyasıyla birlikte silinir');
            $this->assertSame(0, $service->prune(2));
        } finally {
            @unlink($kyc.'/belge.txt');
            @rmdir($kyc);
        }
    }

    public function test_backup_page_creates_lists_downloads_and_deletes(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.backups'))->assertOk()->assertSee('Yedekleme');

        Volt::test('admin.backups-center')->call('createBackup', 'full')->assertSee('Yedek hazır')->assertSee('navluniq-');
        $backup = Backup::first();
        $this->assertSame('completed', $backup->status);

        $this->get(route('admin.backups.download', $backup))->assertOk()->assertHeader('content-type', 'application/zip');

        Volt::test('admin.backups-center')->call('deleteBackup', $backup->id)->assertSee('Yedek silindi');
        $this->assertSame(0, Backup::count());
        $this->assertFileDoesNotExist(BackupService::path($backup));
    }

    public function test_backup_download_requires_admin_permission(): void
    {
        $backup = Backup::create(['filename' => 'navluniq-2026-01-01_0000.zip', 'backup_type' => 'full', 'status' => 'completed']);
        $driver = User::factory()->create(['current_role' => 'driver']);
        $this->actingAs($driver)->get(route('admin.backups.download', $backup))->assertRedirect();
        $this->actingAs($driver)->get(route('admin.backups'))->assertRedirect();
        $this->assertFalse($driver->can('manage settings'));
    }
}
