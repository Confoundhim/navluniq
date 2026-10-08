<?php

use App\Models\DriverFilterPreset;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Livewire\Concerns\HandlesExternalLoadActions;
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
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('İlan Havuzu ve Tekliflerim')]
class extends Component {
    use HandlesExternalLoadActions, WithPagination;

    /** Sekme adreste taşınır: sayfa yenilenince ya da geri gelince şoför aynı sekmede kalır. */
    #[Url(as: 'tab', except: 'pool')]
    public string $tab = 'pool';

    /** Dış kaynak sekmesi: true = "Eksik bilgili ilanlar" bölümü (araç filtresi uygulanmaz; şoför arayıp sorar) */
    #[Url(as: 'eksik', except: false)]
    public bool $incomplete = false;

    #[Url(as: 'ara', except: '')]
    public string $search = '';

    /** @var array<string, mixed> LoadFilterService::defaults() yapısı */
    public array $filters = [];

    #[Locked]
    public ?int $presetId = null;

    public bool $advancedOpen = false;

    /** Açık tutulan ilçe listeleri ("pickup:6" gibi); sunucuda tutulur, yenilemede kapanmaz. */
    public array $openDistricts = [];

    public bool $presetModalOpen = false;

    public string $presetName = '';

    public bool $presetDefault = false;

    /**
     * Liste bu ana sabitlenir: arka plandaki yenileme listeyi kaydırmaz, yalnız "N yeni ilan · Göster" düğmesi
     * çıkarır; şoför dokununca liste yeni ana taşınır. Filtre/sekme/arama değişince de yeni ana taşınır.
     */
    public int $listAsOf = 0;

    public int $newCount = 0;


    public function mount(): void
    {
        $this->pinList();
        $this->tab = in_array($this->tab, ['pool', 'offers', 'external', 'saved'], true) ? $this->tab : 'pool';
        $this->incomplete = $this->tab === 'external' && $this->incomplete;
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
        $this->pinList();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->pinList();
        $this->resetPage();
    }

    /** Sabitleme anı, uygulama saat diliminde (UTC ile karşılaştırma listeyi boşaltır). */
    private function asOfTime(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestamp($this->listAsOf, config('app.timezone'));
    }

    private function pinList(): void
    {
        $this->listAsOf = now()->getTimestamp();
        $this->newCount = 0;
    }

    /** wire:poll: listeyi çizmeden yalnız yeni ilan sayısını bakar; sayı değişmediyse hiçbir şey çizilmez. */
    public function checkNew(): void
    {
        $count = 0;
        if (in_array($this->tab, ['pool', 'external'], true) && ($this->profile()?->isKycApproved() ?? false)) {
            $since = $this->asOfTime();
            $count = $this->tab === 'pool'
                ? $this->poolQuery(pinned: false)->tap(fn (Builder $q) => $this->visibleSince($q, $since))->count()
                : $this->externalQuery(pinned: false)->where('last_seen_at', '>', $since)->count(); // yayın ya da yeniden paylaşım
        }
        if ($count === $this->newCount) {
            $this->skipRender();

            return;
        }
        $this->newCount = $count;
    }

    /** "N yeni ilan · Göster": liste yeni ana taşınır ve listenin başına kaydırılır. */
    public function showNew(): void
    {
        $this->pinList();
        $this->resetPage();
        $this->scrollToList();
    }

    /** Sayfa numarası değişince listenin başına kaydırılır (sayfa yenilenmez). */
    public function updatedPage(): void
    {
        $this->scrollToList();
    }

    private function scrollToList(): void
    {
        $this->js("requestAnimationFrame(() => document.getElementById('ilan-listesi')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))");
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
        $this->pinList();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->presetId = null;
        $this->search = '';
        $this->filters = LoadFilterService::defaults();
        $this->pinList();
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

    public function toggleDistrictBox(string $side, int $code): void
    {
        $key = $side.':'.$code;
        $this->openDistricts = in_array($key, $this->openDistricts, true)
            ? array_values(array_diff($this->openDistricts, [$key]))
            : [...$this->openDistricts, $key];
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

    /** Teklif mesajı için hazır kısa cümleler (çip; dokununca mesaja eklenir). */
    public const OFFER_PHRASES = [
        'Yükleme günü sabah araçla hazırım.',
        'Fiyata yakıt ve otoyol dahildir.',
        'Teslimatta fotoğraf ve irsaliye paylaşırım.',
    ];

    #[Locked]
    public ?int $selectedLoadId = null;

    public string $amount = '';

    public string $estimated_days = '1';

    public string $message = '';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['pool', 'offers', 'external', 'saved'], true) ? $tab : 'pool';
        $this->pinList();
        $this->resetPage();
    }

