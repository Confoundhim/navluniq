{{-- İş kartı: NavlunIQ ilanından (kabul edilen teklif) ya da gruptan ("Bu işi aldım") alınan iş. İşlerim ve Genel bakış
     aynı kartı kullanır; eylemler HandlesJobActions trait'i ile çalışır. Açık iş turuncu çerçevelidir.
     returnLoads: ['system' => Collection<Load>, 'external' => Collection<ScrapedLoad>] ya da null. --}}
@props(['trip', 'expanded' => false, 'returnLoads' => null, 'savedSystemIds' => [], 'savedExternalIds' => [], 'takenExternalIds' => [], 'isPremium' => false])
@php
    $load = $trip->isSystem() ? $trip->cargoLoad : null;
    $goods = $trip->scrapedLoad?->goods_type ?: $load?->goods_type;
    $key = $trip->displayStatusKey();
@endphp
<div {{ $attributes->merge(['class' => 'job-card '.($trip->isOpen() ? 'job-card-open' : '')]) }}>
    <div class="min-w-0 space-y-1.5">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="badge {{ $trip->isSystem() ? 'bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400' }}">{{ $trip->sourceLabel() }}</span>
            <span class="trip-status trip-status-{{ $key }}"><span class="inline-flex h-1.5 w-1.5 rounded-full bg-current"></span>{{ $trip->displayStatusLabel() }}</span>
            @if($load && $trip->isOpen() && ($load->isPaid() || $load->isDirectPayment()))<span class="badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">{{ $load->escrowLabel() }}</span>@endif
            @if($trip->match_count > 0)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trip->match_count }} dönüş yükü bildirildi</span>@endif
        </div>
        <div class="text-sm font-bold text-neutral-900 dark:text-white break-words">{{ $trip->pickup_location ?: 'Belirtilmemiş' }} <span class="text-brand-500">&rarr;</span> {{ $trip->delivery_location ?: 'Belirtilmemiş' }}</div>
        <div class="text-neutral-500 dark:text-neutral-400 break-words">
            Yükleme: {{ $trip->pickup_date?->format('d.m.Y') ?? '—' }} · Teslim: {{ $trip->delivery_date?->format('d.m.Y') ?? '—' }}@if($goods) · {{ $goods }}@endif
        </div>
        @if($load)
            <div class="text-neutral-500 dark:text-neutral-400 break-words">Yük sahibi: <span class="text-neutral-900 dark:text-white font-semibold">{{ $load->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}</span> · Navlun: <span class="text-neutral-900 dark:text-white tabular-nums font-bold">{{ number_format((float) ($load->price ?? 0), 0, ',', '.') }} ₺</span></div>
        @elseif(! $trip->isOpen() && $trip->closed_at)
            <div class="text-neutral-400">Kapandı: {{ $trip->closed_at->format('d.m.Y') }}</div>
        @endif
    </div>

    @if($trip->isOpen() || $load)
        <div class="flex flex-wrap items-center gap-2">
            @if($trip->canStart())
                <button type="button" wire:click="setStatus({{ $trip->id }}, 'on_the_way')" wire:confirm="Yükü teslim aldığınızı ve yola çıktığınızı onaylıyor musunuz?" class="load-card-action">Yola çıktım</button>
            @endif
            @if($trip->isSystem())
                @if($trip->isOpen() && ($key === 'on_the_way' || ($key === 'disputed' && $trip->shipment && $trip->shipment->delivered_at === null)))
                    <a href="{{ route('driver.jobs.show', $trip->load_id) }}#teslimat" wire:navigate class="load-card-action">Teslim ettim</a>
                @endif
                <a href="{{ route('driver.jobs.show', $trip->load_id) }}" wire:navigate class="load-card-action-ghost">{{ $trip->isOpen() ? 'Ayrıntı ve teslimat' : 'Ayrıntı' }}</a>
                @if($trip->isOpen() && $load && $load->status === \App\Models\Load::STATUS_ASSIGNED && in_array($load->escrow_status, [\App\Models\Load::ESCROW_PENDING, \App\Models\Load::ESCROW_PAID, \App\Models\Load::ESCROW_DIRECT], true))
                    <button type="button" wire:click="withdrawJob({{ $trip->id }})" wire:confirm="{{ $load->isDirectPayment() ? 'Yola çıkmadan vazgeçiyorsunuz: ilan yeniden havuza döner ve vazgeçme hesabınızda sayılır. Devam edilsin mi?' : ($load->isPaid() ? 'Yola çıkmadan vazgeçiyorsunuz: ilan yeniden havuza döner, navlun yük sahibine iade edilir ve vazgeçme hesabınızda sayılır. Devam edilsin mi?' : 'Ödeme beklemekten vazgeçiyorsunuz; ilan yeniden havuza döner ve bu iş kapanır. Devam edilsin mi?') }}" class="load-card-action-ghost text-neutral-500">Vazgeç</button>
                @endif
            @else
                @if($trip->status === 'on_the_way')<button type="button" wire:click="setStatus({{ $trip->id }}, 'delivered')" class="load-card-action-ghost">Teslim ettim</button>@endif
                @if($trip->canCloseManually())<button type="button" wire:click="setStatus({{ $trip->id }}, 'closed')" wire:confirm="İş kapatılsın mı? Dönüş yükü bildirimi durur." class="load-card-action-ghost text-neutral-500">Kapat</button>@endif
            @endif
        </div>
    @endif

    @if($trip->isOpen())
        <div class="pt-3 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            @if($isPremium)
                <label class="inline-flex items-center gap-2 cursor-pointer text-neutral-600 dark:text-neutral-300">
                    <input type="checkbox" wire:click="toggleNotify({{ $trip->id }})" @checked($trip->notify_return) class="rounded">
                    Dönüş yükü çıkınca bildir
                </label>
            @else
                <span class="text-neutral-500 dark:text-neutral-400">Dönüş yükleri burada görünür</span>
            @endif
            <button type="button" wire:click="toggleReturnLoads({{ $trip->id }})" class="text-brand-500 font-bold hover:underline text-left">
                {{ $expanded ? 'Dönüş yüklerini gizle' : 'Dönüş yüklerini göster' }} ({{ $trip->delivery_location ?: 'varış' }} çevresi)
            </button>
        </div>
        @if($expanded && $returnLoads !== null)
            <div class="space-y-2">
                @foreach($returnLoads['system'] as $rl)
                    <x-system-load-card :load="$rl" variant="return" :saved="in_array($rl->id, $savedSystemIds, true)" offer="link" wire:key="rl-s-{{ $trip->id }}-{{ $rl->id }}" />
                @endforeach
                @foreach($returnLoads['external'] as $item)
                    <x-external-load-card :item="$item" variant="return" :saved="in_array($item->id, $savedExternalIds, true)" :taken="in_array($item->id, $takenExternalIds, true)" wire:key="rl-e-{{ $trip->id }}-{{ $item->id }}" />
                @endforeach
                @if($returnLoads['system']->isEmpty() && $returnLoads['external']->isEmpty())
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-neutral-500">Şu anda {{ $trip->delivery_location ?: 'varış yeri' }} çevresinden çıkan, aracınıza uyan ilan yok. Yeni ilan gelince {{ $isPremium && $trip->notify_return ? 'bildirilecek' : 'burada görünecek' }}.@if(! $isPremium) Gruptan derlenen ilanlar premium üyelere gösterilir.@endif</div>
                @endif
            </div>
        @endif
    @endif
</div>
