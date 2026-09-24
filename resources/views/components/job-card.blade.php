{{-- İş kartı: NavlunIQ ilanından (kabul edilen teklif) ya da gruptan ("Bu işi aldım") alınan iş. İşlerim ve Genel bakış
     aynı kartı kullanır; eylemler HandlesJobActions trait'i ile çalışır. Açık iş turuncu çerçevelidir.
     returnLoads: ['system' => Collection<Load>, 'external' => Collection<ScrapedLoad>] ya da null. --}}
@props(['trip', 'expanded' => false, 'returnLoads' => null, 'savedExternalIds' => [], 'takenExternalIds' => [], 'isPremium' => false])
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
            @if($load && $trip->isOpen() && $load->isPaid())<span class="badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">{{ $load->escrowLabel() }}</span>@endif
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
                @if($trip->isOpen() && $key === 'on_the_way')
                    <a href="{{ route('driver.jobs.show', $trip->load_id) }}#teslimat" wire:navigate class="load-card-action">Teslim ettim</a>
                @endif
                <a href="{{ route('driver.jobs.show', $trip->load_id) }}" wire:navigate class="load-card-action-ghost">{{ $trip->isOpen() ? 'Ayrıntı ve teslimat' : 'Ayrıntı' }}</a>
            @else
                @if(in_array($trip->status, ['planned', 'on_the_way'], true))<button type="button" wire:click="setStatus({{ $trip->id }}, 'delivered')" class="load-card-action-ghost">Teslim ettim</button>@endif
                @if($trip->canCloseManually())<button type="button" wire:click="setStatus({{ $trip->id }}, 'closed')" wire:confirm="İş kapatılsın mı? Dönüş yükü bildirimi durur." class="load-card-action-ghost text-neutral-500">Kapat</button>@endif
            @endif
        </div>
    @endif

    @if($trip->isOpen())
        <div class="pt-3 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <label class="inline-flex items-center gap-2 cursor-pointer text-neutral-600 dark:text-neutral-300">
                <input type="checkbox" wire:click="toggleNotify({{ $trip->id }})" @checked($trip->notify_return) class="rounded">
                Dönüş yükü çıkınca bildir
            </label>
            <button type="button" wire:click="toggleReturnLoads({{ $trip->id }})" class="text-brand-500 font-bold hover:underline text-left">
                {{ $expanded ? 'Dönüş yüklerini gizle' : 'Dönüş yüklerini göster' }} ({{ $trip->delivery_location ?: 'varış' }} çevresi)
            </button>
        </div>
        @if($expanded && $returnLoads !== null)
            <div class="space-y-2">
                @foreach($returnLoads['system'] as $rl)
                    @php $lp = (float) ($rl->price ?? 0); @endphp
                    <div class="load-card load-card-return" wire:key="rl-s-{{ $trip->id }}-{{ $rl->id }}">
                        <div class="load-card-main">
                            <div class="load-card-title">{{ $rl->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $rl->delivery_location }}</div>
                            <div class="load-card-line">{{ $rl->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($rl->vehicle_type), $rl->bodyLabel()])) }} · Yükleme: {{ $rl->pickup_date?->format('d.m.Y') ?? 'Belirtilmemiş' }}</div>
                            <div class="load-card-badges"><span class="badge-return"><svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-3l2 2M20 15a8 8 0 01-14 3l-2-2"/></svg>Dönüş yükü</span><span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">NavlunIQ ilanı</span></div>
                        </div>
                        <div class="load-card-side sm:min-h-0">
                            <div class="load-card-price">{{ number_format($lp, 0, ',', '.') }} ₺</div>
                            <a href="{{ route('driver.loads.index', ['ilan' => $rl->id]) }}" wire:navigate class="load-card-action">Teklif ver</a>
                        </div>
                    </div>
                @endforeach
                @foreach($returnLoads['external'] as $item)
                    <x-external-load-card :item="$item" variant="return" :saved="in_array($item->id, $savedExternalIds, true)" :taken="in_array($item->id, $takenExternalIds, true)" wire:key="rl-e-{{ $trip->id }}-{{ $item->id }}" />
                @endforeach
                @if($returnLoads['system']->isEmpty() && $returnLoads['external']->isEmpty())
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-neutral-500">Şu anda {{ $trip->delivery_location ?: 'varış yeri' }} çevresinden çıkan, aracınıza uyan ilan yok. Yeni ilan gelince {{ $trip->notify_return ? 'bildirilecek' : 'burada görünecek' }}.@if(! $isPremium) Gruptan derlenen ilanlar premium üyelere gösterilir.@endif</div>
                @endif
            </div>
        @endif
    @endif
</div>