    private function profile()
    {
        return Auth::user()->driverProfile;
    }

    /** Teklif verilebilir açık ilanlar: şoförün aktif teklifi olan ilanlar hariç. */
    private function poolQuery(bool $pinned = true): Builder
    {
        $profileId = $this->profile()?->id ?? 0;

        return Load::query()
            ->with('cargoOwnerProfile.user')
            ->where('status', Load::STATUS_ACTIVE)
            ->where('visibility', 'public')
            ->where(fn (Builder $q) => $q->whereNull('pickup_date')->orWhere('pickup_date', '>=', today())) // yükleme tarihi geçmiş ilan havuzda görünmez
            ->when($pinned && $this->listAsOf > 0, fn (Builder $q) => $this->visibleUntil($q, $this->asOfTime()))
            ->openTo($this->profile())
            ->whereDoesntHave('offers', fn (Builder $q) => $q->where('driver_profile_id', $profileId)->whereIn('status', ['pending', 'accepted']))
            ->when(trim($this->search) !== '', fn (Builder $q) => $this->applySearch($q))
            ->tap(fn (Builder $q) => app(LoadFilterService::class)->applyToLoads($q, LoadFilterService::normalize($this->filters), $this->profile()));
    }

    /**
     * Sistem ilanının bu şoför için "göründüğü an": premium için yayın anı, standart üye için premium bekleme süresinin dolduğu an.
     * Ücretsiz şoförde süre dolup açılan ilan created_at'e bakılınca "yeni" sayılmıyor ve düğmesiz kayma yapıyordu.
     */
    private function visibleSince(Builder $q, $since): Builder
    {
        if ($this->profile()?->isPremium()) {
            return $q->where('created_at', '>', $since);
        }

        return $q->where(fn (Builder $w) => $w->where(fn (Builder $c) => $c->whereNull('available_to_free_at')->where('created_at', '>', $since))
            ->orWhere('available_to_free_at', '>', $since));
    }

    private function visibleUntil(Builder $q, $until): Builder
    {
        if ($this->profile()?->isPremium()) {
            return $q->where('created_at', '<=', $until);
        }

        return $q->where(fn (Builder $w) => $w->where(fn (Builder $c) => $c->whereNull('available_to_free_at')->where('created_at', '<=', $until))
            ->orWhere('available_to_free_at', '<=', $until));
    }

    private function externalQuery(bool $pinned = true): Builder
    {
        $premium = $this->profile()?->isPremium() ?? false;

        $filters = LoadFilterService::normalize($this->filters);
        if ($this->incomplete) {
            $filters['vehicle_mode'] = 'any'; // araç zaten belirsiz; il ve arama süzgeci yine geçerli
        }

        return ScrapedLoad::query()
            ->with('scraper')
            ->where('status', 'parsed_success')
            ->where('visibility', 'public')
            ->where('is_incomplete', $this->incomplete)
            ->when($pinned && $this->listAsOf > 0, fn (Builder $q) => $q->where('last_seen_at', '<=', $this->asOfTime()))
            ->when(! $premium, fn (Builder $q) => $q->whereRaw('1 = 0')) // dış kaynak ilanları yalnız premium üyelere görünür
            ->when(trim($this->search) !== '', fn (Builder $q) => $this->applySearch($q))
            ->tap(fn (Builder $q) => app(LoadFilterService::class)->applyToScraped($q, $filters, $this->profile()));
    }

