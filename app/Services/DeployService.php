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

    /** Renk kodlarını (ANSI) temizler; günlük panelde düz metin görünür. */
    public static function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
    }

    /**
     * @return array{running: bool, started_at: ?string, finished_at: ?string, ok: ?bool, interrupted: bool, error: ?string, log: string}
     *                                                                                                                                    ok: true = "TAMAM" satırı var; false = "HATA" satırı var; null = hiç çalışmadı ya da süreç sonuç yazamadan kesildi (interrupted).
     */
    public static function status(): array
    {
        $log = is_file(self::logPath()) ? self::stripAnsi((string) file_get_contents(self::logPath())) : '';
        $lines = array_values(array_filter(explode("\n", $log), fn ($l) => trim($l) !== ''));
        $tail = implode("\n", array_slice($lines, -60));
        $running = self::isRunning();
        $ok = null;
        $error = null;
        $interrupted = false;
        if (! $running && $log !== '') {
            if (str_contains($log, '[navluniq-update] TAMAM')) {
                $ok = true;
            } elseif (str_contains($log, '[navluniq-update] HATA')) {
                $ok = false;
                // Hata satırından önceki son anlamlı satırlar: nedenini kutuyu kaydırmadan gösterir.
                $idx = null;
                foreach ($lines as $i => $line) {
                    if (str_contains($line, '[navluniq-update] HATA')) {
                        $idx = $i;
                    }
                }
                if ($idx !== null) {
                    $error = implode("\n", array_slice($lines, max(0, $idx - 4), 5));
                }
            } else {
                $interrupted = true;
            }
        }

        return [
            'running' => $running,
            'started_at' => is_file(self::lockPath()) ? date('d.m.Y H:i', filemtime(self::lockPath())) : null,
            'finished_at' => ! $running && is_file(self::logPath()) ? date('d.m.Y H:i', filemtime(self::logPath())) : null,
            'ok' => $ok,
            'interrupted' => $interrupted,
            'error' => $error,
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
        // Sunucudaki sarmalayıcı sonucu kendisi de yazar (süreç kesilse bile); burada yalnız yazılmamışsa eklenir.
        $shell = '('.self::command().'; code=$?; if ! grep -q "\\[navluniq-update\\] \\(TAMAM\\|HATA\\)" '.$log.'; then if [ "$code" = "0" ]; then echo "[navluniq-update] TAMAM"; else echo "[navluniq-update] HATA (kod $code)"; fi; fi; rm -f '.$lock.') >> '.$log.' 2>&1 &';
        $process = Process::fromShellCommandline('setsid nohup bash -c '.escapeshellarg($shell).' > /dev/null 2>&1 < /dev/null &', base_path());
        $process->setTimeout(10);
        $process->run();

        return true;
    }
}
