<?php

use App\Models\Load;
use App\Models\Offer;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.driver')]
#[Title('Genel Bakış')]
class extends Component {
    /** Tercih edilen rota metnini şehir/kelime parçalarına ayırır. */
    private function routeTokens(?string $routes): array
    {
        $parts = preg_split('/[,\-\/>]+/u', (string) $routes) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => mb_strlen($p) >= 2)));
    }

    public function with(): array
    {
        $user = Auth::user();
        $profile = $user->driverProfile;
        $profileId = $profile?->id ?? 0;

        $activeLoad = Load::query()
            ->with(['cargoOwnerProfile.user', 'shipment'])
            ->where('driver_profile_id', $profileId)
            ->whereIn('status', [Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED])
            ->latest('id')
            ->first();

        $tokens = $this->routeTokens($profile?->preferences['preferred_routes'] ?? null);
        $poolQuery = fn () => Load::query()
            ->with('cargoOwnerProfile.user')
            ->where('status', Load::STATUS_ACTIVE)
            ->where('visibility', 'public')
            ->latest('published_at')
            ->latest('id');

        $matchedByPreference = false;
        $recentLoads = collect();

        if ($tokens !== []) {
            $recentLoads = $poolQuery()->where(function ($q) use ($tokens): void {
                foreach ($tokens as $token) {
                    $q->orWhere('pickup_location', 'like', '%'.$token.'%')
                        ->orWhere('delivery_location', 'like', '%'.$token.'%');
                }
            })->take(5)->get();
            $matchedByPreference = $recentLoads->isNotEmpty();
        }

        if ($recentLoads->isEmpty()) {
            $recentLoads = $poolQuery()->take(5)->get();
        }

        return [
            'profile' => $profile,
            'pendingOffers' => Offer::query()->where('driver_profile_id', $profileId)->where('status', 'pending')->count(),
            'activeLoad' => $activeLoad,
            'wallet' => app(PayoutService::class)->walletSummary($user),
            'recentLoads' => $recentLoads,
            'matchedByPreference' => $matchedByPreference,
            'hasPreferredRoutes' => $tokens !== [],
        ];
    }
}; ?>

<div wire:poll.15s class="space-y-6">

    @php $kycStatus = $profile?->kyc_status ?? 'unsubmitted'; @endphp

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Hoş geldiniz, {{ auth()->user()->first_name }}</h2>
        <p class="page-subtitle">Tekliflerinizin, aktif sevkiyatınızın ve hakedişlerinizin özeti.</p>
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
                <div class="mt-0.5 opacity-90">Teklif verebilmek için sürücü belgelerinizin onaylanmış olması gerekir.</div>
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
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Havuzda bloke</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($wallet['in_escrow'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-2 text-[11px] text-neutral-500">Yük sahibinin havuza yatırdığı navlun bedeli</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödeme sırasındaki hakediş</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($wallet['pending'] ?? 0), 2, ',', '.') }} ₺</div>
            <a href="{{ route('driver.wallet.index') }}" wire:navigate class="mt-2 inline-block text-xs text-brand-400 font-bold hover:underline">Cüzdana git</a>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödenen toplam hakediş</div>
            <div class="mt-2 text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">{{ number_format((float) ($wallet['paid'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-2 text-[11px] text-neutral-500">Kesilen komisyon: {{ number_format((float) ($wallet['commission'] ?? 0), 2, ',', '.') }} ₺</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="section-title">Aktif sevkiyat</h3>

                @if($activeLoad)
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">
                                {{ $activeLoad->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $activeLoad->delivery_location }}
                            </div>
                            <span class="px-2.5 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 font-bold text-[11px]">{{ $activeLoad->statusLabel() }}</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-neutral-500 dark:text-neutral-400">
                            <div>Yük sahibi: <span class="text-neutral-900 dark:text-white font-semibold">{{ $activeLoad->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}</span></div>
                            <div>Yükleme: <span class="text-neutral-900 dark:text-white">{{ $activeLoad->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}</span></div>
                            <div>Navlun: <span class="text-neutral-900 dark:text-white tabular-nums font-bold">{{ number_format((float) ($activeLoad->price ?? 0), 2, ',', '.') }} ₺</span></div>
                            <div>Ödeme: <span class="text-neutral-900 dark:text-white">{{ $activeLoad->escrowLabel() }}</span></div>
                        </div>
                        <div class="pt-2 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row gap-2">
                            <a href="{{ route('driver.shipments.show', $activeLoad->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-center">Sevkiyatı yönet</a>
                        </div>
                    </div>
                @else
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">
                        Şu anda aktif bir sevkiyatınız yok. İlan havuzundan teklif vererek yeni bir yük alabilirsiniz.
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="section-title">Son ilanlar</h3>
                        <p class="text-[11px] text-neutral-500 mt-1">
                            @if($matchedByPreference)
                                Tercih ettiğiniz rotalarla eşleşen en yeni açık ilanlar.
                            @elseif($hasPreferredRoutes)
                                Tercih ettiğiniz rotalarda açık ilan bulunmadı; en yeni açık ilanlar gösteriliyor.
                            @else
                                En yeni açık ilanlar. <a href="{{ route('driver.loads.index', ['filters' => 1]) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Kalıcı filtre oluşturun</a>, havuz size göre süzülsün.
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('driver.loads.index') }}" wire:navigate class="text-xs text-brand-400 font-bold hover:underline shrink-0">İlan havuzuna git</a>
                </div>

                @forelse($recentLoads as $load)
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                        <div class="space-y-1">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">
                                {{ $load->goods_type }} · {{ \App\Models\DriverVehicle::getVehicleTypes()[$load->vehicle_type] ?? $load->vehicle_type }}
                                @if($load->weight) · {{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg @endif
                                · Yükleme {{ $load->pickup_date?->format('d.m.Y') ?? 'Belirtilmemiş' }}
                            </div>
                        </div>
                        <div class="text-neutral-900 dark:text-white tabular-nums font-bold text-base shrink-0">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</div>
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz açık ilan yok.</div>
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
</div>
