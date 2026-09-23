<?php

use App\Models\DriverFilterPreset;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Services\DriverTripService;
use App\Services\LoadFilterService;
use App\Services\OfferService;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\BodyTypes;
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

    /** "Bu işi aldım" penceresi (dış kaynak ilanı) */
    public bool $takeModalOpen = false;

    #[Locked]
    public ?int $takeLoadId = null;

    public string $takePickupDate = '';

    public string $takeDeliveryDate = '';

    public bool $takeNotify = true;

    public function mount(): void
    {
        $this->tab = in_array(request()->query('tab'), ['pool', 'offers', 'external', 'saved'], true) ? request()->query('tab') : 'pool';
        $this->filters = LoadFilterService::defaults();

        $requested = (int) request()->query('preset', 0);
        $preset = $requested > 0
            ? $this->presetsQuery()->whereKey($requested)->first()
            : $this->presetsQuery()->where('is_default', true)->first();
        if ($preset) {
            $this->applyPreset($preset->id);
        }
        // Bildirimden / Telegram'dan gelen "?ilan=" bağlantısı: ilan görünürse teklif penceresi açılır.
        if (($ilan = (int) request()->query('ilan', 0)) > 0 && $this->tab === 'pool') {
            $this->openOffer($ilan);
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
        unset($this->filters[$side === 'delivery' ? 'delivery_districts' : 'pickup_districts'][$code]);
        $this->updatedFilters();
    }

    /** Kutudaki il etiketi: seçiliyse kaldırır, değilse ekler. */
    public function toggleProvince(string $side, int $code): void
    {
        $key = $side === 'delivery' ? 'delivery_provinces' : 'pickup_provinces';
        in_array($code, array_map('intval', $this->filters[$key] ?? []), true) ? $this->removeProvince($side, $code) : $this->addProvince($side, $code);
    }

    /** Seçili ilin ilçe etiketi: seçiliyse kaldırır, değilse ekler; hiç ilçe kalmazsa ilin tamamı. */
    public function toggleDistrict(string $side, int $code, string $name): void
    {
        $key = $side === 'delivery' ? 'delivery_districts' : 'pickup_districts';
        $current = (array) ($this->filters[$key][$code] ?? []);
        $this->filters[$key][$code] = in_array($name, $current, true) ? array_values(array_diff($current, [$name])) : [...$current, $name];
        $this->updatedFilters();
    }

    /** "Her yer": o yöndeki il ve ilçe seçimlerini temizler. */
    public function clearSide(string $side): void
    {
        $this->filters[$side === 'delivery' ? 'delivery_provinces' : 'pickup_provinces'] = [];
        $this->filters[$side === 'delivery' ? 'delivery_districts' : 'pickup_districts'] = [];
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

    public function toggleBodyType(string $type): void
    {
        if (! BodyTypes::isValid($type)) {
            return;
        }
        $types = $this->filters['body_types'] ?? [];
        $this->filters['body_types'] = in_array($type, $types, true) ? array_values(array_diff($types, [$type])) : [...$types, $type];
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
        $this->tab = in_array($tab, ['pool', 'offers', 'external', 'saved'], true) ? $tab : 'pool';
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
            ->openTo($this->profile())
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
            ->when(! $premium, fn (Builder $q) => $q->whereRaw('1 = 0')) // dış kaynak ilanları yalnız premium üyelere görünür
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

    /** Kaydet / kaydı kaldır (yıldız). */
    public function toggleSave(string $kind, int $id): void
    {
        $profile = $this->profile();
        if (! $profile || ! $profile->isKycApproved()) {
            return;
        }
        $column = $kind === 'external' ? 'scraped_load_id' : 'load_id';
        if ($kind === 'external' && ! $profile->isPremium()) {
            return;
        }
        $existing = DriverSavedLoad::query()->where('driver_profile_id', $profile->id)->where($column, $id)->first();
        if ($existing) {
            $existing->delete();

            return;
        }
        $exists = $kind === 'external'
            ? ScrapedLoad::query()->whereKey($id)->where('visibility', 'public')->exists()
            : Load::query()->whereKey($id)->where('visibility', 'public')->exists();
        if ($exists) {
            DriverSavedLoad::create(['driver_profile_id' => $profile->id, $column => $id]);
        }
    }

    public function openTake(int $scrapedId): void
    {
        $profile = $this->profile();
        if (! $profile?->isPremium() || ! ScrapedLoad::query()->whereKey($scrapedId)->where('visibility', 'public')->exists()) {
            session()->flash('error_message', 'İlan bulunamadı.');

            return;
        }
        $this->takeLoadId = $scrapedId;
        $this->takePickupDate = now()->toDateString();
        $this->takeDeliveryDate = now()->addDay()->toDateString();
        $this->takeNotify = true;
        $this->resetErrorBag();
        $this->takeModalOpen = true;
    }

    public function closeTake(): void
    {
        $this->takeModalOpen = false;
        $this->takeLoadId = null;
        $this->resetErrorBag();
    }

    public function submitTake(DriverTripService $trips): void
    {
        $this->validate([
            'takePickupDate' => ['required', 'date'],
            'takeDeliveryDate' => ['required', 'date', 'after_or_equal:takePickupDate'],
        ], ['takeDeliveryDate.after_or_equal' => 'Teslim tarihi yükleme tarihinden önce olamaz.']);
        $profile = $this->profile();
        $load = $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null;
        if (! $profile || ! $load) {
            $this->closeTake();

            return;
        }
        try {
            $trips->takeExternal($profile, $load, \Illuminate\Support\Carbon::parse($this->takePickupDate), \Illuminate\Support\Carbon::parse($this->takeDeliveryDate), $this->takeNotify);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
            $this->closeTake();

            return;
        }
        $this->closeTake();
        session()->flash('success_message', 'Sefer kaydedildi. '.($this->takeNotify ? 'Varış yerinizin çevresinden çıkan yeni ilanlar size bildirilecek.' : 'Seferlerim sayfasından takip edebilirsiniz.'));
    }

    public function with(): array
    {
        $profile = $this->profile();
        $profileId = $profile?->id ?? 0;
        $saved = DriverSavedLoad::query()->where('driver_profile_id', $profileId)->get();

        $data = [
            'profile' => $profile,
            'kycApproved' => $profile?->isKycApproved() ?? false,
            'hasActiveVehicle' => $profile ? $profile->activeVehicle()->exists() : false,
            'isPremium' => $profile?->isPremium() ?? false,
            'freeDelay' => app(\App\Services\LoadReleaseService::class)->delayMinutes(),
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
            'myVehicleBody' => $profile?->activeVehicle()->value('body_type'),
            'myVehicleLength' => $profile?->activeVehicle()->value('trailer_length'),
            'minPrice' => Settings::float('min_load_price'),
            'selectedLoad' => $this->selectedLoadId ? Load::query()->with('cargoOwnerProfile.user')->whereKey($this->selectedLoadId)->first() : null,
            'loads' => null,
            'offers' => null,
            'savedSystemIds' => $saved->pluck('load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'savedExternalIds' => $saved->pluck('scraped_load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'takenExternalIds' => DriverTrip::query()->where('driver_profile_id', $profileId)->open()->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all(),
            'savedItems' => null,
            'takeLoad' => $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null,
            'externalLoads' => null, 'webLoadsCount' => ScrapedLoad::query()->where('status', 'parsed_success')->where('visibility', 'public')->count(),
        ];

        if ($this->tab === 'offers') {
            $data['offers'] = Offer::query()->with('cargoLoad')->where('driver_profile_id', $profileId)->latest('id')->paginate(15);
        } elseif (! $data['kycApproved']) {
            // Belgeleri onaylanmamış sürücüye ilan içeriği ve iletişim bilgisi gösterilmez.
        } elseif ($this->tab === 'external') {
            $data['externalLoads'] = $this->externalQuery()->paginate(15);
        } elseif ($this->tab === 'saved') {
            $data['savedItems'] = DriverSavedLoad::query()->with(['cargoLoad.cargoOwnerProfile.user', 'scrapedLoad'])
                ->where('driver_profile_id', $profileId)->latest('id')->get()
                ->filter(fn (DriverSavedLoad $s) => $s->kind() === 'external' ? ($s->scrapedLoad && $data['isPremium']) : (bool) $s->cargoLoad)->values();
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
            @foreach(['pool' => 'İlan havuzu', 'offers' => 'Tekliflerim', 'external' => 'Dış kaynak ilanlar', 'saved' => 'Kaydettiklerim'] as $key => $label)
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
                    Ekibimiz belgelerinizi inceliyor. Onaylandığında ilan havuzu ve teklif verme hesabınıza açılır; size bildirim göndeririz. Dış kaynak ilanları premium üyelere özeldir.
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
                @foreach(['pickup' => 'Çıkış', 'delivery' => 'Varış'] as $side => $sideLabel)
                    @php $selCodes = array_map('intval', $normalizedFilters[$side.'_provinces']); $selDistricts = $normalizedFilters[$side.'_districts']; @endphp
                    <div x-data="{ open: false, q: '' }" @click.outside="open = false" class="relative">
                        <label class="form-label">{{ $sideLabel }} <span class="text-neutral-400 font-normal">(il ve ilçe; boş = her yer)</span></label>
                        {{-- Seçim kutusu: seçilenler kutunun içinde etiket olarak görünür; boşsa "Her yer" --}}
                        <button type="button" @click="open = !open" class="form-input min-h-[42px] flex flex-wrap items-center gap-1.5 text-left cursor-pointer" :aria-expanded="open" aria-label="{{ $sideLabel }} yeri seç">
                            @forelse($selCodes as $code)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-semibold {{ $side === 'pickup' ? 'bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' }}">
                                    {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }}@if(($selDistricts[$code] ?? []) !== []) <span class="font-normal opacity-80">({{ count($selDistricts[$code]) }} ilçe)</span>@endif
                                    <span role="button" wire:click.stop="removeProvince('{{ $side }}', {{ $code }})" class="ml-0.5 hover:text-rose-500" aria-label="Kaldır">×</span>
                                </span>
                            @empty
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[11px] font-semibold bg-neutral-100 dark:bg-neutral-800 text-neutral-500">Her yer</span>
                            @endforelse
                            <svg class="w-4 h-4 ml-auto text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        {{-- Açılır il listesi: etiketler, arama, "Her yer" --}}
                        <div x-show="open" x-cloak x-transition.opacity class="absolute z-30 mt-1 w-full sm:w-[28rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-apple-lg p-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="q" placeholder="İl ara" class="form-input py-1.5 text-xs flex-1" autocomplete="off">
                                <button type="button" wire:click="clearSide('{{ $side }}')" class="tab-pill {{ $selCodes === [] ? 'tab-pill-active' : '' }}">Her yer</button>
                            </div>
                            <div class="max-h-56 overflow-y-auto flex flex-wrap gap-1.5 pr-1">
                                @foreach($provinces as $province)
                                    <button type="button" wire:click="toggleProvince('{{ $side }}', {{ $province['code'] }})" x-show="!q || {{ json_encode(\App\Support\TurkishText::lower($province['name'])) }}.includes(q.toLocaleLowerCase('tr'))"
                                        class="px-2.5 py-1 rounded-lg border text-[11px] font-semibold transition-colors {{ in_array((int) $province['code'], $selCodes, true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">{{ $province['name'] }}</button>
                                @endforeach
                            </div>
                        </div>
                        {{-- Seçili illerin ilçeleri: etiket olarak, birden çok seçilir; hiçbiri seçili değilse ilin tamamı --}}
                        @foreach($selCodes as $code)
                            @php $districtNames = \App\Support\TurkishLocations::districtsOf($code); $picked = $selDistricts[$code] ?? []; @endphp
                            @if($districtNames !== [])
                                <details class="mt-2 group" @if($picked !== []) open @endif>
                                    <summary class="cursor-pointer text-[11px] font-semibold text-neutral-600 dark:text-neutral-300 list-none flex items-center gap-1">
                                        <svg class="w-3 h-3 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }} ilçeleri{{ $picked !== [] ? ': '.implode(', ', $picked) : ' (tümü)' }}
                                    </summary>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach($districtNames as $dn)
                                            <button type="button" wire:click="toggleDistrict('{{ $side }}', {{ $code }}, @js($dn))" class="px-2 py-0.5 rounded-lg border text-[11px] transition-colors {{ in_array($dn, $picked, true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400 font-semibold' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">{{ $dn }}</button>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        @endforeach
                    </div>
                @endforeach
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
                            <button type="button" wire:click="$set('filters.vehicle_mode', 'mine')" class="tab-pill {{ $normalizedFilters['vehicle_mode'] === 'mine' ? 'tab-pill-active' : '' }}">Aracıma uygun{{ $myVehicleType ? ' ('.implode(' · ', array_filter([\App\Support\VehicleTypes::label($myVehicleType).' ve altı', $myVehicleLength ? \App\Support\BodyTypes::TRAILER_LENGTHS[$myVehicleLength] ?? null : null, $myVehicleBody ? \App\Support\BodyTypes::label($myVehicleBody) : null])).')' : '' }}</button>
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

                    <div class="space-y-2">
                        <label class="form-label">Kasa tipi <span class="text-neutral-400 font-normal">(kasa belirtmeyen ilanlar her zaman görünür)</span></label>
                        <div class="flex flex-wrap gap-2">
                            @foreach(\App\Support\BodyTypes::TYPES as $bk => $bmeta)
                                <button type="button" wire:click="toggleBodyType('{{ $bk }}')" class="inline-flex items-center px-3 py-1.5 rounded-xl border text-[11px] font-semibold transition-colors {{ in_array($bk, $normalizedFilters['body_types'], true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">{{ $bmeta['label'] }}</button>
                            @endforeach
                        </div>
                        @if($normalizedFilters['vehicle_mode'] === 'mine' && $myVehicleType && ! $myVehicleBody)
                            <p class="text-[11px] text-amber-600">Aracınızın kasa tipi kayıtlı değil; <a href="{{ route('driver.vehicles.index') }}" class="underline" wire:navigate>Araçlarım</a> sayfasından ekleyin, ilanlar kasanıza göre süzülsün.</p>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <label class="form-label">Yük tipi</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="$set('filters.load_kind', '')" class="tab-pill {{ $normalizedFilters['load_kind'] === '' ? 'tab-pill-active' : '' }}">Fark etmez</button>
                            @foreach(\App\Support\BodyTypes::LOAD_KINDS as $lk => $ll)
                                <button type="button" wire:click="$set('filters.load_kind', '{{ $lk }}')" class="tab-pill {{ $normalizedFilters['load_kind'] === $lk ? 'tab-pill-active' : '' }}">{{ $ll }}</button>
                            @endforeach
                        </div>
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
            @if(! $isPremium)
                <div class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Yeni ilanlar önce premium üyelere açılır; {{ $freeDelay }} dakika sonra burada görünür.
                    <a href="{{ route('driver.premium.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Premium ile anında görün</a>
                </div>
            @endif
            <div class="space-y-3">
                @forelse($loads as $load)
                    @php $kg = (int) ($load->weight ?? 0); $vol = (int) ($load->volume ?? 0); $lp = (float) ($load->price ?? 0); @endphp
                    <div class="load-card">
                        <div class="load-card-main">
                            <div class="load-card-title">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                            <div class="load-card-line">{{ $load->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ \App\Support\VehicleTypes::label($load->vehicle_type) }}@if($load->bodyLabel()) · {{ $load->bodyLabel() }}@endif@if($load->loadKindLabel()) · {{ $load->loadKindLabel() }}@endif@if($kg > 0) · {{ $kg >= 1000 ? rtrim(rtrim(number_format($kg / 1000, 1, ',', '.'), '0'), ',').' ton' : number_format($kg, 0, ',', '.').' kg' }}@endif@if($vol > 0) · {{ number_format($vol, 0, ',', '.') }} m³@endif</div>
                            <div class="load-card-line">Yükleme: {{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}@if($load->delivery_date) · Teslim: {{ $load->delivery_date->format('d.m.Y H:i') }}@endif · {{ $load->cargoOwnerProfile?->displayName() ?: 'Yük sahibi belirtilmemiş' }}</div>
                            <div class="load-card-badges">
                                <span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">Sistem ilanı</span>
                                @if($load->isEarlyAccess())<span class="badge bg-amber-500/10 text-amber-700 dark:text-amber-400" title="Herkese {{ $load->available_to_free_at->format('H:i') }}'de açılır">⭐ Erken erişim</span>@endif
                            </div>
                        </div>
                        <div class="load-card-side">
                            <div class="load-card-price">{{ number_format($lp, fmod($lp, 1.0) === 0.0 ? 0 : 2, ',', '.') }} ₺</div>
                            <div class="flex items-center gap-1.5">
                                @php $isSaved = in_array($load->id, $savedSystemIds, true); @endphp
                                <button type="button" wire:click="toggleSave('system', {{ $load->id }})" class="load-card-star {{ $isSaved ? 'load-card-star-on' : '' }}" title="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-label="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg>
                                </button>
                                <button type="button" wire:click="openOffer({{ $load->id }})" class="load-card-action">Teklif ver</button>
                            </div>
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

    @if($kycApproved && $tab === 'saved')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="text-[11px] text-neutral-500 leading-relaxed">Yıldızladığınız ilanlar burada durur. Kaldırmak için yıldıza yeniden basın. İlan yayından kalkınca listeden düşer.</div>
            <div class="space-y-3">
                @forelse($savedItems as $saved)
                    @if($saved->kind() === 'system')
                        @php $load = $saved->cargoLoad; $lp = (float) ($load->price ?? 0); $stillOpen = $load->status === \App\Models\Load::STATUS_ACTIVE; @endphp
                        <div class="load-card" wire:key="saved-s-{{ $load->id }}">
                            <div class="load-card-main">
                                <div class="load-card-title">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                                <div class="load-card-line">{{ $load->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($load->vehicle_type), $load->bodyLabel(), $load->loadKindLabel()])) }}</div>
                                <div class="load-card-line">Yükleme: {{ $load->pickup_date?->format('d.m.Y') ?? 'Belirtilmemiş' }} · Kaydedildi: {{ $saved->created_at?->diffForHumans() }}</div>
                                <div class="load-card-badges"><span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">Sistem ilanı</span>@if(! $stillOpen)<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">{{ $load->statusLabel() }}</span>@endif</div>
                            </div>
                            <div class="load-card-side">
                                <div class="load-card-price">{{ number_format($lp, fmod($lp, 1.0) === 0.0 ? 0 : 2, ',', '.') }} ₺</div>
                                <div class="flex items-center gap-1.5">
                                    <button type="button" wire:click="toggleSave('system', {{ $load->id }})" class="load-card-star load-card-star-on" title="Kaydedilenlerden çıkar" aria-label="Kaydedilenlerden çıkar"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg></button>
                                    @if($stillOpen)<button type="button" wire:click="openOffer({{ $load->id }})" class="load-card-action">Teklif ver</button>@endif
                                </div>
                            </div>
                        </div>
                    @else
                        @php $item = $saved->scrapedLoad; $phone = $item->plainPhone(); $isTaken = in_array($item->id, $takenExternalIds, true); @endphp
                        <div class="load-card" wire:key="saved-e-{{ $item->id }}">
                            <div class="load-card-main">
                                <div class="load-card-title">{{ $item->pickup_location ?: 'Belirtilmemiş' }} <span class="text-amber-600 dark:text-amber-400">&rarr;</span> {{ $item->delivery_location ?: 'Belirtilmemiş' }}</div>
                                <div class="load-card-line">{{ $item->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ $item->vehicleSummary() }}@if($item->weightLabel()) · {{ $item->weightLabel() }}@endif</div>
                                <div class="load-card-line">Yükleme: {{ $item->meta('pickup_note') ?: 'Belirtilmemiş' }} · Kaydedildi: {{ $saved->created_at?->diffForHumans() }}</div>
                                <div class="load-card-badges"><span class="badge bg-amber-500/10 text-amber-700 dark:text-amber-400">Gruptan derlendi</span>@if($item->isUrgent())<span class="badge bg-red-500 text-white">ACİL</span>@endif</div>
                            </div>
                            <div class="load-card-side">
                                <div class="load-card-price">{{ $item->priceLabel() ?: 'Fiyat belirtilmemiş' }}</div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button type="button" wire:click="toggleSave('external', {{ $item->id }})" class="load-card-star load-card-star-on" title="Kaydedilenlerden çıkar" aria-label="Kaydedilenlerden çıkar"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg></button>
                                    @if($phone)<a href="tel:+90{{ $phone }}" class="load-card-action-ghost tabular-nums">{{ \App\Support\Phone::format($phone) }}</a>@endif
                                    @if($isTaken)
                                        <a href="{{ route('driver.trips.index') }}" wire:navigate class="load-card-action-ghost text-emerald-700 dark:text-emerald-400 border-emerald-500/40">✓ Seferimde</a>
                                    @else
                                        <button type="button" wire:click="openTake({{ $item->id }})" class="load-card-action-ghost">Bu işi aldım</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz kaydettiğiniz ilan yok. İlan kartındaki yıldıza basarak buraya ekleyin.</div>
                @endforelse
            </div>
        </div>
    @endif

    @if($takeModalOpen && $takeLoad)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeTake"></div>
            <form wire:submit.prevent="submitTake" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Bu işi aldım</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $takeLoad->pickup_location ?: 'Belirtilmemiş' }} &rarr; {{ $takeLoad->delivery_location ?: 'Belirtilmemiş' }}@if($takeLoad->goods_type) · {{ $takeLoad->goods_type }}@endif</p>
                </div>
                <p class="text-neutral-600 dark:text-neutral-300 leading-relaxed">İlan sahibiyle anlaştıysanız seferinizi kaydedin. Teslim tarihinden itibaren <strong>{{ $takeLoad->delivery_location ?: 'varış yeriniz' }}</strong> çevresinden çıkan, aracınıza uyan yeni ilanlar size bildirilir; boş dönmezsiniz.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Yükleme tarihi</label>
                        <input type="date" wire:model="takePickupDate" class="form-input">
                        @error('takePickupDate') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="form-label">Tahmini teslim tarihi</label>
                        <input type="date" wire:model="takeDeliveryDate" class="form-input">
                        @error('takeDeliveryDate') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="takeNotify" class="rounded mt-0.5">
                    <span class="text-neutral-700 dark:text-neutral-200">Dönüş yükü çıkınca bana bildir <span class="text-neutral-400">(uygulama içi ve e-posta; Seferlerim'den kapatabilirsiniz)</span></span>
                </label>
                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="closeTake" class="btn-secondary flex-1">Vazgeç</button>
                    <button type="submit" class="btn-primary flex-1" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitTake">Seferi kaydet</span>
                        <span wire:loading wire:target="submitTake">Kaydediliyor...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if($kycApproved && $tab === 'external' && ! $isPremium)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-8 text-center space-y-3">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-brand-500/10 text-brand-500 flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <h2 class="text-base font-bold text-neutral-900 dark:text-white">Dış kaynak ilanları premium üyelere özeldir</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-md mx-auto leading-relaxed">İzinli gruplardan ve web mecralarından derlenip ekibimizce onaylanan ilanlar, ilan sahibinin telefon numarasıyla birlikte yalnız premium üyelere gösterilir. Şu anda {{ $webLoadsCount ?? '' }} onaylı dış kaynak ilanı yayında.</p>
            <a href="{{ route('driver.premium.index') }}" wire:navigate class="btn-primary inline-flex py-2.5 px-5 text-xs">Premium'a geç</a>
        </div>
    @endif

    @if($kycApproved && $tab === 'external' && $isPremium)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="text-xs">
                <div class="text-[11px] text-neutral-500 leading-relaxed">
                    Bu ilanlar izinli dış kaynaklardan derlenir; NavlunIQ havuz ödemesi kapsamında değildir. Teklif ve anlaşma doğrudan ilan sahibiyle yapılır. Yalnız premium üyelere gösterilir.
                </div>
            </div>

            <div class="space-y-3">
                @forelse($externalLoads as $item)
                    @php $plainPhone = $item->plainPhone(); $extraPhones = $item->extraPhones(); @endphp
                    <div class="load-card">
                        <div class="load-card-main">
                            <div class="load-card-title">{{ $item->pickup_location ?: 'Belirtilmemiş' }} <span class="text-amber-600 dark:text-amber-400">&rarr;</span> {{ $item->delivery_location ?: 'Belirtilmemiş' }}</div>
                            <div class="load-card-line">{{ $item->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ $item->vehicleSummary() }}@if($item->weightLabel()) · {{ $item->weightLabel() }}@endif</div>
                            <div class="load-card-line">Yükleme: {{ $item->meta('pickup_note') ?: 'Belirtilmemiş' }} · {{ $item->created_at?->diffForHumans() }}</div>
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
                            @php $isSaved = in_array($item->id, $savedExternalIds, true); $isTaken = in_array($item->id, $takenExternalIds, true); @endphp
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
