<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class SystemBackupCommand extends Command
{
    protected $signature = 'system:backup {--type=full : full (veritabanı + dosyalar) ya da database} {--keep='.BackupService::DEFAULT_KEEP.' : Bu kadar yedek saklanır, eskiler silinir}';

    protected $description = 'Veritabanı ve yüklenen dosyaları tek zip olarak storage/app/backups altına yedekler';

    public function handle(BackupService $backups): int
    {
        $type = $this->option('type') === 'database' ? 'database' : 'full';
        $backup = $backups->create($type);
        if ($backup->status !== 'completed') {
            $this->error('Yedek alınamadı: '.$backup->failure_message);

            return self::FAILURE;
        }
        $removed = $backups->prune(max(1, (int) $this->option('keep')));
        $this->info(sprintf('Yedek hazır: %s (%s MB)%s', BackupService::path($backup), number_format((float) $backup->size_mb, 2, ',', '.'), $removed > 0 ? " · {$removed} eski yedek silindi" : ''));

        return self::SUCCESS;
    }
}
