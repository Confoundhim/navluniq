<?php

namespace App\Services;

use App\Mail\SystemNoticeMail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tek bildirim kapısı. Her bildirim önce uygulama içi kayda (zil + Bildirimler sayfası) yazılır,
 * ardından e-posta ile gönderilir. E-posta hatası iş akışını durdurmaz; kayıt "failed" kalır ve
 * notifications:retry-mail görevi yeniden dener. Yönetici bildirimleri izne göre dağıtılır.
 */
class NotificationService
{
    /**
     * Kullanıcıya bildirim: kayıt + e-posta.
     *
     * @param  list<string>  $lines
     */
    public function notify(User $user, string $title, array $lines, ?string $actionUrl = null, ?string $actionText = null, string $type = 'general', bool $sendMail = true): UserNotification
    {
        $notification = UserNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => mb_substr($title, 0, 160),
            'lines' => array_values(array_map(fn ($l) => (string) $l, $lines)),
            'action_url' => $actionUrl ? mb_substr($actionUrl, 0, 500) : null,
            'action_text' => $actionText ? mb_substr($actionText, 0, 60) : null,
            'mail_status' => $sendMail ? UserNotification::MAIL_PENDING : UserNotification::MAIL_SKIPPED,
        ]);

        if ($sendMail) {
            $this->sendMail($notification->setRelation('user', $user));
        }

        return $notification;
    }

    /**
     * İzne sahip tüm aktif personele bildirim (super_admin her izne sahiptir).
     * Döndürülen değer bilgilendirilen personel sayısıdır.
     */
    public function notifyAdmins(string $permission, string $title, array $lines, ?string $actionUrl = null, ?string $actionText = null, string $type = 'admin'): int
    {
        $admins = User::permission($permission)->where('is_active', true)->whereNull('banned_at')->get()
            ->filter(fn (User $u) => $u->hasAnyRole(User::ADMIN_PANEL_ROLES));

        foreach ($admins as $admin) {
            $this->notify($admin, $title, $lines, $actionUrl, $actionText, $type);
        }

        return $admins->count();
    }

    /** Bildirime bağlı e-postayı gönderir; sonucu kayda işler. */
    public function sendMail(UserNotification $notification): bool
    {
        $user = $notification->user;
        // İnceleme (test) hesaplarının e-posta adresi gerçek değildir; e-posta gönderilmez.
        if (! $user || ! $user->canReceiveMail() || OtpService::isReviewAccount($user)) {
            $notification->forceFill(['mail_status' => UserNotification::MAIL_SKIPPED])->save();

            return false;
        }

        try {
            Mail::to($user->email, $user->full_name)->send(new SystemNoticeMail(
                $notification->title, $notification->lines, $notification->action_url, $notification->action_text, $user->first_name
            ));
            $notification->forceFill([
                'mail_status' => UserNotification::MAIL_SENT,
                'mail_attempts' => $notification->mail_attempts + 1,
                'mail_sent_at' => now(),
                'mail_error' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $notification->forceFill([
                'mail_status' => UserNotification::MAIL_FAILED,
                'mail_attempts' => $notification->mail_attempts + 1,
                'mail_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();
            Log::warning('Bildirim e-postası gönderilemedi.', ['notification_id' => $notification->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Başarısız e-postaları yeniden dener (zamanlanmış görev). Başarıyla gönderilen sayısını döner. */
    public function retryFailedMail(int $limit = 50): int
    {
        $sent = 0;
        UserNotification::query()->with('user')->mailRetryable()
            ->where('updated_at', '<', now()->subMinutes(5))->orderBy('id')->limit($limit)->get()
            ->each(function (UserNotification $n) use (&$sent): void {
                $sent += $this->sendMail($n) ? 1 : 0;
            });

        return $sent;
    }

    /** Yönetici panelinden SMTP doğrulaması: adrese örnek bildirim gönderir, hata metnini döner. */
    public function sendTest(string $email): ?string
    {
        try {
            Mail::to($email)->send(new SystemNoticeMail(
                'E-posta ayarları doğrulandı',
                ['Bu ileti NavlunIQ yönetim panelinden gönderilen bir deneme e-postasıdır.', 'Bu iletiyi görüyorsanız SMTP ayarları, gönderici adresi ve şablon çalışıyor demektir.'],
                rtrim((string) config('app.url'), '/').'/adminsystem/settings', 'Sistem ayarlarına dön'
            ));

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Son 24 saat gönderim özeti (yönetici sekmesi ve sağlık kontrolü). */
    public static function mailStats(): array
    {
        $since = now()->subDay();
        $base = UserNotification::query()->where('created_at', '>=', $since);

        return [
            'sent' => (clone $base)->where('mail_status', UserNotification::MAIL_SENT)->count(),
            'failed' => UserNotification::query()->where('mail_status', UserNotification::MAIL_FAILED)->count(),
            'pending' => UserNotification::query()->where('mail_status', UserNotification::MAIL_PENDING)->count(),
            'skipped' => (clone $base)->where('mail_status', UserNotification::MAIL_SKIPPED)->count(),
        ];
    }
}
