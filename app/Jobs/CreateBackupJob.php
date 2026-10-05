<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Panelden istenen yedeği kuyrukta alır (denetim Y11): kayıt "running" olarak açılmıştır, iş bitince
 * completed/failed olur; Yedekleme ekranı "Alınıyor" satırı varken 5 sn'de bir yenilenir.
 */
class CreateBackupJob implements ShouldQueue
{
    use Queueable;

    /** Yedek yarıda kalırsa ikinci deneme yarım zip'in üstüne yazmasın; hata kayda ve yöneticilere zaten gider. */
    public int $tries = 1;

    /** Büyük veritabanı + belgeler: mysqldump 15 dk'ya kadar bekler. */
    public int $timeout = 1500;

    public function __construct(public readonly int $backupId, public readonly ?int $userId = null) {}

    public function handle(BackupService $backups): void
    {
        $backup = Backup::query()->find($this->backupId);
        if (! $backup || $backup->status !== 'running') {
            return; // silinmiş ya da zaten sonuçlanmış kayıt
        }
        $done = $backups->create($backup->backup_type, $this->userId, $backup);
        if ($done->status === 'completed') {
            $backups->prune();
        }
    }

    public function failed(\Throwable $e): void
    {
        Backup::query()->whereKey($this->backupId)->where('status', 'running')
            ->update(['status' => 'failed', 'failure_message' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
