<?php

use App\Livewire\Concerns\HandlesExternalLoadActions;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\ScrapedLoad;
use App\Services\DriverTripService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.driver')]
#[Title('Seferlerim')]
class extends Component {
    use HandlesExternalLoadActions;

    public string $tab = 'open';

    /** Dönüş yükleri açık gösterilen sefer */
    public ?int $expanded = null;

    public function mount(): void
    {
        $this->tab = request()->query('sekme') === 'past' ? 'past' : 'open';
        $sefer = (int) request()->query('sefer', 0);
        if ($sefer > 0 && ($status = $this->tripsQuery()->whereKey($sefer)->value('status'))) {
            $this->expanded = $sefer;
            $this->tab = $status === DriverTrip::STATUS_CLOSED ? 'past' : 'open';
        }
    }

    private function tripsQuery()
    {
        return DriverTrip::query()->where('driver_profile_id', Auth::user()->driverProfile?->id ?? 0);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'past' ? 'past' : 'open';
        $this->expanded = null;
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function setStatus(int $id, string $status, DriverTripService $trips): void
    {
        $trip = $this->tripsQuery()->whereKey($id)->first();
        if (! $trip) {
            return;
        }
        if ($trip->isSystem() && $status !== DriverTrip::STATUS_CLOSED) {
            session()->flash('error_message', 'NavlunIQ ilanındaki seferin durumu sevkiyat sayfasından güncellenir.');

            return;
        }
        try {
            $trips->setStatus($trip, $status);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }
        session()->flash('success_message', 'Sefer durumu: '.$trip->statusLabel());
    }

    public function toggleNotify(int $id): void
    {
        $trip = $this->tripsQuery()->whereKey($id)->first();
        if ($trip) {
            $trip->forceFill(['notify_return' => ! $trip->notify_return])->save();
        }
    }

    public function with(): array
    {
        $trips = $this->tripsQuery()->with(['scrapedLoad', 'cargoLoad'])
            ->when($this->tab === 'past', fn ($q) => $q->where('status', DriverTrip::STATUS_CLOSED)->latest('closed_at')->take(30), fn ($q) => $q->open()->latest('id'))
            ->get();
        $returnLoads = null;
        if ($this->expanded !== null && ($trip = $trips->firstWhere('id', $this->expanded)) && $trip->isOpen()) {
            $returnLoads = app(DriverTripService::class)->returnLoadsFor($trip, onlyNew: false, limit: 10);
        }

        $profileId = Auth::user()->driverProfile?->id ?? 0;

        return [
            'savedExternalIds' => DriverSavedLoad::query()->where('driver_profile_id', $profileId)->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all(),
            'takenExternalIds' => $trips->pluck('scraped_load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'takeLoad' => $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null,
            'trips' => $trips,
            'returnLoads' => $returnLoads,
            'radiusKm' => \App\Support\Settings::int('return_load_radius_km'),
            'isPremium' => Auth::user()->driverProfile?->isPremium() ?? false,
        ];
    }
}; ?>

<div class="space-y-6">
    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="page-title">Seferlerim</h2>
            <p class="page-subtitle">"Bu işi aldım" dediğiniz işler ve kabul edilen teklifleriniz. Varış yerinizin çevresinden ({{ $radiusKm }} km ve aynı il) çıkan yeni ilanlar dönüş yükü olarak bildirilir.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach(['open' => 'Açık seferler', 'past' => 'Geçmiş'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" class="px-4 py-2 rounded-xl font-bold border transition-colors {{ $tab === $key ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-white dark:bg-neutral-900 border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="space-y-3">
        @forelse($trips as $trip)
            <div class="rounded-2xl p-5 space-y-3 text-xs {{ $trip->isOpen() ? 'border-2 border-brand-500/70 bg-brand-500/5 dark:bg-brand-500/10' : 'bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800' }}" wire:key="trip-{{ $trip->id }}">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $trip->pickup_location ?: 'Belirtilmemiş' }} <span class="text-brand-500">&rarr;</span> {{ $trip->delivery_location ?: 'Belirtilmemiş' }}</div>
                        <div class="text-neutral-500 dark:text-neutral-400 mt-1">
                            Yükleme: {{ $trip->pickup_date?->format('d.m.Y') ?? '—' }} · Teslim: {{ $trip->delivery_date?->format('d.m.Y') ?? '—' }}
                            @if($trip->scrapedLoad?->goods_type) · {{ $trip->scrapedLoad->goods_type }}@elseif($trip->cargoLoad?->goods_type) · {{ $trip->cargoLoad->goods_type }}@endif
                        </div>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            <span class="badge {{ $trip->isSystem() ? 'bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400' }}">{{ $trip->isSystem() ? 'NavlunIQ ilanı' : 'Gruptan derlendi' }}</span>
                            <span class="trip-status trip-status-{{ $trip->status }}"><span class="inline-flex h-1.5 w-1.5 rounded-full bg-current"></span>{{ $trip->statusLabel() }}</span>
                            @if($trip->match_count > 0)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trip->match_count }} dönüş yükü bildirildi</span>@endif
                        </div>
                    </div>
                    @if($trip->isOpen())
                        <div class="flex flex-wrap gap-2 shrink-0">
                            @if($trip->isSystem() && $trip->load_id)
                                <a href="{{ route('driver.shipments.show', $trip->load_id) }}" wire:navigate class="load-card-action-ghost">Sevkiyatı yönet</a>
                            @else
                                @if($trip->status === 'planned')<button type="button" wire:click="setStatus({{ $trip->id }}, 'on_the_way')" class="load-card-action">Yola çıktım</button>@endif
                                @if(in_array($trip->status, ['planned', 'on_the_way'], true))<button type="button" wire:click="setStatus({{ $trip->id }}, 'delivered')" class="load-card-action-ghost">Teslim ettim</button>@endif
                            @endif
                            <button type="button" wire:click="setStatus({{ $trip->id }}, 'closed')" wire:confirm="Sefer kapatılsın mı? Dönüş yükü bildirimi durur." class="load-card-action-ghost text-neutral-500">Kapat</button>
                        </div>
                    @endif
                </div>

                @if($trip->isOpen())
                    <div class="pt-3 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer text-neutral-600 dark:text-neutral-300">
                            <input type="checkbox" wire:click="toggleNotify({{ $trip->id }})" @checked($trip->notify_return) class="rounded">
                            Dönüş yükü çıkınca bildir
                        </label>
                        <button type="button" wire:click="toggle({{ $trip->id }})" class="text-brand-500 font-bold hover:underline text-left">
                            {{ $expanded === $trip->id ? 'Dönüş yüklerini gizle' : 'Dönüş yüklerini göster' }} ({{ $trip->delivery_location ?: 'varış' }} çevresi)
                        </button>
                    </div>
                    @if($expanded === $trip->id && $returnLoads)
                        <div class="space-y-2">
                            @foreach($returnLoads['system'] as $load)
                                @php $lp = (float) ($load->price ?? 0); @endphp
                                <div class="load-card load-card-return" wire:key="rl-s-{{ $load->id }}">
                                    <div class="load-card-main">
                                        <div class="load-card-title">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                                        <div class="load-card-line">{{ $load->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($load->vehicle_type), $load->bodyLabel()])) }} · Yükleme: {{ $load->pickup_date?->format('d.m.Y') ?? 'Belirtilmemiş' }}</div>
                                        <div class="load-card-badges"><span class="badge-return"><svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-3l2 2M20 15a8 8 0 01-14 3l-2-2"/></svg>Dönüş yükü</span><span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">NavlunIQ ilanı</span></div>
                                    </div>
                                    <div class="load-card-side sm:min-h-0">
                                        <div class="load-card-price">{{ number_format($lp, 0, ',', '.') }} ₺</div>
                                        <a href="{{ route('driver.loads.index', ['ilan' => $load->id]) }}" wire:navigate class="load-card-action">Teklif ver</a>
                                    </div>
                                </div>
                            @endforeach
                            @foreach($returnLoads['external'] as $item)
                                <x-external-load-card :item="$item" variant="return" :saved="in_array($item->id, $savedExternalIds, true)" :taken="in_array($item->id, $takenExternalIds, true)" wire:key="rl-e-{{ $item->id }}" />
                            @endforeach
                            @if($returnLoads['system']->isEmpty() && $returnLoads['external']->isEmpty())
                                <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-neutral-500">Şu anda {{ $trip->delivery_location ?: 'varış yeri' }} çevresinden çıkan, aracınıza uyan ilan yok. Yeni ilan gelince {{ $trip->notify_return ? 'bildirilecek' : 'burada görünecek' }}.@if(! $isPremium) Gruptan derlenen ilanlar premium üyelere gösterilir.@endif</div>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        @empty
            <div class="p-8 bg-white dark:bg-neutral-900 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-2xl text-center text-xs text-neutral-500 dark:text-neutral-400 space-y-2">
                <div>{{ $tab === 'past' ? 'Kapanmış sefer yok.' : 'Açık seferiniz yok.' }}</div>
                @if($tab === 'open')<div>Dış kaynak ilanında <strong>Bu işi aldım</strong> deyince ya da bir teklifiniz kabul edilince sefer burada açılır.</div>
                <a href="{{ route('driver.loads.index', ['tab' => 'external']) }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">Dış kaynak ilanlarına git</a>@endif
            </div>
        @endforelse
    </div>
    <x-take-trip-modal :load="$takeModalOpen ? $takeLoad : null" />
</div>
