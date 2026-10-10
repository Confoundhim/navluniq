<?php

namespace Tests\Feature\Ops;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** I9: zamanlayıcı kilitleri süresiz değil (sert kapanma sonrası dakikalık hat 24 saat durmasın); çıktı günlüğe akar. */
class ScheduleLocksTest extends TestCase
{
    /** @return array<string, Event> komut adı => olay */
    private function events(): array
    {
        $out = [];
        foreach (app(Schedule::class)->events() as $event) {
            $name = $event instanceof CallbackEvent ? (string) $event->description : trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command));
            $out[$name] = $event;
        }

        return $out;
    }

    public function test_every_overlap_lock_has_an_expiry_shorter_than_a_day(): void
    {
        $events = $this->events();
        $this->assertNotEmpty($events);
        foreach ($events as $name => $event) {
            if ($event->withoutOverlapping) {
                $this->assertLessThanOrEqual(180, $event->expiresAt, "{$name} kilidi en çok 180 dk olmalı");
            }
        }
    }

    public function test_lock_expiries_match_run_frequency(): void
    {
        $events = $this->events();
        $expected = [
            'scraped-loads:auto-approve' => 10,
            'loads:release-to-free' => 10,
            'scraped-loads:ai-enrich' => 10,
            'scraped-loads:relocate-force' => 10,
            'trips:scan-return-loads' => 60,
            'notifications:retry-mail' => 60,
            'loads:expire-unpaid' => 60,
            'loads:expire' => 180,
            'system:backup' => 180,
            'scraped-loads:ai-audit' => 120,
            'subscriptions:expire' => 10,
            'subscriptions:remind' => 10,
            'subscriptions:reconcile' => 10,
            'subscriptions:renew' => 60,
        ];
        foreach ($expected as $name => $minutes) {
            $this->assertArrayHasKey($name, $events, "{$name} zamanlanmış olmalı");
            $this->assertTrue($events[$name]->withoutOverlapping, "{$name} kilitli olmalı");
            $this->assertSame($minutes, $events[$name]->expiresAt, "{$name} kilidi {$minutes} dk olmalı");
        }
    }

    public function test_cron_line_writes_schedule_output_to_log(): void
    {
        foreach (['deploy/install.sh', 'deploy/update.sh'] as $file) {
            $script = file_get_contents(base_path($file));
            $this->assertStringContainsString('schedule:run >> ${APP_DIR}/storage/logs/schedule.log 2>&1', $script, $file);
            $this->assertStringNotContainsString('schedule:run >> /dev/null', $script, $file);
        }
    }

    public function test_schedule_log_is_trimmed_weekly_to_its_tail(): void
    {
        $events = $this->events();
        $this->assertArrayHasKey('schedule-log-trim', $events);
        $event = $events['schedule-log-trim'];
        $this->assertSame('50 4 * * 1', $event->expression);

        $file = storage_path('logs/schedule.log');
        $backup = is_file($file) ? file_get_contents($file) : null;
        try {
            file_put_contents($file, str_repeat("eski satır\n", 100_000).str_repeat("yeni satır\n", 100_000)); // ~2,2 MB
            $event->run($this->app);
            $after = file_get_contents($file);
            $this->assertLessThanOrEqual(2 * 1024 * 1024 + 100, strlen($after));
            $this->assertStringStartsWith('[kırpıldı ', $after);
            $this->assertStringEndsWith("yeni satır\n", $after);

            // Küçük dosyaya dokunulmaz.
            file_put_contents($file, "kısa\n");
            $event->run($this->app);
            $this->assertSame("kısa\n", file_get_contents($file));
        } finally {
            $backup === null ? @unlink($file) : file_put_contents($file, $backup);
        }
    }
}
