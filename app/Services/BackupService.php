<?php

namespace App\Services;

use App\Jobs\CreateBackupJob;
use App\Jobs\QueueHeartbeat;
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
    /** Tek yedek yolu (I5): her gece tam yedek, son 7 tutulur; 6 saatte bir veritabanı dökümü, son 12 tutulur (3 gün). */
    public const DEFAULT_KEEP = 7;

    public const DEFAULT_KEEP_DATABASE = 12;

    /** Türüne göre varsayılan saklama sayısı. */
    public static function defaultKeep(string $type): int
    {
        return $type === 'database' ? self::DEFAULT_KEEP_DATABASE : self::DEFAULT_KEEP;
    }

    public static function directory(): string
    {
        // Testler yazılamayan bir dizin vererek hata yolunu sınar; canlıda storage/app/backups.
        return (string) (config('backup.directory') ?: storage_path('app/backups'));
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

    /** "Alınıyor" durumunda kayıt açar; dosya henüz yoktur. create() bu kaydı doldurur. */
    public function start(string $type = 'full'): Backup
    {
        $type = $type === 'database' ? 'database' : 'full';
        $filename = 'navluniq-'.now()->format('Y-m-d_His').($type === 'database' ? '-db' : '').'.zip';

        return Backup::create(['filename' => $filename, 'backup_type' => $type, 'storage_disk' => 'local', 'storage_path' => 'backups/'.$filename, 'status' => 'running']);
    }

    /**
     * Panelden "yedek al": kuyruk işçisi canlıysa iş kuyruğa bırakılır ve kayıt "Alınıyor" olarak hemen döner (Livewire isteği
     * dakikalarca sürmez, zaman aşımı ve çift kayıt olmaz); işçi yoksa/eskiyse eski gibi istek içinde alınır (telefon mesajlarıyla aynı düzen).
     *
     * @return array{backup: Backup, queued: bool}
     */
    public function request(string $type = 'full', ?int $userId = null): array
    {
        $backup = $this->start($type);
        if (QueueHeartbeat::alive()) {
            CreateBackupJob::dispatch($backup->id, $userId);

            return ['backup' => $backup, 'queued' => true];
        }
        set_time_limit(0);
        $backup = $this->create($backup->backup_type, $userId, $backup);
        if ($backup->status === 'completed') {
            $this->prune();
        }

        return ['backup' => $backup, 'queued' => false];
    }

    public function create(string $type = 'full', ?int $userId = null, ?Backup $backup = null): Backup
    {
        $dir = self::directory();
        $backup ??= $this->start($type);
        $type = $backup->backup_type;
        $filename = $backup->filename;
        $path = $dir.'/'.$filename;
        $work = $dir.'/.work-'.$backup->id;

        try {
            // Dizin hatası da "başarısız yedek" olarak kaydedilir ve bildirilir (sessizce patlamaz).
            if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new RuntimeException("Yedek dizini oluşturulamadı: {$dir}");
            }
            if (! is_writable($dir)) {
                throw new RuntimeException("Yedek dizini yazılabilir değil: {$dir}");
            }
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
                // Uygulama kodu: tek zip GitHub olmadan siteyi ayağa kaldırmaya yetsin (deploy/geri-yukle.sh).
                foreach (self::codeFiles() as $rel) {
                    $zip->addFile(base_path($rel), 'kod/'.$rel);
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
            $this->notifyFailure($backup, $e);
        } finally {
            $this->removeDirectory($work);
        }

        return $backup->fresh();
    }

    /** Başarısız yedek yöneticilere bildirilir (I5: hata sessiz kalmaz; bekçi de son yedeğe bakar). */
    private function notifyFailure(Backup $backup, Throwable $e): void
    {
        try {
            $url = null;
            try {
                $url = route('admin.backups');
            } catch (Throwable) {
                // rota yoksa bağlantısız bildirim
            }
            app(NotificationService::class)->notifyAdmins('manage system', 'Yedek alınamadı', [
                ($backup->backup_type === 'database' ? 'Veritabanı yedeği' : 'Tam yedek').' alınamadı: '.mb_substr($e->getMessage(), 0, 300),
                'Sunucu diskini ve storage/logs/laravel.log dosyasını kontrol edin; yedek olmadan taşınma ve geri yükleme yapılamaz.',
            ], $url, $url ? 'Yedekleri aç' : null, 'admin');
        } catch (Throwable $notifyError) {
            Log::warning('Yedek hatası bildirilemedi.', ['error' => $notifyError->getMessage()]);
        }
    }

    /**
     * Sayı sınırını aşan en eski tamamlanmış yedekleri siler; silinen sayısını döndürür.
     * Tür verilirse yalnız o türde sayar (6 saatlik veritabanı dökümleri gece tam yedeklerini sıradan düşürmez);
     * verilmezse her tür kendi varsayılan sınırıyla ayrı ayrı budanır.
     */
    public function prune(?int $keep = null, ?string $type = null): int
    {
        if ($type === null) {
            return $this->prune($keep ?? self::DEFAULT_KEEP, 'full') + $this->prune($keep ?? self::DEFAULT_KEEP_DATABASE, 'database');
        }
        $removed = 0;
        Backup::query()->where('status', 'completed')->where('backup_type', $type)->orderByDesc('id')->skip(max(1, $keep ?? self::defaultKeep($type)))->take(1000)->get()
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

    /**
     * Zip'e giren kod dosyaları (proje köküne göre): depodaki dosyalar + derlenmiş ön yüz (public/build).
     * vendor ve node_modules girmez; geri yüklemede composer/npm ile yeniden kurulur. .env ayrıca env.txt olarak eklenir.
     *
     * @return list<string>
     */
    public static function codeFiles(): array
    {
        $files = [];
        $git = new Process(['git', 'ls-files', '-z'], base_path());
        $git->run();
        if ($git->isSuccessful() && trim($git->getOutput()) !== '') {
            $files = array_values(array_filter(explode("\0", $git->getOutput()), fn ($f) => $f !== '' && is_file(base_path($f))));
        } else {
            // git yoksa: bilinen klasörler ve kök dosyalar, üretilen/gizli olanlar hariç.
            $skip = ['vendor', 'node_modules', 'storage', '.git', 'public/build', 'public/storage', 'public/uploads'];
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $f) use ($skip): bool {
                    $rel = ltrim(str_replace('\\', '/', substr((string) $f, strlen(base_path()))), '/');

                    return ! in_array($rel, $skip, true) && ! str_starts_with($rel, '.env');
                }
            ));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[] = ltrim(str_replace('\\', '/', substr((string) $f, strlen(base_path()))), '/');
                }
            }
        }
        $build = public_path('build');
        if (is_dir($build)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($build, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[] = 'public/build/'.ltrim(str_replace('\\', '/', substr((string) $f, strlen($build))), '/');
                }
            }
        }
        $files = array_values(array_unique(array_filter($files, fn ($f) => ! str_starts_with($f, '.env') && ! str_starts_with($f, 'vendor/') && ! str_starts_with($f, 'node_modules/'))));
        sort($files);

        return $files;
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
        $git = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
        $git->run();
        $version = $git->isSuccessful() ? trim($git->getOutput()) : 'bilinmiyor';

        return 'NavlunIQ tam yedek ('.now()->format('d.m.Y H:i').", kod sürümü {$version})\n\n".
            "Bu tek dosya siteyi yeni bir sunucuda ayağa kaldırmaya yeter; GitHub gerekmez.\n\n".
            "kod/              : uygulama kodu ve derlenmiş ön yüz (vendor/node_modules composer ve npm ile kurulur)\n".
            "database.sql      : MySQL dökümü (tüm tablolar, ayarlar, kaynaklar, ilanlar, kullanıcılar)\n".
            "env.txt           : .env dosyası (anahtarlar; gizli tutun — APP_KEY olmadan şifreli veriler okunamaz)\n".
            "storage/kyc       : sürücü belgeleri\nstorage/private   : faturalar ve özel dosyalar\nstorage/public    : herkese açık dosyalar\n\n".
            "Geri yükleme (boş Ubuntu 24.04 sunucusunda, root ile; 10-15 dk):\n".
            "  apt-get update && apt-get install -y unzip\n".
            "  unzip -o BU-DOSYA.zip -d /root/yedek\n".
            "  bash /root/yedek/kod/deploy/geri-yukle.sh --kontrol /root/BU-DOSYA.zip          (yalnız inceler)\n".
            "  LETSENCRYPT_EMAIL=siz@ornek.com bash /root/yedek/kod/deploy/geri-yukle.sh /root/BU-DOSYA.zip\n".
            "  (alan adı yerine IP ile açmak için: DOMAIN=SUNUCU_IP bash ... ; deneme kopyası için --deneme)\n".
            "Betik MySQL, Redis, PHP, nginx, SSL, zamanlayıcı ve kuyruk işçisini kurar; veritabanını, belgeleri ve\n".
            "anahtarları yerine koyar. Ayrıntı: kod/docs/SUNUCU_TASINMA.md\n";
    }
}
