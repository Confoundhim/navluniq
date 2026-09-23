<div class="max-w-4xl mx-auto space-y-5" wire:poll.15s>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Bildirimler</h2>
            <p class="page-subtitle">Teklif, sevkiyat, ödeme ve belge süreçlerinizle ilgili tüm gelişmeler. Aynı bildirimler e-posta adresinize de gönderilir.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl text-xs font-semibold">
                <button type="button" wire:click="setFilter('all')" class="px-3 py-1.5 rounded-lg {{ $filter === 'all' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Tümü</button>
                <button type="button" wire:click="setFilter('unread')" class="px-3 py-1.5 rounded-lg {{ $filter === 'unread' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Okunmamış @if($unreadCount > 0)<span class="ml-1 text-brand-500">{{ $unreadCount }}</span>@endif</button>
            </div>
            @if($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="btn-secondary py-2 px-3 text-xs">Tümünü okundu işaretle</button>
            @endif
            @if($readCount > 0)
                <button type="button" wire:click="deleteRead" wire:confirm="Okunmuş {{ $readCount }} bildirim silinsin mi?" class="btn-secondary py-2 px-3 text-xs text-neutral-500">Okunanları sil</button>
            @endif
        </div>
    </div>

    <div class="space-y-2">
        @forelse($notifications as $n)
            <div class="rounded-2xl border p-4 bg-white dark:bg-neutral-900 {{ $n->isRead() ? 'border-neutral-200 dark:border-neutral-800' : 'border-brand-500/30 bg-brand-500/[0.03]' }}" wire:key="n-{{ $n->id }}">
                <div class="flex items-start gap-3">
                    <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $n->isRead() ? 'bg-neutral-300 dark:bg-neutral-700' : 'bg-brand-500' }}"></span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-sm font-semibold text-neutral-900 dark:text-white">{{ $n->title }}</span>
                            <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400">{{ $typeLabels[$n->type] ?? $n->type }}</span>
                            <span class="text-[11px] text-neutral-400">{{ $n->created_at->format('d.m.Y H:i') }}</span>
                        </div>
                        @foreach($n->lines as $line)
                            <p class="text-xs text-neutral-600 dark:text-neutral-300 leading-relaxed">{{ $line }}</p>
                        @endforeach
                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            @if($n->action_url)
                                <button type="button" wire:click="open({{ $n->id }})" class="text-xs font-semibold text-brand-500 hover:underline">{{ $n->action_text ?? 'Görüntüle' }} →</button>
                            @endif
                            @if(! $n->isRead())
                                <button type="button" wire:click="markRead({{ $n->id }})" class="text-[11px] font-semibold text-neutral-500 hover:text-neutral-900 dark:hover:text-white">Okundu işaretle</button>
                            @endif
                            <button type="button" wire:click="delete({{ $n->id }})" class="text-[11px] font-semibold text-neutral-400 hover:text-rose-500 ml-auto" aria-label="Bildirimi sil">Sil</button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-neutral-200 dark:border-neutral-800 p-10 text-center text-xs text-neutral-500">{{ $filter === 'unread' ? 'Okunmamış bildiriminiz yok.' : 'Henüz bildiriminiz yok. Teklif, sevkiyat ve ödeme gelişmeleri burada listelenir.' }}</div>
        @endforelse
    </div>

    <div>{{ $notifications->links() }}</div>
</div>
