<?php

namespace Tests\Feature\Ops;

use App\Models\Backup;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** I5: tek yedek yolu — yedek alınamazsa yöneticiye bildirim gider, komut başarısız döner; 6 saatlik döküm planlı. */
class BackupFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_backup_notifies_setting_managers_and_command_fails(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $driver = User::factory()->create();

        // /proc altında dizin açılamaz: yedek dizini yazılamaz durumu (dolu/bozuk disk) taklit edilir.
        config()->set('backup.directory', '/proc/navluniq-yedek-test');

        $this->artisan('system:backup', ['--type' => 'database'])->assertFailed();

        $backup = Backup::latest('id')->first();
        $this->assertSame('failed', $backup->status);
        $this->assertSame('database', $backup->backup_type);
        $this->assertStringContainsString('Yedek dizini', (string) $backup->failure_message);

        $notification = UserNotification::query()->where('user_id', $admin->id)->where('title', 'Yedek alınamadı')->first();
        $this->assertNotNull($notification, 'Ayar yöneticisine bildirim gitmeli');
        $this->assertStringContainsString('Veritabanı yedeği alınamadı', $notification->lines[0]);
        $this->assertSame(0, UserNotification::query()->where('user_id', $driver->id)->count(), 'Yetkisiz kullanıcıya bildirim gitmez');
    }

    public function test_database_dump_is_scheduled_every_six_hours_offset_from_full_backup(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'system:backup'));
        $this->assertCount(2, $events, 'Gece tam yedek + 6 saatlik döküm');

        $isDb = fn ($e) => (bool) preg_match("/--type=?'?database/", (string) $e->command);
        $db = $events->first($isDb);
        $this->assertNotNull($db);
        $this->assertSame('45 3,9,15,21 * * *', $db->expression);
        $this->assertStringContainsString('--keep=12', (string) $db->command);
        $this->assertTrue($db->withoutOverlapping);
        $this->assertSame(60, $db->expiresAt);

        $full = $events->first(fn ($e) => ! $isDb($e));
        $this->assertSame('30 3 * * *', $full->expression);
    }

    public function test_default_keep_depends_on_type(): void
    {
        $this->assertSame(7, BackupService::defaultKeep('full'));
        $this->assertSame(12, BackupService::defaultKeep('database'));
    }

    public function test_update_script_no_longer_installs_second_backup_cron(): void
    {
        foreach (['deploy/update.sh', 'deploy/install.sh'] as $file) {
            $script = file_get_contents(base_path($file));
            $this->assertStringNotContainsString('ensure_cron root "deploy/backup.sh"', $script, $file);
            $this->assertStringNotContainsString('0 3 * * * bash', $script, $file);
        }
        $this->assertStringContainsString('YALNIZ ELLE KULLANIM', file_get_contents(base_path('deploy/backup.sh')));
    }
}
