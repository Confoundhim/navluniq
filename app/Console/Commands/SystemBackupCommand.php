<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Tek yedek yolu (I5): zamanlayıcı her gece 03:30'da tam yedek (son 7), 6 saatte bir veritabanı dökümü (son 12) alır.
 * Hata halinde yöneticilere bildirim gider (BackupService) ve komut başarısız döner (zamanlayıcı günlüğünde görünür).
 */
class SystemBackupCommand extends Command
{
    protected $signature = 'system:backup {--type=full : full (veritabanı + dosyalar + kod) ya da database (yalnız döküm)} {--keep= : Bu kadar yedek saklanır, eskiler silinir (boş: tam 7, veritabanı 12; tür bazında sayılır)}';

    protected $description = 'Veritabanını (ve tam yedekte dosyaları/kodu) tek zip olarak storage/app/backups altına yedekler';

    public function handle(BackupService $backups): int
    {
        $type = $this->option('type') === 'database' ? 'database' : 'full';
        $keep = $this->option('keep') !== null && $this->option('keep') !== '' ? max(1, (int) $this->option('keep')) : BackupService::defaultKeep($type);

        $backup = $backups->create($type);
        if ($backup->status !== 'completed') {
            $this->error('Yedek alınamadı: '.$backup->failure_message);

            return self::FAILURE;
        }
        $removed = $backups->prune($keep, $type);
        $this->info(sprintf('Yedek hazır: %s (%s MB)%s', BackupService::path($backup), number_format((float) $backup->size_mb, 2, ',', '.'), $removed > 0 ? " · {$removed} eski yedek silindi" : ''));

        return self::SUCCESS;
    }
}
