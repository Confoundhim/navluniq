<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Panelden gönderilen duyuru (hizmet bildirimi): alıcılar parça parça kuyruğa bırakılır, her alıcıya uygulama içi
 * bildirim + e-posta gider. Gönderim web isteğinin içinde değil işçide çalışır (denetim Y12).
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
    public function __construct(public readonly array $userIds, public readonly string $subject, public readonly array $lines) {}

    public function handle(NotificationService $notifications): void
    {
        // Kuyrukta beklerken engellenen / pasife alınan kullanıcı almaz.
        User::query()->whereIn('id', $this->userIds)->where('is_active', true)->whereNull('banned_at')
            ->get(['id', 'email', 'first_name', 'last_name'])
            ->each(fn (User $user) => $notifications->notify($user, $this->subject, $this->lines, null, null, 'general'));
    }
}
