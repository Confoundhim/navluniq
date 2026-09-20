<?php

use App\Models\DriverFilterPreset;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Services\LoadFilterService;
use App\Services\OfferService;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('İlan Havuzu ve Tekliflerim')]
class extends Component {
    use WithPagination;

    public string $tab = 'pool';

    public string $search = '';

    /** @var array<string, mixed> LoadFilterService::defaults() yapısı */
    public array $filters = [];

    #[Locked]
    public ?int $presetId = null;

    public bool $advancedOpen = false;

    public bool $presetModalOpen = false;

    public string $presetName = '';

    public bool $presetDefault = false;

    public function mount(): void
    {
        $this->tab = in_array(request()->query('tab'), ['pool', 'offers', 'external'], true) ? request()->query('tab') : 'pool';
        $this->filters = LoadFilterService::defaults();

        $requested = (int) request()->query('preset', 0);
        $preset = $requested > 0
            ? $this->presetsQuery()->whereKey($requested)->first()
            : $this->presetsQuery()->where('is_default', true)->first();
        if ($preset) {
            $this->applyPreset($preset->id);
        }
        if (request()->boolean('filters')) {
            $this->advancedOpen = true;
        }
    }

    private function presetsQuery()
    {
        return DriverFilterPreset::query()->where('driver_profile_id', $this->profile()?->id ?? 0);
    }

    public function updatedFilters(): void
    {
        $this->filters = LoadFilterService::normalize($this->filters);
        $this->resetPage();
    }

    public function applyPreset(?int $id): void
    {
        if ($id === null) {
            $this->presetId = null;
            $this->filters = LoadFilterService::defaults();
            $this->resetPage();

            return;
        }
        $preset = $this->presetsQuery()->whereKey($id)->first();
        if (! $preset) {
            return;
        }
        $this->presetId = $preset->id;
        $this->filters = LoadFilterService::normalize((array) $preset->filters);
        $preset->forceFill(['last_used_at' => now()])->save();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->presetId = null;
        $this->search = '';
        $this->filters = LoadFilterService::defaults();
        $this->resetPage();
    }

    public function addProvince(string $side, $code): void
    {
        $key = $side === 'delivery' ? 'delivery_provinces' : 'pickup_provinces';
        $code = (int) $code;
        if ($code >= 1 && $code <= 81 && ! in_array($code, $this->filters[$key], true)) {
            $this->filters[$key][] = $code;
        }
        $this->updatedFilters();
    }

    public function removeProvince(string $side, int $code): void
    {
        $key = $side === 'delivery' ? 'delivery_provinces' : 'pickup_provinces';
        $this->filters[$key] = array_values(array_filter($this->filters[$key], fn ($c) => (int) $c !== $code));
        $this->updatedFilters();
    }

    public function toggleVehicleType(string $type): void
    {
        if (! VehicleTypes::isValid($type)) {
            return;
        }
        $this->filters['vehicle_mode'] = 'custom';
        $types = $this->filters['vehicle_types'];
        $this->filters['vehicle_types'] = in_array($type, $types, true) ? array_values(array_diff($types, [$type])) : [...$types, $type];
        $this->updatedFilters();
    }

    /** Tarayıcı konumu (Alpine → Livewire). */
    public function useMyLocation(float $lat, float $lng): void
    {
        $this->filters['near_lat'] = $lat;
        $this->filters['near_lng'] = $lng;
        $this->filters['near_label'] = 'Konumum';
        $this->filters['near_radius_km'] = $this->filters['near_radius_km'] ?: 50;
        $this->updatedFilters();
    }

    public function useProvinceCenter($code): void
    {
        $province = TurkishLocations::province((int) $code);
        if (! $province) {
            return;
        }
        $this->filters['near_lat'] = $province['lat'];
        $this->filters['near_lng'] = $province['lng'];
        $this->filters['near_label'] = $province['name'];
        $this->filters['near_radius_km'] = $this->filters['near_radius_km'] ?: 50;
        $this->updatedFilters();
    }

    public function clearNear(): void
    {
        $this->filters['near_radius_km'] = null;
        $this->filters['near_lat'] = null;
        $this->filters['near_lng'] = null;
        $this->filters['near_label'] = '';
        if ($this->filters['sort'] === 'distance') {
            $this->filters['sort'] = 'newest';
        }
        $this->updatedFilters();
    }

