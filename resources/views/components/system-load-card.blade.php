{{-- NavlunIQ (sistem) ilan kartı: genel bakış, ilan havuzu, kaydedilenler ve dönüş yükü listesi aynı bileşeni kullanır;
     rozetler, tarih biçimi (d.m.Y H:i) ve eylemler her ekranda birebir aynıdır. Kullanan Livewire bileşeni
     HandlesExternalLoadActions trait'ini kullanmalıdır (toggleSave). offer: "modal" → openOffer(id) (ilan havuzu),
     "link" → havuza ?ilan= ile gider. offerable=false → "Teklif ver" yok, durum rozeti çıkar (kapanmış kayıtlı ilan).
     Varsayılan slot kartın bilgi satırına eklenir ("Kaydedildi: 2 sa önce"). --}}
@props(['load', 'saved' => false, 'variant' => null, 'offer' => 'link', 'offerable' => true])
@php
    $owner = $load->cargoOwnerProfile;
    $isSaved = (bool) $saved;
    $vol = (int) ($load->volume ?? 0);
    $distance = $load->distanceLabel();
    $isOpen = $load->status === \App\Models\Load::STATUS_ACTIVE;
    $canOffer = $offerable && $isOpen;
@endphp
<div {{ $attributes->merge(['class' => 'load-card'.($variant === 'return' ? ' load-card-return' : '')]) }}>
    <div class="load-card-main">
        <div class="load-card-title">{{ $load->pickup_location ?: 'Belirtilmemiş' }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location ?: 'Belirtilmemiş' }}</div>
        <div class="load-card-line">{{ $load->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ $load->vehicleSummary() }}@if($load->weightLabel()) · {{ $load->weightLabel() }}@endif@if($vol > 0) · {{ number_format($vol, 0, ',', '.') }} m³@endif</div>
        <div class="load-card-line">Yükleme: {{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}@if($load->delivery_date) · Teslim: {{ $load->delivery_date->format('d.m.Y H:i') }}@endif · {{ $owner?->publicName() ?: 'Yük sahibi belirtilmemiş' }}@if(trim($slot) !== '') · {{ $slot }}@endif</div>
        @if($distance)<div class="load-card-line tabular-nums" title="Kara yolu tahmini ve kilometre başına navlun">{{ $distance }}</div>@endif
        <div class="load-card-badges">
            @if($variant === 'return')<span class="badge-return"><svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-3l2 2M20 15a8 8 0 01-14 3l-2-2"/></svg>Dönüş yükü</span>@endif
            <span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">NavlunIQ ilanı</span>
            @if($owner?->isVerified())<span class="badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" title="{{ $owner->type === 'corporate' ? 'Şirket bilgileri teyit edildi' : 'Kimliği NVİ ile doğrulandı' }}">✓ Doğrulanmış yük sahibi</span>@endif
            @if($isOpen && $load->isEarlyAccess())<span class="badge bg-amber-500/10 text-amber-700 dark:text-amber-400" title="Herkese {{ $load->available_to_free_at->format('H:i') }}'de açılır">⭐ Erken erişim</span>@endif
            @if(! $isOpen)<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">{{ $load->statusLabel() }}</span>@endif
        </div>
    </div>
    <div class="load-card-side">
        @if($load->priceLabel())
            <div class="load-card-price">{{ $load->priceLabel() }}</div>
        @else
            <div class="load-card-price-muted">Fiyat belirtilmemiş</div>
        @endif
        <div class="load-card-actions">
            <button type="button" wire:click="toggleSave('system', {{ $load->id }})" class="load-card-star {{ $isSaved ? 'load-card-star-on' : '' }}" title="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-label="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg>
            </button>
            <button type="button" x-data="shareLoad" @click="share()" data-share-text="{{ $load->shareText() }}" data-share-url="{{ $load->shareUrl() }}" class="load-card-action-ghost" :class="copied ? 'text-emerald-700 dark:text-emerald-400 border-emerald-500/40' : ''" aria-label="İlanı paylaş"><span x-text="copied ? 'Kopyalandı' : 'Paylaş'">Paylaş</span></button>
            @if($canOffer)
                @if($offer === 'modal')
                    <button type="button" wire:click="openOffer({{ $load->id }})" class="load-card-action">Teklif ver</button>
                @else
                    <a href="{{ route('driver.loads.index', ['ilan' => $load->id]) }}" wire:navigate class="load-card-action">Teklif ver</a>
                @endif
            @endif
        </div>
    </div>
</div>
