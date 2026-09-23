<?php

use App\Models\UserNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/** Panel üst çubuğundaki bildirim zili: okunmamış sayısı, son bildirimler, okundu işaretleme. */
new class extends Component {
    public bool $open = false;

    /** Zil düşürüldüğünde ilgili sayfaya gidilecek adres (rol paneline göre). */
    public string $indexRoute = 'driver.notifications.index';

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()?->unreadNotificationCount() ?? 0;
    }

    #[Computed]
    public function recent()
    {
        return Auth::user()?->userNotifications()->limit(6)->get() ?? collect();
    }

    public function markAllRead(): void
    {
        Auth::user()?->userNotifications()->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->unreadCount, $this->recent);
    }

    /** Bildirimler sayfasında okundu/silme yapılınca zil beklemeden güncellenir. */
    #[On('notifications-changed')]
    public function refreshBell(): void
    {
        unset($this->unreadCount, $this->recent);
    }

    public function openNotification(int $id): void
    {
        $n = Auth::user()?->userNotifications()->whereKey($id)->first();
        if (! $n) {
            return;
        }
        $n->markRead();
        $this->open = false;
        if ($n->action_url) {
            $this->redirect($n->action_url, navigate: true);
        }
    }
}; ?>

<div class="relative" x-data="{ open: @entangle('open') }" @click.outside="open = false" wire:poll.15s>
    <button type="button" @click="open = !open" class="relative p-2 rounded-lg text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors" title="Bildirimler" aria-label="Bildirimler">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        @if($this->unreadCount > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-brand-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white dark:ring-neutral-900">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.right class="fixed left-4 right-4 top-[4.25rem] sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-[22rem] z-50 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 shadow-apple-lg overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 border-b border-neutral-100 dark:border-neutral-800">
            <span class="text-xs font-bold text-neutral-900 dark:text-white">Bildirimler</span>
            @if($this->unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-[11px] font-semibold text-brand-500 hover:underline">Tümünü okundu işaretle</button>
            @endif
        </div>
        <div class="max-h-96 overflow-y-auto divide-y divide-neutral-100 dark:divide-neutral-800">
            @forelse($this->recent as $n)
                <button type="button" wire:click="openNotification({{ $n->id }})" class="w-full text-left px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/60 transition-colors {{ $n->isRead() ? '' : 'bg-brand-500/5' }}">
                    <div class="flex items-start gap-2">
                        <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $n->isRead() ? 'bg-neutral-300 dark:bg-neutral-700' : 'bg-brand-500' }}"></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-semibold text-neutral-900 dark:text-white truncate">{{ $n->title }}</div>
                            <div class="text-[11px] text-neutral-500 dark:text-neutral-400 line-clamp-2 leading-relaxed">{{ $n->lines[0] ?? '' }}</div>
                            <div class="text-[10px] text-neutral-400 mt-1"><x-time-ago :at="$n->created_at" /> · {{ $n->typeLabel() }}</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="px-4 py-8 text-center text-xs text-neutral-500">Henüz bildiriminiz yok.</div>
            @endforelse
        </div>
        <a href="{{ route($indexRoute) }}" wire:navigate @click="open = false" class="block px-4 py-3 text-center text-xs font-semibold text-neutral-700 dark:text-neutral-200 bg-neutral-50 dark:bg-neutral-800/60 hover:text-brand-500">Tüm bildirimleri gör</a>
    </div>
</div>
