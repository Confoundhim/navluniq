<?php

use App\Livewire\Concerns\HandlesExternalLoadActions;
use App\Livewire\Concerns\HandlesJobActions;
use App\Models\DriverFilterPreset;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Services\DriverTripService;
use App\Services\LoadFilterService;
use App\Support\BodyTypes;
use App\Support\VehicleTypes;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.driver')]
#[Title('Genel Bakış')]
class extends Component {
    use HandlesExternalLoadActions;
    use HandlesJobActions;

    public function mount(DriverTripService $trips): void
    {
        if ($profile = Auth::user()->driverProfile) {
            $trips->reconcile($profile);
            // İlk açık işin dönüş yükleri açık gelir
            $this->expandedJob = $this->openJobsQuery()->value('id');
        }
    }

    /** Sayfadaki verinin ucuz imzası: yeni ilan, iş / teklif / bildirim değişimi; aynıysa süreli yenileme çizmez. */
    public ?string $pollSignature = null;

    private function currentSignature(): string
    {
        $profileId = Auth::user()->driverProfile?->id ?? 0;
        $parts = [
            ScrapedLoad::query()->where('visibility', 'public')->max('id'),
            Load::query()->where('status', Load::STATUS_ACTIVE)->where('visibility', 'public')->max('id'),
            Load::query()->where('driver_profile_id', $profileId)->max('updated_at'),
            DriverTrip::query()->where('driver_profile_id', $profileId)->max('updated_at'),
            DriverTrip::query()->where('driver_profile_id', $profileId)->open()->count(),
            Offer::query()->where('driver_profile_id', $profileId)->where('status', 'pending')->count(),
            Auth::user()->unreadNotificationCount(),
        ];

        return md5(implode('|', array_map(fn ($v) => (string) $v, $parts)));
    }

    public function tick(): void
    {
        $signature = $this->currentSignature();
        if ($this->pollSignature === $signature) {
            $this->skipRender();

            return;
        }
        $this->pollSignature = $signature;
    }