    public function openPresetModal(): void
    {
        $current = $this->presetId ? $this->presetsQuery()->whereKey($this->presetId)->first() : null;
        $this->presetName = $current?->name ?? '';
        $this->presetDefault = $current?->is_default ?? ($this->presetsQuery()->count() === 0);
        $this->resetErrorBag();
        $this->presetModalOpen = true;
    }

    public function savePreset(): void
    {
        $profile = $this->profile();
        if (! $profile) {
            return;
        }
        $this->validate(['presetName' => 'required|string|min:2|max:60'], ['presetName.required' => 'Filtreye bir ad verin.', 'presetName.min' => 'Ad en az 2 karakter olmalı.']);

        $filters = LoadFilterService::normalize($this->filters);
        $preset = $this->presetId ? $this->presetsQuery()->whereKey($this->presetId)->first() : null;
        if (! $preset && $this->presetsQuery()->count() >= 10) {
            $this->addError('presetName', 'En fazla 10 kalıcı filtre oluşturabilirsiniz.');

            return;
        }
        if ($this->presetDefault) {
            $this->presetsQuery()->update(['is_default' => false]);
        }
        if ($preset) {
            $preset->update(['name' => trim($this->presetName), 'is_default' => $this->presetDefault, 'filters' => $filters]);
        } else {
            $preset = DriverFilterPreset::create(['driver_profile_id' => $profile->id, 'name' => trim($this->presetName), 'is_default' => $this->presetDefault, 'filters' => $filters, 'last_used_at' => now()]);
        }
        $this->presetId = $preset->id;
        $this->presetModalOpen = false;
        session()->flash('success_message', $this->presetDefault ? 'Filtre kaydedildi ve varsayılan yapıldı; havuz her açılışta bu filtreyle gelir.' : 'Filtre kaydedildi.');
    }

    public function deletePreset(): void
    {
        if (! $this->presetId) {
            return;
        }
        $this->presetsQuery()->whereKey($this->presetId)->delete();
        $this->presetId = null;
        session()->flash('success_message', 'Kalıcı filtre silindi.');
        $this->resetPage();
    }

    public bool $offerModalOpen = false;

    #[Locked]
    public ?int $selectedLoadId = null;

    public string $amount = '';

    public string $estimated_days = '1';

