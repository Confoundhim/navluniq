<?php

namespace App\Console\Commands;

use App\Services\SystemWatchdog;
use Illuminate\Console\Command;

/**
 * Sistem bekçisi (I1): 5 dakikada bir çalışır (routes/console.php). Sorunları Telegram'a ve yöneticilere iletir;
 * ayrıntı App\Services\SystemWatchdog. Çıktı schedule.log'da görünür.
 */
class SystemWatchdogCommand extends Command
{
    protected $signature = 'system:watchdog';

    protected $description = 'Kuyruk, zamanlayıcı, yedek, disk, telefon, e-posta, Redis ve SSL denetimi; sorun varsa Telegram ve panel bildirimi';

    public function handle(SystemWatchdog $watchdog): int
    {
        $r = $watchdog->run();
        foreach ($r['checks'] as $key => $check) {
            $this->line(sprintf('  %s %-16s %s', $check['ok'] ? '✔' : '✘', SystemWatchdog::LABELS[$key] ?? $key, $check['ok'] ? 'tamam' : $check['note']));
        }
        if ($r['sent'] !== []) {
            $this->warn('Gönderilen uyarı: '.implode(', ', $r['sent']));
        }
        if ($r['recovered'] !== []) {
            $this->info('Düzeldi: '.implode(', ', $r['recovered']));
        }
        $this->info($r['alerts'] === [] ? 'Her şey yolunda.' : count($r['alerts']).' sorun var.');

        return self::SUCCESS;
    }
}