    public function with(): array
    {
        $user = Auth::user();
        $profile = $user->driverProfile;
        $profileId = $profile?->id ?? 0;
        $this->pollSignature = $this->currentSignature();

        // Açık işler (kabul edilen teklifler + "Bu işi aldım") ve seçili işin varış çevresindeki dönüş yükleri
        $openJobs = $profileId ? $this->openJobsQuery()->get() : collect();
        $returnLoads = $this->returnLoadsForExpanded($openJobs, app(DriverTripService::class), limit: 4);
        $saved = $profileId ? DriverSavedLoad::query()->where('driver_profile_id', $profileId)->get() : collect();

        // "Size uygun ilanlar": varsayılan filtre seti (yoksa "Aracıma uygun") ile süzülmüş sistem + (premium ise) dış kaynak ilanları
        $preset = $profileId ? DriverFilterPreset::query()->where('driver_profile_id', $profileId)->where('is_default', true)->first() : null;
        $filters = LoadFilterService::normalize($preset?->filters ?? []);
        $filterSvc = app(LoadFilterService::class);
        $vehicle = $profile?->activeVehicle()->first();
        $canSeePool = $profile && $profile->kyc_status === 'approved';

        $systemLoads = collect();
        $externalLoads = collect();
        if ($canSeePool) {
            $systemLoads = Load::query()
                ->with('cargoOwnerProfile.user')
                ->where('status', Load::STATUS_ACTIVE)
                ->where('visibility', 'public')
                ->where(fn ($q) => $q->whereNull('cargo_owner_profile_id')->orWhereHas('cargoOwnerProfile', fn ($o) => $o->where('user_id', '!=', $user->id)))
                ->openTo($profile)
                ->tap(fn ($q) => $filterSvc->applyToLoads($q, $filters, $profile))
                ->take(8)->get();
            if ($profile->isPremium()) {
                $externalLoads = ScrapedLoad::query()
                    ->where('status', 'parsed_success')->where('visibility', 'public')->complete()
                    ->tap(fn ($q) => $filterSvc->applyToScraped($q, $filters, $profile))
                    ->take(8)->get();
            }
        }
        $matchedLoads = $systemLoads->map(fn ($l) => ['kind' => 'system', 'at' => $l->published_at ?? $l->created_at, 'load' => $l])
            ->concat($externalLoads->map(fn ($l) => ['kind' => 'external', 'at' => $l->created_at, 'load' => $l]))
            ->sortByDesc('at')->take(8)->values();
        $matchSummary = array_values(array_filter(array_merge(
            [$vehicle ? implode(' · ', array_filter([VehicleTypes::label($vehicle->vehicle_type), $vehicle->trailer_length ? (BodyTypes::TRAILER_LENGTHS[$vehicle->trailer_length] ?? null) : null, $vehicle->body_type ? BodyTypes::label($vehicle->body_type) : null])) : null],
            array_slice(LoadFilterService::chips($filters), 1)
        )));

        return [
            'profile' => $profile,
            'pendingOffers' => Offer::query()->where('driver_profile_id', $profileId)->where('status', 'pending')->count(),
            'wallet' => app(PayoutService::class)->walletSummary($user),
            'matchedLoads' => $matchedLoads,
            'matchSummary' => $matchSummary,
            'matchPreset' => $preset,
            'vehicle' => $vehicle,
            'canSeePool' => $canSeePool,
            'openJobs' => $openJobs,
            'returnLoads' => $returnLoads,
            'isPremium' => $profile?->isPremium() ?? false,
            'savedSystemIds' => $saved->pluck('load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'savedExternalIds' => $saved->pluck('scraped_load_id')->filter()->map(fn ($v) => (int) $v)->all(),
            'takenExternalIds' => $profileId ? DriverTrip::query()->where('driver_profile_id', $profileId)->open()->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all() : [],
            'takeLoad' => $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null,
        ];
    }
}; ?>

<div wire:poll.15s="tick" class="space-y-6">

    @php $kycStatus = $profile?->kyc_status ?? 'unsubmitted'; @endphp

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Hoş geldiniz, {{ auth()->user()->first_name }}</h2>
        <p class="page-subtitle">Tekliflerinizin, açık işlerinizin ve ödemelerinizin özeti.</p>
    </div>