    public string $message = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['pool', 'offers', 'external'], true) ? $tab : 'pool';
        $this->resetPage();
    }

    private function profile()
    {
        return Auth::user()->driverProfile;
    }

    /** Teklif verilebilir açık ilanlar: şoförün aktif teklifi olan ilanlar hariç. */
    private function poolQuery(): Builder
    {
        $profileId = $this->profile()?->id ?? 0;

        return Load::query()
            ->with('cargoOwnerProfile.user')
            ->where('status', Load::STATUS_ACTIVE)
            ->where('visibility', 'public')
            ->whereDoesntHave('offers', fn (Builder $q) => $q->where('driver_profile_id', $profileId)->whereIn('status', ['pending', 'accepted']))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w->where('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term));
            })
            ->tap(fn (Builder $q) => app(LoadFilterService::class)->applyToLoads($q, LoadFilterService::normalize($this->filters), $this->profile()));
    }

    private function externalQuery(): Builder
    {
        $premium = $this->profile()?->isPremium() ?? false;

        return ScrapedLoad::query()
            ->with('scraper')
            ->where('status', 'parsed_success')
            ->where('visibility', 'public')
            ->when(! $premium, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('available_to_free_at')->orWhere('available_to_free_at', '<=', now())))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w->where('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term));
            })
            ->tap(fn (Builder $q) => app(LoadFilterService::class)->applyToScraped($q, LoadFilterService::normalize($this->filters), $this->profile()));
    }

    public function openOffer(int $loadId): void
    {
        $load = $this->poolQuery()->whereKey($loadId)->first();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı veya artık teklif kabul etmiyor.');

            return;
        }

        $this->selectedLoadId = $load->id;
        $this->amount = $load->price !== null ? number_format((float) $load->price, 2, '.', '') : '';
        $this->estimated_days = '1';
        $this->message = '';
        $this->resetErrorBag();
        $this->offerModalOpen = true;
    }

    public function closeOffer(): void
    {
        $this->offerModalOpen = false;
        $this->selectedLoadId = null;
        $this->resetErrorBag();
    }

    public function submitOffer(OfferService $offers): void
    {
        $min = Settings::float('min_load_price');

        $this->validate([
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:99999999'],
            'estimated_days' => ['required', 'integer', 'min:1', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required' => 'Teklif tutarı zorunludur.',
            'amount.numeric' => 'Teklif tutarı sayısal olmalıdır.',
            'amount.min' => 'Teklif tutarı en az '.number_format($min, 2, ',', '.').' ₺ olmalıdır.',
            'estimated_days.min' => 'Tahmini süre en az 1 gün olmalıdır.',
            'estimated_days.max' => 'Tahmini süre en fazla 30 gün olabilir.',
        ]);

        $profile = $this->profile();
        $load = $this->selectedLoadId ? Load::query()->whereKey($this->selectedLoadId)->first() : null;

        if (! $profile || ! $load) {
            $this->addError('amount', 'İlan bulunamadı.');

            return;
        }

        try {
            $offers->submit($profile, $load, (float) $this->amount, trim($this->message) ?: null, (int) $this->estimated_days);
        } catch (\RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->closeOffer();
        $this->reset(['amount', 'estimated_days', 'message']);
        $this->tab = 'offers';
        $this->resetPage();
        session()->flash('success_message', 'Teklifiniz iletildi. Yük sahibi değerlendirdiğinde bilgilendirileceksiniz.');
    }

    public function withdrawOffer(OfferService $offers, int $offerId): void
    {
        $profile = $this->profile();
        $offer = Offer::query()->where('driver_profile_id', $profile?->id ?? 0)->whereKey($offerId)->first();

        if (! $profile || ! $offer) {
            session()->flash('error_message', 'Teklif bulunamadı.');

            return;
        }

        try {
            $offers->withdraw($offer, $profile);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Teklifiniz geri çekildi.');
    }

    public function with(): array
    {
        $profile = $this->profile();
        $profileId = $profile?->id ?? 0;

        $data = [
            'profile' => $profile,
            'kycApproved' => $profile?->isKycApproved() ?? false,
            'hasActiveVehicle' => $profile ? $profile->activeVehicle()->exists() : false,
            'isPremium' => $profile?->isPremium() ?? false,
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
            'presets' => $this->presetsQuery()->orderByDesc('is_default')->orderBy('name')->get(),
            'provinces' => TurkishLocations::provinces(),
            'normalizedFilters' => $normalized = LoadFilterService::normalize($this->filters),
            'filterChips' => LoadFilterService::chips($normalized),
            'activeFilterCount' => LoadFilterService::activeCount($normalized),
            'radii' => LoadFilterService::RADII,
            'sorts' => LoadFilterService::SORTS,
            'withinDays' => LoadFilterService::WITHIN_DAYS,
            'myVehicleType' => $profile?->activeVehicle()->value('vehicle_type'),
            'minPrice' => Settings::float('min_load_price'),
            'selectedLoad' => $this->selectedLoadId ? Load::query()->with('cargoOwnerProfile.user')->whereKey($this->selectedLoadId)->first() : null,
            'loads' => null,
            'offers' => null,
            'externalLoads' => null,
        ];

        if ($this->tab === 'offers') {
            $data['offers'] = Offer::query()->with('cargoLoad')->where('driver_profile_id', $profileId)->latest('id')->paginate(15);
        } elseif (! $data['kycApproved']) {
            // Belgeleri onaylanmamış sürücüye ilan içeriği ve iletişim bilgisi gösterilmez.
        } elseif ($this->tab === 'external') {
            $data['externalLoads'] = $this->externalQuery()->paginate(15);
        } else {
            $data['loads'] = $this->poolQuery()->paginate(15);
        }

        return $data;
    }
}; ?>

<div wire:poll.5s class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="page-title">İlan Havuzu ve Tekliflerim</h2>
            <p class="page-subtitle">Açık ilanlara teklif verin, tekliflerinizi takip edin ve dış kaynaklı ilanları inceleyin.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach(['pool' => 'İlan havuzu', 'offers' => 'Tekliflerim', 'external' => 'Dış kaynak ilanlar'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    class="px-4 py-2 rounded-xl font-bold border transition-colors {{ $tab === $key ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-white dark:bg-neutral-900 border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if(! $kycApproved && $tab !== 'offers')
        @php $kycStatus = $profile?->kyc_status ?? 'unsubmitted'; @endphp
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-8 sm:p-10 text-center space-y-4">
            <div class="mx-auto w-14 h-14 rounded-2xl flex items-center justify-center {{ $kycStatus === 'rejected' ? 'bg-rose-500/10 text-rose-500' : 'bg-brand-500/10 text-brand-500' }}">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h3 class="text-base font-black text-neutral-900 dark:text-white">
                @if($kycStatus === 'pending') Belgeleriniz onay bekliyor
                @elseif($kycStatus === 'rejected') Belgeleriniz reddedildi
                @else Belgelerinizi yükleyin
                @endif
            </h3>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-md mx-auto leading-relaxed">
                @if($kycStatus === 'pending')
                    Ekibimiz belgelerinizi inceliyor. Onaylandığında ilan havuzu, dış kaynak ilanlar ve iletişim bilgileri hesabınıza açılır; size bildirim göndeririz.
                @elseif($kycStatus === 'rejected')
                    Belgelerinizde düzeltilmesi gereken bir nokta var. Profil sayfasından yeniden yükleyin; onaylandığında ilanlara erişim açılır.
                @else
                    NavlunIQ'da ilanlar yalnız belgeleri doğrulanmış şoförlere gösterilir. Sürücü belgesi, SRC ve araç belgelerinizi yükleyin; inceleme genellikle aynı gün tamamlanır.
                @endif
            </p>
            <a href="{{ route('driver.profile.index') }}" wire:navigate class="btn-primary inline-flex px-6">{{ $kycStatus === 'pending' ? 'Belge durumunu gör' : 'Belgelere git' }}</a>
        </div>
    @elseif($kycApproved && ! $hasActiveVehicle)
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <span>Teklif verebilmek için en az bir aktif aracınız olmalı.</span>
            <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="shrink-0 font-bold underline">Araç ekle</a>
        </div>
    @endif


    @if($kycApproved && in_array($tab, ['pool', 'external'], true))
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-4 sm:p-5 space-y-4 text-xs">
            {{-- Kalıcı filtre profilleri --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 mr-1">Filtre</span>
                <button type="button" wire:click="applyPreset(null)" class="tab-pill {{ $presetId === null ? 'tab-pill-active' : '' }}">Serbest</button>
                @foreach($presets as $preset)
                    <button type="button" wire:click="applyPreset({{ $preset->id }})" class="tab-pill {{ $presetId === $preset->id ? 'tab-pill-active' : '' }}" title="{{ implode(' · ', \App\Services\LoadFilterService::chips(\App\Services\LoadFilterService::normalize((array) $preset->filters))) }}">
                        {{ $preset->name }}@if($preset->is_default) <span class="text-brand-500">★</span>@endif
                    </button>
                @endforeach
                <button type="button" wire:click="$toggle('advancedOpen')" class="ml-auto inline-flex items-center gap-1.5 font-bold text-brand-500 hover:underline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4"/></svg>
                    Gelişmiş filtreler @if($activeFilterCount > 0)<span class="badge bg-brand-500 text-white">{{ $activeFilterCount }}</span>@endif
                </button>
            </div>

            {{-- Hızlı satır --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label class="form-label">Rota ara</label>
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Şehir veya ilçe" class="form-input">
                </div>
                <div>
                    <label class="form-label">Çıkış ili ekle</label>
                    <select class="form-input" wire:change="addProvince('pickup', $event.target.value); $event.target.value = ''">
                        <option value="">Seçin…</option>
                        @foreach($provinces as $province)<option value="{{ $province['code'] }}">{{ $province['name'] }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Varış ili ekle</label>
                    <select class="form-input" wire:change="addProvince('delivery', $event.target.value); $event.target.value = ''">
                        <option value="">Seçin…</option>
                        @foreach($provinces as $province)<option value="{{ $province['code'] }}">{{ $province['name'] }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Sıralama</label>
                    <select wire:model.live="filters.sort" class="form-input">
                        @foreach($sorts as $key => $label)
                            <option value="{{ $key }}" @disabled($key === 'distance' && $normalizedFilters['near_radius_km'] === null)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Seçili il rozetleri ve yakınımda --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach($normalizedFilters['pickup_provinces'] as $code)
                    <span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">Çıkış: {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }} <button type="button" wire:click="removeProvince('pickup', {{ $code }})" class="ml-1 hover:text-rose-500" aria-label="Kaldır">×</button></span>
                @endforeach
                @foreach($normalizedFilters['delivery_provinces'] as $code)
                    <span class="badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">Varış: {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }} <button type="button" wire:click="removeProvince('delivery', {{ $code }})" class="ml-1 hover:text-rose-500" aria-label="Kaldır">×</button></span>
                @endforeach
                @if($normalizedFilters['near_radius_km'] !== null)
                    <span class="badge bg-sky-500/10 text-sky-700 dark:text-sky-400">{{ $normalizedFilters['near_label'] ?: 'Konumum' }} çevresi {{ $normalizedFilters['near_radius_km'] }} km <button type="button" wire:click="clearNear" class="ml-1 hover:text-rose-500" aria-label="Kaldır">×</button></span>
                @else
                    <button type="button"
                        x-data
                        @click="if (!navigator.geolocation) { alert('Tarayıcınız konum desteklemiyor.'); return; } $el.disabled = true; navigator.geolocation.getCurrentPosition(p => { $wire.useMyLocation(p.coords.latitude, p.coords.longitude); $el.disabled = false; }, () => { alert('Konum alınamadı. Telefonun konum izni açık olmalı.'); $el.disabled = false; }, { enableHighAccuracy: false, timeout: 10000 })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-dashed border-sky-400/60 text-sky-700 dark:text-sky-400 font-semibold hover:bg-sky-500/10 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        Yakınımdaki ilanlar
                    </button>
                @endif
                @if($activeFilterCount > 0 || $search !== '')
                    <button type="button" wire:click="clearFilters" class="text-neutral-500 hover:text-rose-500 font-semibold">Temizle</button>
                @endif
            </div>

            {{-- Gelişmiş panel --}}
            @if($advancedOpen)
                <div class="pt-4 border-t border-neutral-200 dark:border-neutral-800 space-y-5">
                    <div class="space-y-2">
                        <label class="form-label">Araç tipi</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="$set('filters.vehicle_mode', 'mine')" class="tab-pill {{ $normalizedFilters['vehicle_mode'] === 'mine' ? 'tab-pill-active' : '' }}">Aracıma uygun{{ $myVehicleType ? ' ('.\App\Support\VehicleTypes::label($myVehicleType).' ve altı)' : '' }}</button>
                            <button type="button" wire:click="$set('filters.vehicle_mode', 'any')" class="tab-pill {{ $normalizedFilters['vehicle_mode'] === 'any' ? 'tab-pill-active' : '' }}">Tüm tipler</button>
                            <button type="button" wire:click="$set('filters.vehicle_mode', 'custom')" class="tab-pill {{ $normalizedFilters['vehicle_mode'] === 'custom' ? 'tab-pill-active' : '' }}">Seçtiklerim</button>
                        </div>
                        @if($normalizedFilters['vehicle_mode'] === 'custom')
                            <div class="flex flex-wrap gap-2 pt-1">
                                @foreach(\App\Support\VehicleTypes::FORM_ORDER as $type)
                                    <button type="button" wire:click="toggleVehicleType('{{ $type }}')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-[11px] font-semibold transition-colors {{ in_array($type, $normalizedFilters['vehicle_types'], true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">{!! \App\Support\VehicleTypes::iconPath($type) !!}</svg>{{ \App\Support\VehicleTypes::label($type) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="form-label">En az tonaj (kg)</label>
                            <input type="number" inputmode="numeric" min="0" step="100" wire:model.live.debounce.500ms="filters.min_weight" class="form-input" placeholder="0">
                        </div>
                        <div>
                            <label class="form-label">En fazla tonaj (kg)</label>
                            <input type="number" inputmode="numeric" min="0" step="100" wire:model.live.debounce.500ms="filters.max_weight" class="form-input" placeholder="Sınırsız">
                        </div>
                        <div>
                            <label class="form-label">En az fiyat (₺)</label>
                            <input type="number" inputmode="numeric" min="0" step="500" wire:model.live.debounce.500ms="filters.min_price" class="form-input" placeholder="0">
                        </div>
                        <div>
                            <label class="form-label">Yükleme zamanı</label>
                            <select wire:model.live="filters.pickup_within_days" class="form-input">
                                @foreach($withinDays as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <div>
                            <label class="form-label">Yük türü anahtar sözcükleri</label>
                            <input type="text" wire:model.live.debounce.500ms="filters.goods_keywords" placeholder="palet, koli, frigo, dökme…" class="form-input">
                            <span class="form-help">Virgülle ayırın; biri geçen ilanlar listelenir.</span>
                        </div>
                        <div class="space-y-2">
                            <label class="form-label">Yakınımda (çıkış noktası)</label>
                            <div class="flex flex-wrap gap-2 items-center">
                                <select wire:model.live="filters.near_radius_km" class="form-input w-auto">
                                    <option value="">Kapalı</option>
                                    @foreach($radii as $km)<option value="{{ $km }}">{{ $km }} km</option>@endforeach
                                </select>
                                <select class="form-input w-auto" wire:change="useProvinceCenter($event.target.value); $event.target.value = ''">
                                    <option value="">İl merkezi seç…</option>
                                    @foreach($provinces as $province)<option value="{{ $province['code'] }}">{{ $province['name'] }}</option>@endforeach
                                </select>
                                <label class="inline-flex items-center gap-2 text-[11px] text-neutral-500"><input type="checkbox" wire:model.live="filters.only_priced" class="accent-brand-500 w-4 h-4"> Yalnız fiyatlı ilanlar</label>
                            </div>
                            <span class="form-help">Merkez olarak konumunuzu ("Yakınımdaki ilanlar") ya da bir il merkezini kullanın.</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                        <button type="button" wire:click="openPresetModal" class="btn-primary py-2 text-xs">{{ $presetId ? 'Bu filtreyi güncelle' : 'Bu filtreyi kaydet' }}</button>
                        @if($presetId)
                            <button type="button" wire:click="deletePreset" wire:confirm="Bu kalıcı filtre silinecek. Devam edilsin mi?" class="btn-danger py-2 text-xs">Sil</button>
                        @endif
                        <span class="text-[11px] text-neutral-400 ml-auto">Kaydedilen filtreler telefonda ve bilgisayarda aynı çalışır; varsayılan filtre havuz her açılışta uygulanır.</span>
                    </div>
                </div>
            @endif

            @if($filterChips !== [])
                <div class="flex flex-wrap gap-1.5 text-[11px] text-neutral-500">
                    @foreach($filterChips as $chip)<span class="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800">{{ $chip }}</span>@endforeach
                </div>
            @endif
        </div>

        @if($presetModalOpen)
            <div class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('presetModalOpen', false)"></div>
                <form wire:submit.prevent="savePreset" class="relative z-10 w-full max-w-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-xs">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">{{ $presetId ? 'Kalıcı filtreyi güncelle' : 'Kalıcı filtre kaydet' }}</h3>
                    <div>
                        <label class="form-label">Filtre adı</label>
                        <input type="text" wire:model="presetName" placeholder="Örn. Ankara çıkışlı tır yükleri" class="form-input" autofocus>
                        @error('presetName') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-[11px] text-neutral-500">
                        @foreach($filterChips as $chip)<span class="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800">{{ $chip }}</span>@endforeach
                    </div>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="presetDefault" class="accent-brand-500 w-4 h-4"> Varsayılan filtrem olsun (havuz her açılışta bununla gelsin)</label>
                    <div class="flex gap-3 pt-2">
                        <button type="button" wire:click="$set('presetModalOpen', false)" class="btn-secondary flex-1">Vazgeç</button>
                        <button type="submit" class="btn-primary flex-1">Kaydet</button>
                    </div>
                </form>
            </div>
        @endif
    @endif

    @if($kycApproved && $tab === 'pool')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="space-y-3">
                @forelse($loads as $load)
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 hover:border-brand-500/40 rounded-xl transition-colors flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                        <div class="space-y-1.5 flex-1">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">
                                {{ $load->goods_type }} · {{ $vehicleTypes[$load->vehicle_type] ?? $load->vehicle_type }}
                                @if($load->weight) · {{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg @endif
                                @if($load->volume) · {{ number_format((int) ($load->volume ?? 0), 0, ',', '.') }} m³ @endif
                            </div>
                            <div class="text-neutral-500">
                                Yükleme: {{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}
                                @if($load->delivery_date) · Teslim: {{ $load->delivery_date->format('d.m.Y H:i') }} @endif
                                · Yük sahibi: {{ $load->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}
                            </div>
                        </div>
                        <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                            <div class="text-lg font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</div>
                            <button type="button" wire:click="openOffer({{ $load->id }})" class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">Teklif ver</button>
                        </div>
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Filtrelerinize uyan açık ilan bulunmuyor.</div>
                @endforelse
            </div>

            @if($loads && $loads->hasPages())
                <div class="pt-2">{{ $loads->links() }}</div>
            @endif
        </div>
    @endif

    @if($tab === 'offers')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
            @forelse($offers as $offer)
                @php $offerLoad = $offer->cargoLoad; @endphp
                <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                    <div class="space-y-1.5 flex-1">
                        <div class="text-sm font-bold text-neutral-900 dark:text-white">
                            @if($offerLoad)
                                {{ $offerLoad->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $offerLoad->delivery_location }}
                            @else
                                İlan kaldırılmış
                            @endif
                        </div>
                        <div class="text-neutral-500 dark:text-neutral-400">
                            Teklifim: <span class="text-neutral-900 dark:text-white tabular-nums font-bold">{{ number_format((float) ($offer->amount ?? 0), 2, ',', '.') }} ₺</span>
                            @if($offer->estimated_days) · {{ (int) $offer->estimated_days }} gün @endif
                            · Verildi: {{ $offer->created_at?->format('d.m.Y H:i') }}
                        </div>
                        <div class="text-neutral-500">
                            @if($offer->status === 'pending' && $offer->expires_at)
                                Geçerlilik: {{ $offer->expires_at->format('d.m.Y H:i') }} tarihine kadar
                            @elseif($offer->responded_at)
                                Sonuçlandı: {{ $offer->responded_at->format('d.m.Y H:i') }}
                            @endif
                            @if($offerLoad) · İlan durumu: {{ $offerLoad->statusLabel() }} @endif
                        </div>
                    </div>
                    <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border
                            {{ $offer->status === 'accepted' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($offer->status === 'pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300') }}">
                            {{ \App\Models\Offer::STATUS_LABELS[$offer->status] ?? $offer->status }}
                        </span>
                        @if($offer->status === 'pending')
                            <button type="button" wire:click="withdrawOffer({{ $offer->id }})" wire:confirm="Teklifinizi geri çekmek istediğinize emin misiniz?" class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-bold">Geri çek</button>
                        @elseif($offer->status === 'accepted' && $offerLoad)
                            <a href="{{ route('driver.shipments.show', $offerLoad->id) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Sevkiyata git</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz teklif vermediniz.</div>
            @endforelse

            @if($offers && $offers->hasPages())
                <div class="pt-2">{{ $offers->links() }}</div>
            @endif
        </div>
    @endif

    @if($kycApproved && $tab === 'external')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="text-xs">
                <div class="text-[11px] text-neutral-500 leading-relaxed">
                    Bu ilanlar izinli dış kaynaklardan derlenir; NavlunIQ havuz ödemesi kapsamında değildir. Teklif ve anlaşma doğrudan ilan sahibiyle yapılır.
                    @if(! $isPremium)
                        Yeni dış kaynak ilanlar önce premium üyelere açılır; telefon numarasının tamamı premium üyelere gösterilir.
                        <a href="{{ route('driver.premium.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Premium</a>
                    @endif
                </div>
            </div>

            <div class="space-y-3">
                @forelse($externalLoads as $item)
                    @php $plainPhone = $item->plainPhone(); $fullPhone = $isPremium && $plainPhone ? \App\Support\Phone::format($plainPhone) : null; @endphp
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                        <div class="space-y-1.5 flex-1">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">
                                @if($item->isUrgent())<span class="badge bg-red-500 text-white mr-1 align-middle">ACİL</span>@endif
                                {{ $item->pickup_location ?: 'Belirtilmemiş' }} <span class="text-amber-600 dark:text-amber-400">&rarr;</span> {{ $item->delivery_location ?: 'Belirtilmemiş' }}
                            </div>
                            <div class="text-neutral-600 dark:text-neutral-300 font-medium">
                                {{ $item->goods_type ?: 'Yük türü belirtilmemiş' }}@if($item->weightLabel()) · {{ $item->weightLabel() }}@endif@if($item->meta('pickup_note')) · Yükleme: {{ $item->meta('pickup_note') }}@endif
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if($item->vehicle_type)
                                    <span class="badge bg-neutral-900 dark:bg-white text-white dark:text-neutral-900 inline-flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">{!! \App\Support\VehicleTypes::iconPath($item->vehicle_type) !!}</svg>
                                        {{ $item->vehicleLabel() }}
                                    </span>
                                @endif
                                @foreach($item->traitLabels() as $trait)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trait }}</span>@endforeach
                                <span class="badge bg-amber-500/10 text-amber-700 dark:text-amber-400">WhatsApp grubu</span>
                                @if((int) $item->duplicate_count > 1)
                                    <span class="badge bg-amber-500 text-white" title="{{ implode(', ', (array) $item->seen_sources) }}">{{ $item->duplicate_count }} grupta paylaşıldı</span>
                                @endif
                            </div>
                            <div class="text-neutral-500">{{ $item->created_at?->diffForHumans() }}</div>
                        </div>
                        <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                            <div class="text-neutral-900 dark:text-white tabular-nums font-bold">
                                @if($item->price !== null)
                                    {{ number_format((float) $item->price, 2, ',', '.') }} ₺
                                @else
                                    Fiyat belirtilmemiş
                                @endif
                            </div>
                            @if($fullPhone)
                                                                <div class="flex items-center gap-3">
                                    <a href="tel:+90{{ $plainPhone }}" class="text-brand-400 tabular-nums font-bold hover:underline">{{ $fullPhone }}</a>
                                    <a href="https://wa.me/90{{ $plainPhone }}" target="_blank" rel="noopener" class="text-emerald-600 dark:text-emerald-400 font-bold hover:underline">WhatsApp</a>
                                </div>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400 tabular-nums">{{ $plainPhone ? '0'.substr($plainPhone, 0, 3).' *** ** '.substr($plainPhone, -2) : 'Bilinmiyor' }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Şu anda görüntülenebilir dış kaynak ilan yok.</div>
                @endforelse
            </div>

            @if($externalLoads && $externalLoads->hasPages())
                <div class="pt-2">{{ $externalLoads->links() }}</div>
            @endif
        </div>
    @endif

    @if($offerModalOpen && $selectedLoad)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeOffer"></div>
            <form wire:submit.prevent="submitOffer" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Teklif ver</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $selectedLoad->pickup_location }} &rarr; {{ $selectedLoad->delivery_location }} · İlan fiyatı {{ number_format((float) ($selectedLoad->price ?? 0), 2, ',', '.') }} ₺</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Teklif tutarı (₺)</label>
                        <input type="number" step="0.01" min="{{ $minPrice }}" wire:model="amount" class="form-input tabular-nums">
                        @error('amount') <span class="form-error">{{ $message }}</span> @enderror
                        <span class="text-[11px] text-neutral-500 mt-1 block">Asgari {{ number_format($minPrice, 2, ',', '.') }} ₺</span>
                    </div>
                    <div>
                        <label class="form-label">Tahmini süre (gün)</label>
                        <input type="number" min="1" max="30" wire:model="estimated_days" class="form-input tabular-nums">
                        @error('estimated_days') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="form-label">Mesaj (isteğe bağlı)</label>
                    <textarea wire:model="message" rows="3" maxlength="1000" class="form-input"></textarea>
                    @error('message') <span class="form-error">{{ $message }}</span> @enderror
                </div>

                @if(! $kycApproved)
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300">
                        Teklif verebilmek için belgelerinizin onaylanmış olması gerekir.
                        <a href="{{ route('driver.profile.index') }}" wire:navigate class="font-bold underline">Belgeleri yükle</a>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" wire:click="closeOffer" class="btn-secondary flex-1">Kapat</button>
                    </div>
                @else
                    @if(! $hasActiveVehicle)
                        <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300">
                            Teklif verebilmek için aktif bir aracınız olmalı.
                            <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="font-bold underline">Araç ekle</a>
                        </div>
                    @endif
                    <div class="flex gap-3 pt-2">
                        <button type="button" wire:click="closeOffer" class="btn-secondary flex-1">Vazgeç</button>
                        <button type="submit" class="btn-primary flex-1" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="submitOffer">Teklifi gönder</span>
                            <span wire:loading wire:target="submitOffer">Gönderiliyor...</span>
                        </button>
                    </div>
                @endif
            </form>
        </div>
    @endif
</div>