    /** Arama: her sözcük rota ya da yük adında geçmeli ("adana tavas", "kömür"). */
    private function applySearch(Builder $q): void
    {
        foreach (preg_split('/\s+/u', trim($this->search)) ?: [] as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $term = '%'.$word.'%';
            $q->where(fn (Builder $w) => $w->where('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term)->orWhere('goods_type', 'like', $term));
        }
    }

    /** Çıkış ⇄ varış: il ve ilçe seçimleri yer değiştirir (dönüş yükü ararken tek dokunuş). */
    public function swapSides(): void
    {
        [$this->filters['pickup_provinces'], $this->filters['delivery_provinces']] = [$this->filters['delivery_provinces'] ?? [], $this->filters['pickup_provinces'] ?? []];
        [$this->filters['pickup_districts'], $this->filters['delivery_districts']] = [$this->filters['delivery_districts'] ?? [], $this->filters['pickup_districts'] ?? []];
        $this->updatedFilters();
    }

    /** Etkin rozetteki "×": o süzgeç varsayılana döner. */
    public function removeChip(string $key): void
    {
        $this->filters = LoadFilterService::without($this->filters, $key);
        $this->pinList();
        $this->resetPage();
    }

    /** Hızlı çipler: tek dokunuşla aç/kapa. */
    public function toggleQuick(string $key): void
    {
        $f = LoadFilterService::normalize($this->filters);
        match (true) {
            $key === 'mine' => $this->filters['vehicle_mode'] = $f['vehicle_mode'] === 'mine' ? 'any' : 'mine',
            $key === 'priced' => $this->filters['only_priced'] = ! ($f['only_priced'] || $f['min_price'] !== null),
            $key === 'today' => $this->filters['seen_within_hours'] = $f['seen_within_hours'] === '24' ? '' : '24',
            $key === 'urgent' => $this->filters['urgent'] = ! $f['urgent'],
            in_array($key, ['komple', 'parca'], true) => $this->filters['load_kind'] = $f['load_kind'] === $key ? '' : $key,
            in_array($key, ['kisa', 'uzun'], true) => $this->filters['trailer_length'] = $f['trailer_length'] === $key ? '' : $key,
            str_starts_with($key, 'body:') => $this->toggleBodyType(substr($key, 5)),
            default => null,
        };
        if ($key === 'priced' && $this->filters['only_priced'] === false) {
            $this->filters['min_price'] = null;
        }
        $this->updatedFilters();
    }

    public function toggleGoodsCategory(string $label): void
    {
        $current = (array) ($this->filters['goods_categories'] ?? []);
        $this->filters['goods_categories'] = in_array($label, $current, true) ? array_values(array_diff($current, [$label])) : [...$current, $label];
        $this->updatedFilters();
    }

    /** Hızlı tonaj aralığı ("3500-10000"); aynı aralığa ikinci dokunuş kaldırır. */
    public function setWeightPreset(string $range): void
    {
        if (! array_key_exists($range, LoadFilterService::WEIGHT_PRESETS)) {
            return;
        }
        [$min, $max] = explode('-', $range);
        $min = (int) $min;
        $max = $max === '' ? null : (int) $max;
        $same = (int) ($this->filters['min_weight'] ?? -1) === $min && (($this->filters['max_weight'] ?? null) === null ? $max === null : (int) $this->filters['max_weight'] === $max);
        $this->filters['min_weight'] = $same ? null : $min;
        $this->filters['max_weight'] = $same ? null : $max;
        $this->updatedFilters();
    }

    /** Dış kaynak sekmesinde normal ↔ eksik bilgili bölüm; liste yeniden sabitlenir. */
    public function setIncomplete(bool $on): void
    {
        $this->incomplete = $on;
        $this->pinList();
        $this->resetPage();
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
            'chipItems' => LoadFilterService::chipItems($normalized),
            'activeFilterCount' => LoadFilterService::activeCount($normalized),
            'radii' => LoadFilterService::RADII,
            'sorts' => LoadFilterService::SORTS,
            'withinDays' => LoadFilterService::WITHIN_DAYS,
            'seenWithin' => LoadFilterService::SEEN_WITHIN_HOURS,
            'trailerLengths' => LoadFilterService::TRAILER_LENGTHS,
            'weightPresets' => LoadFilterService::WEIGHT_PRESETS,
            'goodsLabels' => array_values(\App\Support\GoodsCatalog::labels()),
            'resultTotal' => 0,
            'myVehicleType' => $profile?->activeVehicle()->value('vehicle_type'),
            'myVehicleBody' => $profile?->activeVehicle()->value('body_type'),
            'myVehicleLength' => $profile?->activeVehicle()->value('trailer_length'),
            'minPrice' => Settings::float('min_load_price'),
            'commissionRate' => $profile?->commissionRate() ?? Settings::float('commission_standard_driver'),
            'selectedLoad' => $this->selectedLoadId ? Load::query()->with('cargoOwnerProfile.user')->whereKey($this->selectedLoadId)->first() : null,
            // Teklif penceresi: şoförün son 3 teklifi (tutar, gün, mesaj) tek dokunuşla doldurulur; pencere kapalıyken sorgu yok.
            'recentOffers' => $this->offerModalOpen && $this->selectedLoadId
                ? Offer::query()->where('driver_profile_id', $profileId)->latest('id')->take(3)->get()
                    ->map(fn (Offer $o) => ['amount' => number_format((float) $o->amount, 2, '.', ''), 'label' => number_format((float) $o->amount, 0, ',', '.').' ₺', 'days' => (string) max(1, (int) $o->estimated_days), 'message' => (string) ($o->message ?? ''), 'date' => $o->created_at?->format('d.m.Y') ?? ''])
                    ->values()->all()
                : [],
            'offerPhrases' => self::OFFER_PHRASES,
            'loads' => null,
            'offers' => null,
            'savedSystemIds' => $saved->pluck('load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'savedExternalIds' => $saved->pluck('scraped_load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'takenExternalIds' => DriverTrip::query()->where('driver_profile_id', $profileId)->open()->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all(),
            'savedItems' => null,
            'takeLoad' => $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null,
            'externalLoads' => null, 'webLoadsCount' => ScrapedLoad::query()->where('status', 'parsed_success')->where('visibility', 'public')->complete()->count(),
            'incompleteCount' => ScrapedLoad::query()->where('status', 'parsed_success')->where('visibility', 'public')->where('is_incomplete', true)->count(),
        ];

        if ($this->tab === 'offers') {
            $data['offers'] = Offer::query()->with('cargoLoad')->where('driver_profile_id', $profileId)->latest('id')->paginate(50);
        } elseif (! $data['kycApproved']) {
            // Belgeleri onaylanmamış sürücüye ilan içeriği ve iletişim bilgisi gösterilmez.
        } elseif ($this->tab === 'external') {
            $data['externalLoads'] = $this->externalQuery()->paginate(50);
        } elseif ($this->tab === 'saved') {
            $data['savedItems'] = DriverSavedLoad::query()->with(['cargoLoad.cargoOwnerProfile.user', 'scrapedLoad'])
                ->where('driver_profile_id', $profileId)->latest('id')->get()
                // Yayından kalkmış (reddedilmiş / kuyruğa dönmüş) dış kaynak ilanı numarasıyla birlikte burada kalmasın
                ->filter(fn (DriverSavedLoad $s) => $s->kind() === 'external' ? ($s->scrapedLoad && $data['isPremium'] && $s->scrapedLoad->visibility === 'public' && $s->scrapedLoad->status === 'parsed_success') : (bool) $s->cargoLoad)->values();
        } else {
            $data['loads'] = $this->poolQuery()->paginate(50);
        }
        $data['resultTotal'] = (int) ($data['loads']?->total() ?? $data['externalLoads']?->total() ?? 0);

        return $data;
    }
}; ?>

<div wire:poll.15s="checkNew" class="space-y-6">

    @if($newCount > 0 && in_array($tab, ['pool', 'external'], true))
        {{-- Yeni ilan geldi: liste yerinden oynamaz, şoför isteyince gösterilir --}}
        <div class="fixed bottom-6 inset-x-0 z-40 flex justify-center pointer-events-none">
            <button type="button" wire:click="showNew" class="pointer-events-auto inline-flex items-center gap-2 rounded-full bg-neutral-900 dark:bg-white text-white dark:text-neutral-900 pl-4 pr-3 py-2.5 text-xs font-bold shadow-2xl">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                {{ $newCount }} yeni ilan
                <span class="rounded-full bg-brand-500 text-white px-2.5 py-1">Göster</span>
            </button>
        </div>
    @endif


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
        @include('livewire.driver.loads.partials.filter-bar')
    @endif

    @if($kycApproved && $tab === 'pool')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            @if(! $isPremium)
                <div class="text-2xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Yeni ilanlar önce premium üyelere açılır; {{ $freeDelay }} dakika sonra burada görünür.
                    <a href="{{ route('driver.premium.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Premium ile anında görün</a>
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="font-bold text-neutral-900 dark:text-white tabular-nums">{{ number_format($loads->total(), 0, ',', '.') }} ilan</span>
                @if($activeFilterCount > 0 || $search !== '')<span class="text-neutral-500">filtrenize uyuyor</span>@endif
                <label class="ml-auto inline-flex items-center gap-1.5 text-neutral-500">Sırala
                    <select wire:model.live="filters.sort" class="form-input py-1.5 w-auto text-xs">
                        @foreach($sorts as $key => $label)<option value="{{ $key }}" @disabled($key === 'distance' && $normalizedFilters['near_radius_km'] === null)>{{ $label }}</option>@endforeach
                    </select>
                </label>
            </div>
            <div class="space-y-3 scroll-mt-24" id="ilan-listesi">
                @forelse($loads as $load)
                    <x-system-load-card :load="$load" :saved="in_array($load->id, $savedSystemIds, true)" offer="modal" wire:key="pool-{{ $load->id }}" />
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
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 scroll-mt-24" id="ilan-listesi">
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
                        <span class="px-2.5 py-1 rounded-full text-2xs font-bold border
                            {{ $offer->status === 'accepted' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($offer->status === 'pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300') }}">
                            {{ \App\Models\Offer::STATUS_LABELS[$offer->status] ?? $offer->status }}
                        </span>
                        @if($offer->status === 'pending')
                            <button type="button" wire:click="withdrawOffer({{ $offer->id }})" wire:confirm="Teklifinizi geri çekmek istediğinize emin misiniz?" class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-bold">Geri çek</button>
                        @elseif($offer->status === 'accepted' && $offerLoad)
                            <a href="{{ route('driver.jobs.show', $offerLoad->id) }}" wire:navigate class="text-brand-400 font-bold hover:underline">İşe git</a>
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
            <div class="text-2xs text-neutral-500 leading-relaxed">Yıldızladığınız ilanlar burada durur. Kaldırmak için yıldıza yeniden basın. İlan yayından kalkınca listeden düşer.</div>
            <div class="space-y-3">
                @forelse($savedItems as $saved)
                    @if($saved->kind() === 'system')
                        <x-system-load-card :load="$saved->cargoLoad" :saved="true" offer="modal" wire:key="saved-s-{{ $saved->cargoLoad->id }}">Kaydedildi: <x-time-ago :at="$saved->created_at" /></x-system-load-card>
                    @else
                        <x-external-load-card :item="$saved->scrapedLoad" :saved="true" :taken="in_array($saved->scrapedLoad->id, $takenExternalIds, true)" wire:key="saved-e-{{ $saved->scrapedLoad->id }}" />
                    @endif
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz kaydettiğiniz ilan yok. İlan kartındaki yıldıza basarak buraya ekleyin.</div>
                @endforelse
            </div>
        </div>
    @endif

    <x-take-trip-modal :load="$takeModalOpen ? $takeLoad : null" />

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
            <div class="text-xs space-y-3">
                <div class="text-2xs text-neutral-500 leading-relaxed">
                    Bu ilanlar izinli dış kaynaklardan derlenir; NavlunIQ havuz ödemesi kapsamında değildir. Teklif ve anlaşma doğrudan ilan sahibiyle yapılır. Yalnız premium üyelere gösterilir.
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="setIncomplete(false)" class="tab-pill {{ ! $incomplete ? 'tab-pill-active' : '' }}">Dış kaynak ilanlar <span class="opacity-70">{{ number_format($webLoadsCount, 0, ',', '.') }}</span></button>
                    <button type="button" wire:click="setIncomplete(true)" class="tab-pill {{ $incomplete ? 'tab-pill-active' : '' }}">Eksik bilgili ilanlar <span class="opacity-70">{{ number_format($incompleteCount, 0, ',', '.') }}</span></button>
                    <label class="ml-auto inline-flex items-center gap-1.5 text-neutral-500">Sırala
                        <select wire:model.live="filters.sort" class="form-input py-1.5 w-auto text-xs">
                            @foreach($sorts as $key => $label)<option value="{{ $key }}" @disabled($key === 'distance' && $normalizedFilters['near_radius_km'] === null)>{{ $label }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-bold text-neutral-900 dark:text-white tabular-nums">{{ number_format($externalLoads->total(), 0, ',', '.') }} ilan</span>
                    <span class="text-neutral-500">{{ ($activeFilterCount > 0 || $search !== '') ? 'filtrenize uyuyor' : ($incomplete ? 'arayıp sorulacak' : 'aracınıza göre süzüldü') }}</span>
                </div>
                @if($incomplete)
                    <div class="text-2xs text-neutral-500 leading-relaxed">Aracı, kasası ya da yükü belirsiz ilanlar; kalkış, varış ve telefon bellidir. Arayıp öğrendiğiniz araç tipini karta girerseniz ilan tamamlanır ve herkese normal listede görünür.</div>
                @endif
            </div>

            <div class="space-y-3 scroll-mt-24" id="ilan-listesi">
                @forelse($externalLoads as $item)
                    <x-external-load-card :item="$item" :saved="in_array($item->id, $savedExternalIds, true)" :taken="in_array($item->id, $takenExternalIds, true)" wire:key="ext-{{ $item->id }}" />
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">{{ $incomplete ? 'Şu anda eksik bilgili ilan yok.' : 'Şu anda görüntülenebilir dış kaynak ilan yok.' }}</div>
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
            {{-- Son teklifler ve hazır cümleler Alpine ile doldurulur; sunucuya yalnız "Teklifi gönder" gider. --}}
            <form wire:submit.prevent="submitOffer" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs"
                x-data="{
                    amount: $wire.entangle('amount'), days: $wire.entangle('estimated_days'), message: $wire.entangle('message'),
                    rate: {{ (float) $commissionRate }}, recent: @js($recentOffers), phrases: @js($offerPhrases),
                    net() { const a = parseFloat(String(this.amount || '').replace(',', '.')); return isNaN(a) || a <= 0 ? null : (a * (1 - this.rate / 100)).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                    fill(o) { this.amount = o.amount; this.days = o.days; this.message = o.message; },
                    addPhrase(t) { const m = String(this.message || '').trim(); this.message = m === '' ? t : (m.includes(t) ? m : m + ' ' + t); },
                }">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Teklif ver</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $selectedLoad->pickup_location }} &rarr; {{ $selectedLoad->delivery_location }} · İlan fiyatı {{ number_format((float) ($selectedLoad->price ?? 0), 2, ',', '.') }} ₺@if($selectedLoad->distanceLabel()) · {{ $selectedLoad->distanceLabel() }}@endif</p>
                </div>

                @if($recentOffers !== [])
                    <div class="space-y-1.5">
                        <div class="text-2xs font-semibold text-neutral-500">Son tekliflerin</div>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="(o, i) in recent" :key="i">
                                <button type="button" @click="fill(o)" class="inline-flex max-w-full items-center gap-1.5 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-950 px-2.5 py-1.5 text-2xs text-neutral-700 dark:text-neutral-200 hover:border-brand-500 hover:text-brand-500">
                                    <span class="font-bold tabular-nums" x-text="o.label"></span>
                                    <span class="text-neutral-500" x-text="o.days + ' gün'"></span>
                                    <span class="text-neutral-400 truncate max-w-[9rem]" x-show="o.message" x-text="o.message"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Teklif tutarı (₺)</label>
                        <input type="number" step="0.01" min="{{ $minPrice }}" x-model="amount" class="form-input tabular-nums">
                        @error('amount') <span class="form-error">{{ $message }}</span> @enderror
                        <span class="text-2xs text-neutral-500 mt-1 block">Asgari {{ number_format($minPrice, 2, ',', '.') }} ₺</span>
                        <span class="text-2xs mt-1 block" data-commission-rate="{{ $commissionRate }}">Size kalan: <span class="font-bold text-neutral-900 dark:text-white tabular-nums" x-text="net() ? net() + ' ₺' : '—'">—</span> <span class="text-neutral-500">(%{{ number_format($commissionRate, 1, ',', '.') }} hizmet bedeli düşülür)</span></span>
                    </div>
                    <div>
                        <label class="form-label">Tahmini süre (gün)</label>
                        <input type="number" min="1" max="30" x-model="days" class="form-input tabular-nums">
                        @error('estimated_days') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="form-label">Mesaj (isteğe bağlı)</label>
                    <textarea x-model="message" rows="3" maxlength="1000" class="form-input"></textarea>
                    @error('message') <span class="form-error">{{ $message }}</span> @enderror
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <template x-for="p in phrases" :key="p">
                            <button type="button" @click="addPhrase(p)" class="rounded-full border border-neutral-200 dark:border-neutral-700 px-2.5 py-1 text-2xs text-neutral-600 dark:text-neutral-300 hover:border-brand-500 hover:text-brand-500" x-text="p"></button>
                        </template>
                    </div>
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