    @if($kycStatus !== 'approved')
        <div class="p-4 rounded-xl border text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3
            {{ $kycStatus === 'rejected' ? 'bg-rose-500/10 border-rose-500/20 text-rose-700 dark:text-rose-300' : 'bg-amber-500/10 border-amber-500/20 text-amber-700 dark:text-amber-300' }}">
            <div>
                <div class="font-bold">
                    @if($kycStatus === 'pending') Belgeleriniz inceleniyor
                    @elseif($kycStatus === 'rejected') Belgeleriniz reddedildi
                    @else Belge doğrulaması tamamlanmadı
                    @endif
                </div>
                <div class="mt-0.5 opacity-90">İlan havuzu ve teklif verme belgeleriniz onaylandığında açılır; dış kaynak ilanları premium üyelere özeldir.</div>
            </div>
            <a href="{{ route('driver.profile.index') }}" wire:navigate class="shrink-0 px-4 py-2 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-300 dark:border-neutral-700 text-neutral-900 dark:text-white font-bold hover:bg-neutral-200 dark:hover:bg-neutral-800">Belgelere git</a>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Değerlendirilen tekliflerim</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ (int) $pendingOffers }}</div>
            <a href="{{ route('driver.loads.index') }}" wire:navigate class="mt-2 inline-block text-xs text-brand-400 font-bold hover:underline">Teklifleri gör</a>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Teslimat onayı bekleyen</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($wallet['in_escrow'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-2 text-[11px] text-neutral-500">Yük sahibinin ödediği, onayla tamamlanacak navlun bedeli</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Hesabınıza geçecek ödeme</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($wallet['pending'] ?? 0), 2, ',', '.') }} ₺</div>
            <a href="{{ route('driver.wallet.index') }}" wire:navigate class="mt-2 inline-block text-xs text-brand-400 font-bold hover:underline">Ödemelerime git</a>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Hesabınıza geçen toplam ödeme</div>
            <div class="mt-2 text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">{{ number_format((float) ($wallet['paid'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-2 text-[11px] text-neutral-500">Kesilen komisyon: {{ number_format((float) ($wallet['commission'] ?? 0), 2, ',', '.') }} ₺</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h3 class="section-title">Açık işlerim{{ $openJobs->count() > 1 ? ' · '.$openJobs->count() : '' }}</h3>
                    <a href="{{ route('driver.jobs.index') }}" wire:navigate class="text-xs text-brand-400 font-bold hover:underline">İşlerim</a>
                </div>
                @forelse($openJobs as $trip)
                    <x-job-card :trip="$trip" :expanded="$expandedJob === $trip->id" :return-loads="$expandedJob === $trip->id ? $returnLoads : null" :saved-external-ids="$savedExternalIds" :taken-external-ids="$takenExternalIds" :is-premium="$isPremium" wire:key="job-{{ $trip->id }}" />
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">
                        Şu anda açık işiniz yok. İlan havuzundan teklif verin ya da gruptan derlenen bir ilanda "Bu işi aldım" deyin.
                    </div>
                @endforelse
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="section-title">Size uygun ilanlar</h3>
                        <p class="text-[11px] text-neutral-500 mt-1">
                            @if($matchSummary !== [])
                                {{ implode(' · ', $matchSummary) }}
                                @if($matchPreset) · filtre: {{ $matchPreset->name }}@endif
                                · <a href="{{ route('driver.loads.index', ['filters' => 1]) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Filtreyi değiştir</a>
                            @else
                                Aracınıza ve kaydettiğiniz filtreye göre süzülür. <a href="{{ route('driver.loads.index', ['filters' => 1]) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Filtre oluşturun</a>.
                            @endif
                        </p>
                        @if($vehicle && ! $vehicle->body_type)
                            <p class="text-[11px] text-amber-600 mt-1">Aracınızın kasa tipi kayıtlı değil; <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="underline">Araçlarım</a> sayfasından ekleyin, ilanlar kasanıza göre süzülsün.</p>
                        @endif
                    </div>
                    <a href="{{ route('driver.loads.index') }}" wire:navigate class="text-xs text-brand-400 font-bold hover:underline shrink-0">Tümünü gör</a>
                </div>

                @forelse($matchedLoads as $row)
                    @php $load = $row['load']; @endphp
                    @if($row['kind'] === 'system')
                        @php $kg = (int) ($load->weight ?? 0); $lp = (float) ($load->price ?? 0); @endphp
                        <div class="load-card">
                            <div class="load-card-main">
                                <div class="load-card-title">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                                <div class="load-card-line">{{ $load->goods_type ?: 'Yük türü belirtilmemiş' }} · {{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($load->vehicle_type), $load->bodyLabel(), $load->loadKindLabel()])) }}@if($kg > 0) · {{ $kg >= 1000 ? rtrim(rtrim(number_format($kg / 1000, 1, ',', '.'), '0'), ',').' ton' : number_format($kg, 0, ',', '.').' kg' }}@endif</div>
                                <div class="load-card-line">Yükleme: {{ $load->pickup_date?->format('d.m.Y') ?? 'Belirtilmemiş' }} · {{ $load->cargoOwnerProfile?->displayName() ?: 'Yük sahibi belirtilmemiş' }}</div>
                                <div class="load-card-badges"><span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400">NavlunIQ ilanı</span></div>
                            </div>
                            <div class="load-card-side sm:min-h-0">
                                <div class="load-card-price">{{ number_format($lp, fmod($lp, 1.0) === 0.0 ? 0 : 2, ',', '.') }} ₺</div>
                                <div class="flex items-center gap-1.5">
                                    @php $isSaved = in_array($load->id, $savedSystemIds, true); @endphp
                                    <button type="button" wire:click="toggleSave('system', {{ $load->id }})" class="load-card-star {{ $isSaved ? 'load-card-star-on' : '' }}" title="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}" aria-label="{{ $isSaved ? 'Kaydedilenlerden çıkar' : 'Kaydet' }}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $isSaved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.05 3.7c.3-.92 1.6-.92 1.9 0l1.52 4.67a1 1 0 00.95.69h4.92c.97 0 1.37 1.24.59 1.81l-3.98 2.89a1 1 0 00-.36 1.12l1.52 4.67c.3.92-.76 1.69-1.54 1.12l-3.98-2.89a1 1 0 00-1.18 0l-3.98 2.89c-.78.57-1.84-.2-1.54-1.12l1.52-4.67a1 1 0 00-.36-1.12L3.07 10.87c-.78-.57-.38-1.81.59-1.81h4.92a1 1 0 00.95-.69l1.52-4.67z"/></svg></button>
                                    <a href="{{ route('driver.loads.index', ['ilan' => $load->id]) }}" wire:navigate class="load-card-action">Teklif ver</a>
                                </div>
                            </div>
                        </div>
                    @else
                        <x-external-load-card :item="$load" :saved="in_array($load->id, $savedExternalIds, true)" :taken="in_array($load->id, $takenExternalIds, true)" wire:key="m-e-{{ $load->id }}" />
                    @endif
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">
                        @if(! $canSeePool) İlanlar belgeleriniz onaylandığında görünür. @else Filtrenize uyan açık ilan yok. <a href="{{ route('driver.loads.index', ['filters' => 1]) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Filtreyi genişletin</a>. @endif
                    </div>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="section-title">Belge durumu</h3>
                <div class="flex items-center justify-between">
                    <span class="text-neutral-500 dark:text-neutral-400">KYC</span>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border
                        {{ $kycStatus === 'approved' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($kycStatus === 'pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400' : ($kycStatus === 'rejected' ? 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300')) }}">
                        {{ ['approved' => 'Doğrulandı', 'pending' => 'İnceleniyor', 'rejected' => 'Reddedildi', 'unsubmitted' => 'Belge bekleniyor'][$kycStatus] ?? $kycStatus }}
                    </span>
                </div>
                <a href="{{ route('driver.profile.index') }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">Profil ve belgeler</a>
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="section-title">Premium</h3>
                @if($profile?->isPremium())
                    <div class="text-emerald-600 dark:text-emerald-400 font-bold">Aktif</div>
                    <div class="text-neutral-500 dark:text-neutral-400">{{ $profile->premium_until->format('d.m.Y H:i') }} tarihine kadar geçerli.</div>
                @else
                    <div class="text-neutral-700 dark:text-neutral-300 font-bold">Pasif</div>
                    <div class="text-neutral-500 dark:text-neutral-400">Komisyon oranınız: %{{ number_format($profile?->commissionRate() ?? 0, 1, ',', '.') }}</div>
                @endif
                <a href="{{ route('driver.premium.index') }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">Premium ayrıntıları</a>
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="section-title">Aktif araç</h3>
                @if($profile?->activeVehicle)
                    <div class="text-neutral-900 dark:text-white font-mono font-bold">{{ $profile->activeVehicle->plate }}</div>
                    <div class="text-neutral-500 dark:text-neutral-400">{{ \App\Support\VehicleTypes::label($profile->activeVehicle->vehicle_type) }}</div>
                @else
                    <div class="text-neutral-500 dark:text-neutral-400">Aktif aracınız yok. Teklif verebilmek için bir araç ekleyip aktif yapın.</div>
                @endif
                <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">Araçları yönet</a>
            </div>
        </div>
    </div>
    <x-take-trip-modal :load="$takeModalOpen ? $takeLoad : null" />
</div>
