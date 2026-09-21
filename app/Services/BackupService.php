<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Tam sistem yedeği: veritabanı dökümü + .env + yüklenen dosyalar (KYC belgeleri, faturalar, herkese açık dosyalar)
 * tek bir zip'te. Yedekler storage/app/backups altında (web'den erişilemez) tutulur; panelden indirilir, sunucudan
 * scp ile bilgisayara alınır. Eski yedekler sayı sınırına göre silinir.
 */
class BackupService
{
    public const DEFAULT_KEEP = 14;

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    /** Zip'e giren dizinler: proje köküne göre yol => zip içindeki ad. */
    public static function fileTargets(): array
    {
        return [
            storage_path('app/kyc') => 'storage/kyc',
            storage_path('app/private') => 'storage/private',
            storage_path('app/public') => 'storage/public',
            public_path('uploads') => 'public/uploads',
        ];
    }

    public function create(string $type = 'full', ?int $userId = null): Backup
    {
        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Yedek dizini oluşturulamadı: {$dir}");
        }
        $filename = 'navluniq-'.now()->format('Y-m-d_Hi').($type === 'database' ? '-db' : '').'.zip';
        $path = $dir.'/'.$filename;
        $backup = Backup::create(['filename' => $filename, 'backup_type' => $type, 'storage_disk' => 'local', 'storage_path' => 'backups/'.$filename, 'status' => 'running']);
        $work = $dir.'/.work-'.$backup->id;

        try {
            @mkdir($work, 0750, true);
            $sql = $work.'/database.sql';
            $this->dumpDatabase($sql);

            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Zip dosyası açılamadı.');
            }
            $zip->addFile($sql, 'database.sql');
            $zip->addFromString('BENIOKU.txt', $this->readme());
            if ($type === 'full') {
                if (is_file(base_path('.env'))) {
                    $zip->addFile(base_path('.env'), 'env.txt');
                }
                foreach (self::fileTargets() as $source => $name) {
                    $this->addDirectory($zip, $source, $name);
                }
            }
            $zip->close();

            $size = (int) filesize($path);
            $backup->update([
                'size_bytes' => $size, 'size_mb' => round($size / 1048576, 2), 'sha256' => hash_file('sha256', $path),
                'status' => 'completed', 'completed_at' => now(),
            ]);
            ActivityLog::record('backup.created', "Yedek alındı: {$filename} (".number_format($size / 1048576, 1, ',', '.').' MB)', $userId);
        } catch (Throwable $e) {
            @unlink($path);
            $backup->update(['status' => 'failed', 'failure_message' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::error('Yedek alınamadı.', ['error' => $e->getMessage()]);
        } finally {
            $this->removeDirectory($work);
        }

        return $backup->fresh();
    }

    /** Sayı sınırını aşan en eski tamamlanmış yedekleri siler; silinen sayısını döndürür. */
    public function prune(int $keep = self::DEFAULT_KEEP): int
    {
        $removed = 0;
        Backup::query()->where('status', 'completed')->orderByDesc('id')->skip(max(1, $keep))->take(1000)->get()
            ->each(function (Backup $backup) use (&$removed): void {
                $this->delete($backup);
                $removed++;
            });
        Backup::query()->where('status', 'failed')->where('created_at', '<', now()->subDays(30))->delete();

        return $removed;
    }

    public function delete(Backup $backup, ?int $userId = null): void
    {
        $file = self::path($backup);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
        $backup->delete();
        if ($userId !== null) {
            ActivityLog::record('backup.deleted', "Yedek silindi: {$backup->filename}", $userId);
        }
    }

    public static function path(Backup $backup): ?string
    {
        $name = basename((string) $backup->filename);
        if ($name === '' || ! preg_match('/^navluniq-[\w\-]+\.zip$/', $name)) {
            return null;
        }

        return self::directory().'/'.$name;
    }

    /** MySQL/MariaDB için mysqldump; diğer sürücülerde (testler: sqlite) tabloları JSON satırları olarak yazar. */
    private function dumpDatabase(string $target): void
    {
        $conn = config('database.default');
        $cfg = (array) config("database.connections.{$conn}");
        if (($cfg['driver'] ?? '') === 'mysql' || ($cfg['driver'] ?? '') === 'mariadb') {
            $bin = $this->findBinary(['mysqldump', 'mariadb-dump']);
            $defaults = tempnam(sys_get_temp_dir(), 'mydump');
            file_put_contents($defaults, "[client]\nuser=".($cfg['username'] ?? '')."\npassword=".($cfg['password'] ?? '')."\nhost=".($cfg['host'] ?? '127.0.0.1')."\nport=".($cfg['port'] ?? 3306)."\n");
            chmod($defaults, 0600);
            try {
                $process = new Process([$bin, '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4', '--result-file='.$target, (string) ($cfg['database'] ?? '')]);
                $process->setTimeout(900)->run();
                if (! $process->isSuccessful() || ! is_file($target) || filesize($target) < 100) {
                    throw new RuntimeException('Veritabanı dökümü başarısız: '.trim($process->getErrorOutput() ?: $process->getOutput()));
                }
            } finally {
                @unlink($defaults);
            }

            return;
        }

        $out = fopen($target, 'w');
        fwrite($out, '-- '.$conn." veritabanı; tablolar JSON satırları olarak (yalnız geliştirme/test ortamı)\n");
        $tables = $conn === 'sqlite'
            ? array_map(fn ($r) => $r->name, DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            : [];
        foreach ($tables as $table) {
            fwrite($out, "-- TABLE {$table}\n");
            foreach (DB::table($table)->cursor() as $row) {
                fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
            }
        }
        fclose($out);
    }

    private function findBinary(array $candidates): string
    {
        foreach ($candidates as $bin) {
            $p = new Process(['which', $bin]);
            $p->run();
            if ($p->isSuccessful() && trim($p->getOutput()) !== '') {
                return trim($p->getOutput());
            }
        }
        throw new RuntimeException('mysqldump bulunamadı; sunucuda "apt install mariadb-client" gerekir.');
    }

    private function addDirectory(ZipArchive $zip, string $source, string $name): void
    {
        if (! is_dir($source)) {
            return;
        }
        $zip->addEmptyDir($name);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $rel = ltrim(str_replace('\\', '/', substr((string) $file, strlen($source))), '/');
            if ($rel === '' || str_starts_with($rel, 'livewire-tmp') || str_starts_with($rel, 'backups')) {
                continue;
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($name.'/'.$rel);
            } elseif ($file->isFile()) {
                $zip->addFile((string) $file, $name.'/'.$rel);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir.'/'.$entry;
            is_dir($p) ? $this->removeDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function readme(): string
    {
        return 'NavlunIQ tam yedek ('.now()->format('d.m.Y H:i').")\n\n".
            "database.sql      : MariaDB/MySQL dökümü (tüm tablolar, ayarlar, kaynaklar, ilanlar, kullanıcılar)\n".
            "env.txt           : .env dosyası (anahtarlar; gizli tutun)\n".
            "storage/kyc       : sürücü belgeleri\nstorage/private   : faturalar ve özel dosyalar\nstorage/public    : herkese açık dosyalar\n\n".
            "Geri yükleme (yeni sunucuda):\n".
            "1. Uygulamayı kurun, env.txt'yi .env olarak kopyalayın.\n".
            "2. mysql -u KULLANICI -p VERITABANI < database.sql\n".
            "3. storage/* klasörlerini storage/app/ altına aynı adlarla kopyalayın; chown -R www-data:www-data storage\n".
            "4. php artisan migrate --force && php artisan storage:link && php artisan optimize:clear\n";
    }
}
