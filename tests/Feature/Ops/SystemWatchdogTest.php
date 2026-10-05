<?php

namespace Tests\Feature\Ops;

use App\Jobs\QueueHeartbeat;
use App\Models\Backup;
use App\Models\IntakeEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SystemWatchdog;
use App\Services\TelegramPublisher;
use App\Support\Settings;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** I1: sistem bekçisi — her denetim sahte durumla, iletim (Telegram + panel), tekrar engeli, düzelme; /up gerçek sağlık. */
class SystemWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
        Cache::flush();
        // Gerçek disk yerine sağlıklı sahte değer (geliştirme kabı dolu olabilir); disk testi kendi değerini verir.
        config()->set('watchdog.disk', ['free' => 20 * 1024 ** 3, 'total' => 40 * 1024 ** 3]);
        // Testler 12:00'de çalışır (telefon sessizliği denetimi gündüz saatine bakar).
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function watch(): array
    {
        return app(SystemWatchdog::class)->run();
    }

    private function adminNotifications(string $title): int
    {
        return UserNotification::query()->where('user_id', $this->admin->id)->where('title', $title)->count();
    }

    public function test_healthy_system_produces_no_alert_and_stores_last_run(): void
    {
        $r = $this->watch();

        $this->assertSame([], $r['alerts']);
        $this->assertCount(10, $r['checks']);
        $this->assertSame(0, UserNotification::query()->count());

        $status = SystemWatchdog::lastStatus();
        $this->assertNotNull($status['last_run']);
        $this->assertFalse($status['stale']);
        $this->assertNull($status['last_alert']);
    }

    public function test_stale_queue_heartbeat_alerts_once_via_telegram_and_panel_then_reports_recovery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Settings::set('telegram_bot_token', 'test-token');
        Settings::set('alert_telegram_chat_id', '123456789');
        Cache::put(QueueHeartbeat::CACHE_KEY, now()->subMinutes(12)->timestamp, now()->addDay());

        $r = $this->watch();
        $this->assertArrayHasKey('queue', $r['alerts']);
        $this->assertSame(['queue'], $r['sent']);
        $this->assertSame(1, $this->adminNotifications('⚠️ NavlunIQ uyarı'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bottest-token/sendMessage') && $req['chat_id'] === '123456789' && str_contains($req['text'], 'Kuyruk işçisi'));

        // Aynı sorun sürüyor: 6 saat içinde tekrar gönderilmez.
        $r2 = $this->watch();
        $this->assertArrayHasKey('queue', $r2['alerts']);
        $this->assertSame([], $r2['sent']);
        $this->assertSame(1, $this->adminNotifications('⚠️ NavlunIQ uyarı'));
        Http::assertSentCount(1);

        // İşçi tekrar iş alıyor: "düzeldi" gider.
        Cache::put(QueueHeartbeat::CACHE_KEY, now()->timestamp, now()->addDay());
        $r3 = $this->watch();
        $this->assertSame([], $r3['alerts']);
        $this->assertSame(['queue'], $r3['recovered']);
        $this->assertSame(1, $this->adminNotifications('✅ NavlunIQ düzeldi'));
        Http::assertSentCount(2);
        $this->assertSame(['queue'], SystemWatchdog::lastStatus()['last_alert']['recovered']);
    }

    public function test_alert_goes_only_to_panel_when_telegram_chat_is_not_set(): void
    {
        Http::fake();
        Cache::put(QueueHeartbeat::CACHE_KEY, now()->subMinutes(12)->timestamp, now()->addDay());

        $this->watch();

        Http::assertNothingSent();
        $this->assertSame(1, $this->adminNotifications('⚠️ NavlunIQ uyarı'));
    }

    public function test_new_failed_jobs_alert_after_baseline_and_never_report_recovery(): void
    {
        $this->watch(); // eşik kurulur
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessNotificationMessage']),
            'exception' => "RuntimeException: Yapay zeka cevap vermedi\n#0 trace", 'failed_at' => now(),
        ]);

        $r = $this->watch();
        $this->assertArrayHasKey('failed_jobs', $r['alerts']);
        $this->assertStringContainsString('ProcessNotificationMessage', $r['alerts']['failed_jobs']);
        $this->assertStringContainsString('Yapay zeka cevap vermedi', $r['alerts']['failed_jobs']);

        $r2 = $this->watch();
        $this->assertArrayNotHasKey('failed_jobs', $r2['alerts']);
        $this->assertSame([], $r2['recovered'], 'Olay niteliğindeki uyarı için "düzeldi" gönderilmez');
    }

    public function test_jobs_backlog_alerts_above_500(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('jobs')->insert($chunk);
        }

        $this->assertStringContainsString('501 iş', $this->watch()['alerts']['jobs_backlog']);
    }

    public function test_backup_alerts_on_failure_or_old_full_backup(): void
    {
        Backup::create(['filename' => 'navluniq-a.zip', 'backup_type' => 'full', 'storage_disk' => 'local', 'storage_path' => 'backups/a', 'status' => 'completed', 'completed_at' => now()->subHours(30)]);
        $this->assertStringContainsString('30 saat önce', $this->watch()['alerts']['backup']);

        Backup::create(['filename' => 'navluniq-b.zip', 'backup_type' => 'full', 'storage_disk' => 'local', 'storage_path' => 'backups/b', 'status' => 'completed', 'completed_at' => now()->subHours(2)]);
        $this->assertArrayNotHasKey('backup', $this->watch()['alerts']);

        Backup::create(['filename' => 'navluniq-c-db.zip', 'backup_type' => 'database', 'storage_disk' => 'local', 'storage_path' => 'backups/c', 'status' => 'failed', 'failure_message' => 'disk dolu']);
        $this->assertStringContainsString('disk dolu', $this->watch()['alerts']['backup']);
    }

    public function test_disk_alerts_when_free_space_is_low(): void
    {
        config()->set('watchdog.disk', ['free' => 1.5 * 1024 ** 3, 'total' => 40 * 1024 ** 3]);
        $this->assertStringContainsString('Disk doluyor', $this->watch()['alerts']['disk']);

        config()->set('watchdog.disk', ['free' => 5 * 1024 ** 3, 'total' => 40 * 1024 ** 3]);
        $this->assertStringContainsString('%12', $this->watch()['alerts']['disk']);

        config()->set('watchdog.disk', ['free' => 20 * 1024 ** 3, 'total' => 40 * 1024 ** 3]);
        $this->assertArrayNotHasKey('disk', $this->watch()['alerts']);
    }

    public function test_intake_silence_counts_from_morning_and_only_in_daytime(): void
    {
        IntakeEvent::record('created', ['source_name' => 'Grup', 'created_at' => now()->subHours(4)]);
        IntakeEvent::query()->update(['created_at' => now()->subHours(4)]);
        $this->assertStringContainsString('4 saattir', $this->watch()['alerts']['intake_silence']);

        // Sabah 08:00: son mesaj gece 23:00'te; sayaç 07:00'dan başlar → 1 saat, uyarı yok.
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', config('app.timezone')));
        IntakeEvent::query()->update(['created_at' => now()->subHours(9)]);
        $this->assertArrayNotHasKey('intake_silence', $this->watch()['alerts']);

        // Gece 02:00: hiç uyarı yok.
        Carbon::setTestNow(Carbon::parse('2026-10-05 02:00:00', config('app.timezone')));
        IntakeEvent::query()->update(['created_at' => now()->subHours(9)]);
        $this->assertArrayNotHasKey('intake_silence', $this->watch()['alerts']);

        // Telefon hiç bağlanmadıysa (kurulum) uyarı yok.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
        IntakeEvent::query()->delete();
        $this->assertArrayNotHasKey('intake_silence', $this->watch()['alerts']);
    }

    public function test_mail_failures_above_ten_per_hour_alert(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 11; $i++) {
            UserNotification::create(['user_id' => $user->id, 'type' => 'general', 'title' => "Deneme {$i}", 'lines' => ['x'], 'mail_status' => UserNotification::MAIL_FAILED, 'mail_attempts' => 1]);
        }
        $this->assertStringContainsString('11 e-posta', $this->watch()['alerts']['mail']);
    }

    public function test_redis_check_runs_only_when_redis_is_used_and_reports_connection_failure(): void
    {
        $this->assertArrayNotHasKey('redis', $this->watch()['alerts']);

        // Önbellek sürücüsü değiştirilmez (bekçinin kendi tekrar kilidi önbellekte); kuyruk Redis'e alınır.
        config()->set('queue.default', 'redis');
        config()->set('database.redis.default.host', '127.0.0.1');
        config()->set('database.redis.default.port', 1); // kapalı port: bağlantı reddedilir
        config()->set('database.redis.default.timeout', 0.5);
        $r = $this->watch();
        $this->assertArrayHasKey('redis', $r['alerts']);
        $this->assertStringContainsString('Redis', $r['alerts']['redis']);
    }

    public function test_tls_expiry_alerts_under_fourteen_days(): void
    {
        config()->set('app.url', 'https://ornek.test');
        $this->assertArrayNotHasKey('tls', $this->watch()['alerts'], 'Test ortamında dışa bağlanmaz');

        config()->set('watchdog.tls_expires_at', now()->addDays(5)->toDateTimeString());
        $this->assertStringContainsString('5 gün sonra', $this->watch()['alerts']['tls']);

        config()->set('watchdog.tls_expires_at', now()->subDay()->toDateTimeString());
        $this->assertStringContainsString('süresi dolmuş', $this->watch()['alerts']['tls']);

        config()->set('watchdog.tls_expires_at', now()->addDays(60)->toDateTimeString());
        $this->assertArrayNotHasKey('tls', $this->watch()['alerts']);
    }

    public function test_stuck_relocation_alerts(): void
    {
        Settings::set('scraper_relocate_force_until', now()->addDay()->toDateTimeString());
        Settings::set('scraper_relocate_force_progress', json_encode(['done' => 10, 'changed' => 1, 'at' => now()->subMinutes(45)->toDateTimeString(), 'cursor' => 5, 'finished' => false]));
        $this->assertStringContainsString('ilerlemiyor', $this->watch()['alerts']['relocate']);

        Settings::set('scraper_relocate_force_progress', json_encode(['at' => now()->subMinutes(3)->toDateTimeString()]));
        $this->assertArrayNotHasKey('relocate', $this->watch()['alerts']);
    }

    public function test_watchdog_command_is_scheduled_every_five_minutes_with_short_lock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'system:watchdog'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);

        $this->artisan('system:watchdog')->assertSuccessful()->expectsOutputToContain('Her şey yolunda.');
    }

    public function test_up_endpoint_reflects_database_cache_and_scheduler_health(): void
    {
        $this->get('/up')->assertOk();

        Cache::put('scheduler.heartbeat', now()->timestamp, now()->addDay());
        $this->get('/up')->assertOk();

        Cache::put('scheduler.heartbeat', now()->subMinutes(10)->timestamp, now()->addDay());
        $this->get('/up')->assertStatus(500);
    }

    public function test_telegram_send_to_requires_token(): void
    {
        $this->expectException(\RuntimeException::class);
        app(TelegramPublisher::class)->sendTo('1', 'x');
    }
}
