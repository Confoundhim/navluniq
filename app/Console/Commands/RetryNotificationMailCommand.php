<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/** Gönderilemeyen bildirim e-postalarını yeniden dener (her 10 dakikada bir zamanlanır). */
class RetryNotificationMailCommand extends Command
{
    protected $signature = 'notifications:retry-mail {--limit=50 : Bir çalıştırmada en fazla kaç e-posta denensin}';

    protected $description = 'Başarısız bildirim e-postalarını yeniden gönderir (en fazla 4 deneme)';

    public function handle(NotificationService $notifications): int
    {
        $sent = $notifications->retryFailedMail((int) $this->option('limit'));
        $this->info("Yeniden gönderilen e-posta: {$sent}");

        return self::SUCCESS;
    }
}
