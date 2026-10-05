<?php

namespace Tests\Feature\Admin;

use App\Jobs\CreateBackupJob;
use App\Jobs\QueueHeartbeat;
use App\Models\Backup;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y11: yedek kuyrukta alınır (işçi canlıysa), satır "Alınıyor" görünür; hata yöneticilere bildirilir. */
class BackupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
    }

    protected function tearDown(): void
    {
        foreach (glob(BackupService::directory().'/navluniq-*.zip') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_backup_is_queued_when_the_worker_heartbeat_is_fresh_and_the_job_completes_it(): void
    {
        Queue::fake();
        Cache::put(QueueHeartbeat::CACHE_KEY, now()->timestamp, now()->addDay());

        Volt::test('admin.backups-center')->call('createBackup', 'database')->assertSee('kuyruğa alındı')->assertSee('Alınıyor');
        $backup = Backup::first();
        $this->assertSame('running', $backup->status);
        $this->assertSame('database', $backup->backup_type);
        Queue::assertPushed(CreateBackupJob::class, fn (CreateBackupJob $job) => $job->backupId === $backup->id);

        // Yedek sürerken ikinci istek reddedilir.
        Volt::test('admin.backups-center')->call('createBackup', 'full')->assertSee('zaten alınıyor');
        $this->assertSame(1, Backup::count());

        (new CreateBackupJob($backup->id, auth()->id()))->handle(app(BackupService::class));
        $backup = $backup->fresh();
        $this->assertSame('completed', $backup->status, (string) $backup->failure_message);
        $this->assertFileExists(BackupService::path($backup));

        // Sonuçlanmış kaydı yeniden çalıştırmak bir şey yapmaz.
        (new CreateBackupJob($backup->id))->handle(app(BackupService::class));
        $this->assertSame(1, Backup::count());
    }

    public function test_backup_runs_inline_when_the_worker_is_stale(): void
    {
        Queue::fake();
        Cache::forget(QueueHeartbeat::CACHE_KEY);

        Volt::test('admin.backups-center')->call('createBackup', 'database')->assertSee('Yedek hazır');
        Queue::assertNothingPushed();
        $this->assertSame('completed', Backup::first()->status);
    }

    public function test_failed_backup_notifies_system_admins_and_marks_the_row(): void
    {
        $service = app(BackupService::class);
        $backup = Backup::create(['filename' => 'olmayan-klasor/navluniq-hata.zip', 'backup_type' => 'database', 'storage_disk' => 'local', 'storage_path' => 'backups/x', 'status' => 'running']);

        $done = $service->create('database', auth()->id(), $backup);
        $this->assertSame('failed', $done->status);
        $this->assertNotEmpty($done->failure_message);
        $this->assertSame(1, UserNotification::query()->where('user_id', auth()->id())->where('title', 'Yedek alınamadı')->count());

        // Kuyruk işi de çöktüğünde kayıt "failed" olur.
        $running = Backup::create(['filename' => 'navluniq-2026-01-01_000000-db.zip', 'backup_type' => 'database', 'storage_disk' => 'local', 'storage_path' => 'backups/y', 'status' => 'running']);
        (new CreateBackupJob($running->id))->failed(new \RuntimeException('işçi çöktü'));
        $this->assertSame('failed', $running->fresh()->status);
        $this->assertSame('işçi çöktü', $running->fresh()->failure_message);
    }
}
