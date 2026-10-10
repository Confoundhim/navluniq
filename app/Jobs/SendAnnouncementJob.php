<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Panelden gönderilen duyuru (hizmet bildirimi): alıcılar parça parça kuyruğa bırakılır, her alıcıya uygulama içi
 * bildirim + e-posta gider. Gönderim web isteğinin içinde değil işçide çalışır (denetim Y12).
 * $type 'marketing' ise ticari ileti kuralları NotificationService'te işler (rızasıza e-posta yok, çıkış bağlantısı).
 * Yeniden denemede aynı başlıklı bildirimi son bir saatte almış kullanıcı atlanır (yarıda kesilen iş iki kez göndermez).
 */
class SendAnnouncementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $lines
     */
    public function __construct(public readonly array $userIds, public readonly string $subject, public readonly array $lines, public readonly string $type = 'general') {}

    public function handle(NotificationService $notifications): void
    {
        $title = mb_substr($this->subject, 0, 160);
        $alreadySent = UserNotification::query()->whereIn('user_id', $this->userIds)->where('title', $title)
            ->where('created_at', '>=', now()->subHour())->pluck('user_id')->flip();

        // Kuyrukta beklerken engellenen / pasife alınan kullanıcı almaz; yeniden denemede zaten almış olan atlanır.
        User::query()->whereIn('id', $this->userIds)->where('is_active', true)->whereNull('banned_at')
            ->get(['id', 'email', 'first_name', 'last_name'])
            ->reject(fn (User $user) => $alreadySent->has($user->id))
            ->each(fn (User $user) => $notifications->notify($user, $this->subject, $this->lines, null, null, $this->type));
    }
}
