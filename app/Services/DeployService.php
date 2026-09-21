<?php

namespace App\Services;

use App\Models\ActivityLog;
use Symfony\Component\Process\Process;

/**
 * Panelden "Siteyi güncelle": sunucudaki güncelleme betiğini (/root/update.sh sarmalayıcısı) arka planda başlatır,
 * çıktısını storage/logs/update.log'a yazar. Aynı anda ikinci güncelleme başlatılmaz (kilit dosyası).
 * Sunucu tarafı tek seferlik kurulum: deploy/install-update-button.sh (sarmalayıcı + sudoers izni).
 */
class DeployService
{
    public static function logPath(): string
    {
        return storage_path('logs/update.log');
    }

    public static function lockPath(): string
    {
        return storage_path('app/update.lock');
    }

    public static function command(): string
    {
        return (string) config('services.deploy.command', 'sudo -n /usr/local/bin/navluniq-update');
    }

    /** Çalışıyor mu? (kilit dosyası 30 dakikadan yeni ise) */
    public static function isRunning(): bool
    {
        $lock = self::lockPath();

        return is_file($lock) && filemtime($lock) > time() - 1800;
    }

    /** @return array{running: bool, started_at: ?string, finished_at: ?string, ok: ?bool, log: string} */
    public static function status(): array
    {
        $log = is_file(self::logPath()) ? (string) file_get_contents(self::logPath()) : '';
        $lines = array_values(array_filter(explode("\n", $log), fn ($l) => trim($l) !== ''));
        $tail = implode("\n", array_slice($lines, -40));
        $running = self::isRunning();
        $ok = null;
        if (! $running && $log !== '') {
            $ok = str_contains($log, '[navluniq-update] TAMAM');
        }

        return [
            'running' => $running,
            'started_at' => is_file(self::lockPath()) ? date('d.m.Y H:i', filemtime(self::lockPath())) : null,
            'finished_at' => ! $running && is_file(self::logPath()) ? date('d.m.Y H:i', filemtime(self::logPath())) : null,
            'ok' => $ok,
            'log' => $tail,
        ];
    }

    /** Güncellemeyi arka planda başlatır; zaten çalışıyorsa false döner. */
    public function start(?int $userId = null): bool
    {
        if (self::isRunning()) {
            return false;
        }
        @mkdir(dirname(self::lockPath()), 0750, true);
        file_put_contents(self::lockPath(), (string) time());
        file_put_contents(self::logPath(), '[navluniq-update] '.now()->format('d.m.Y H:i:s')." başlatıldı\n");
        ActivityLog::record('system.update_started', 'Site güncellemesi panelden başlatıldı', $userId);

        $lock = escapeshellarg(self::lockPath());
        $log = escapeshellarg(self::logPath());
        // Komut bitince kilit kaldırılır; çıkış kodu günlüğe yazılır. nohup + & ile istek bitse de sürer.
        $shell = '('.self::command().'; code=$?; if [ "$code" = "0" ]; then echo "[navluniq-update] TAMAM"; else echo "[navluniq-update] HATA (kod $code)"; fi; rm -f '.$lock.') >> '.$log.' 2>&1 &';
        $process = Process::fromShellCommandline('nohup bash -c '.escapeshellarg($shell).' > /dev/null 2>&1 &', base_path());
        $process->setTimeout(10);
        $process->run();

        return true;
    }
}
