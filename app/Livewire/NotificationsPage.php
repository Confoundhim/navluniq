<?php

namespace App\Livewire;

use App\Models\UserNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Bildirimler sayfasının ortak mantığı. Her panel (şoför, yük sahibi, yönetici) kendi
 * yerleşimiyle bu sınıfı genişleten bir Volt bileşeni kullanır.
 */
abstract class NotificationsPage extends Component
{
    use WithPagination;

    public string $filter = 'all'; // all | unread

    public function markRead(int $id): void
    {
        Auth::user()?->userNotifications()->whereKey($id)->first()?->markRead();
    }

    public function open(int $id): void
    {
        $n = Auth::user()?->userNotifications()->whereKey($id)->first();
        if (! $n) {
            return;
        }
        $n->markRead();
        if ($n->action_url) {
            $this->redirect($n->action_url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Auth::user()?->userNotifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Auth::user()->userNotifications();
        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        }

        return [
            'notifications' => $query->paginate(20),
            'unreadCount' => Auth::user()->unreadNotificationCount(),
            'typeLabels' => UserNotification::TYPE_LABELS,
        ];
    }
}
