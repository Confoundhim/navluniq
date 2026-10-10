<?php

namespace App\Jobs;

use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bildirim e-postasını kuyrukta gönderir. İlan yayınında onlarca premium şoföre e-posta giderken yük sahibinin isteği SMTP'yi
 * beklemesin diye (2026-10-06): bildirim MAIL_PENDING yazılır, bu iş gönderir; iş düşerse notifications:retry-mail yeniden dener.
 */
class SendNotificationMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $notificationId) {}

    public function handle(NotificationService $notifications): void
    {
        $notification = UserNotification::query()->with('user')->find($this->notificationId);
        if (! $notification || $notification->mail_status !== UserNotification::MAIL_PENDING) {
            return;
        }
        $notifications->sendMail($notification);
    }

    /** İş tüm denemelerde düştü (zaman aşımı, işçi hatası): kayıt "bekliyor"da kalmaz, "başarısız" olur ve retry-mail yeniden dener. */
    public function failed(?\Throwable $e = null): void
    {
        UserNotification::query()->whereKey($this->notificationId)->where('mail_status', UserNotification::MAIL_PENDING)->update([
            'mail_status' => UserNotification::MAIL_FAILED,
            'mail_error' => mb_substr('Kuyruk işi düştü: '.($e?->getMessage() ?? 'bilinmeyen hata'), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
