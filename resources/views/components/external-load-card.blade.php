{{-- Dış kaynak ilan kartı: her ekranda aynı yapı (yıldız, "Bu işi aldım", numara, WhatsApp). Kullanan Livewire bileşeni
     HandlesExternalLoadActions trait'ini kullanmalıdır (toggleSave / openTake). --}}
@props(['item', 'saved' => false, 'taken' => false])
@php $plainPhone = $item->plainPhone(); $extraPhones = $item->extraPhones(); $isSaved = (bool) $saved; $isTaken = (bool) $taken; @endphp
<div {{ $attributes->merge(['class' => 'load-card']) }}>
    <div class="load-card-main">
        <div class="load-card-title">{{ $item->pickup_location ?: 'Belirtilmemiş' }} <span class="text-amber-600 dark:text-amber-400">&rarr;</span> {{ $item->delivery_location ?: 'Belirtilmemiş' }}</div>
        <div class="load-card-line">{{ $item->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ $item->vehicleSummary() }}@if($item->weightLabel()) · {{ $item->weightLabel() }}@endif</div>
        <div class="load-card-line">Yükleme: {{ $item->meta('pickup_note') ?: 'Belirtilmemiş' }} · <x-time-ago :at="$item->created_at" /></div>
        <div class="load-card-badges">
            <span class="badge bg-amber-500/10 text-amber-700 dark:text-amber-400">Gruptan derlendi</span>
            @if($item->isUrgent())<span class="badge bg-red-500 text-white">ACİL</span>@endif
            @foreach($item->traitLabels() as $trait)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trait }}</span>@endforeach
        </div>
    </div>
    <div class="load-card-side">
        @if($item->priceLabel())
            <div class="load-card-price">{{ $item->priceLabel() }}</div>
        @else
            <div class="load-card-price-muted">Fiyat belirtilmemiş</div>
        @endif
                <div class="flex items-center gap-1.5">
            <button type="button" wire:click="toggleSave('external', {{ $item->id }})" class="load-card-star {{ $isSaved ? 'load-card-star-on' : '' }}" title="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-label="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg>
            </button>
            @if($isTaken)
                <a href="{{ route('driver.trips.index') }}" wire:navigate class="load-card-action-ghost text-emerald-700 dark:text-emerald-400 border-emerald-500/40" title="Bu ilan için açık seferiniz var">✓ Seferimde</a>
            @else
                <button type="button" wire:click="openTake({{ $item->id }})" class="load-card-action-ghost" title="İşi aldıysanız seferinizi kaydedin; varış yerinize göre dönüş yükü bildirilir">Bu işi aldım</button>
            @endif
        </div>
        @if($plainPhone)
            @php $allPhones = array_values(array_unique(array_merge([$plainPhone], $extraPhones))); $waIcon = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.5l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.1.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2l-.5-.3z"/></svg>'; @endphp
            <div class="load-card-phones" x-data="{ open: false }">
                <div class="load-card-phone">
                    <a href="tel:+90{{ $allPhones[0] }}" class="text-brand-500 font-bold hover:underline tabular-nums whitespace-nowrap">{{ \App\Support\Phone::format($allPhones[0]) }}</a>
                    @if(count($allPhones) > 1)
                        <button type="button" @click="open = true" class="load-card-wa" title="Bu ilanın tüm numaraları">{!! $waIcon !!}WhatsApp <span class="ml-0.5 inline-flex items-center justify-center min-w-[1.25rem] h-4 px-1 rounded-full bg-emerald-600 text-white text-[10px]">+{{ count($allPhones) - 1 }}</span></button>
                    @else
                        <a href="{{ $item->whatsappUrl($allPhones[0], auth()->user()) }}" target="_blank" rel="noopener" class="load-card-wa" title="Hazır mesajla WhatsApp sohbeti açar">{!! $waIcon !!}WhatsApp</a>
                    @endif
                </div>
                @if(count($allPhones) > 1)
                    <template x-teleport="body">
                        <div x-show="open" x-cloak class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center p-4" @keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-label="İlan numaraları">
                            <div class="fixed inset-0 bg-neutral-950/60 backdrop-blur-sm" x-show="open" x-transition.opacity @click="open = false"></div>
                            <div class="relative z-10 w-full max-w-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-3xl p-5 shadow-2xl space-y-3 text-xs" x-show="open" x-transition.scale.origin.bottom @click.outside="open = false">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $item->pickup_location ?: 'Belirtilmemiş' }} → {{ $item->delivery_location ?: 'Belirtilmemiş' }}</div>
                                        <div class="text-neutral-500 mt-0.5">Bu ilanda {{ count($allPhones) }} iletişim numarası var. İstediğinizi arayın ya da hazır mesajla yazın.</div>
                                    </div>
                                    <button type="button" @click="open = false" class="shrink-0 p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 dark:hover:bg-neutral-800" aria-label="Kapat">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <div class="space-y-2">
                                    @foreach($allPhones as $i => $phone)
                                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-2xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 px-3 py-2.5">
                                            <div class="min-w-0">
                                                <div class="text-[10px] uppercase tracking-wider text-neutral-400">{{ $i === 0 ? 'Ana numara' : ($i + 1).'. numara' }}</div>
                                                <a href="tel:+90{{ $phone }}" class="block text-sm font-bold text-neutral-900 dark:text-white tabular-nums whitespace-nowrap hover:underline">{{ \App\Support\Phone::format($phone) }}</a>
                                            </div>
                                            <div class="flex items-center gap-1.5 shrink-0 ml-auto">
                                                <a href="tel:+90{{ $phone }}" class="inline-flex items-center gap-1 rounded-lg bg-brand-500/10 px-2.5 py-1.5 text-[11px] font-bold text-brand-600 dark:text-brand-400 hover:bg-brand-500/20">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.3a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.2l-2.26 1.13a11 11 0 005.52 5.52l1.13-2.26a1 1 0 011.2-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.7 21 3 14.3 3 6V5z"/></svg>Ara
                                                </a>
                                                <a href="{{ $item->whatsappUrl($phone, auth()->user()) }}" target="_blank" rel="noopener" class="load-card-wa">{!! $waIcon !!}WhatsApp</a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </template>
                @endif
            </div>
        @else
            <div class="text-neutral-400 whitespace-nowrap">Numara yok</div>
        @endif
    </div>
</div>
