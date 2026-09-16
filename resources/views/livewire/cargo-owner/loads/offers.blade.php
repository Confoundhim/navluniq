<?php

use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Services\OfferService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Gelen Teklifler')]
class extends Component {
    #[Locked]
    public int $loadId = 0;

    public string $sortBy = 'amount';

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;

        if (! $this->ownerLoad()) {
            session()->flash('error_message', 'İlan bulunamadı veya size ait değil.');
            $this->redirect(route('cargo-owner.loads.index'), navigate: true);
        }
    }

    private function ownerLoad(): ?Load
    {
        return Load::query()
            ->whereKey($this->loadId)
            ->where('cargo_owner_profile_id', (int) Auth::user()->cargoOwnerProfile?->id)
            ->first();
    }

    public function acceptOffer(int $offerId, OfferService $offers): void
    {
        $load = $this->ownerLoad();
        $offer = $load ? Offer::query()->whereKey($offerId)->where('load_id', $load->id)->first() : null;

        if (! $load || ! $offer) {
            session()->flash('error_message', 'Teklif bulunamadı.');

            return;
        }

        try {
            $offers->accept($load, $offer, (int) Auth::id());
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Teklif kabul edildi. Şoförün yola çıkabilmesi için navlun bedelini güvenli havuza yatırın.');
        $this->redirect(route('cargo-owner.finance.payment', $load->id), navigate: true);
    }

    public function rejectOffer(int $offerId, OfferService $offers): void
    {
        $load = $this->ownerLoad();
        $offer = $load ? Offer::query()->whereKey($offerId)->where('load_id', $load->id)->first() : null;

        if (! $load || ! $offer) {
            session()->flash('error_message', 'Teklif bulunamadı.');

            return;
        }

        try {
            $offers->reject($offer, (int) Auth::id());
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Teklif reddedildi.');
    }

    public function with(): array
    {
        $load = $this->ownerLoad();

        $all = $load
            ? Offer::query()
                ->with(['driverProfile.user' => fn ($q) => $q->withAvg('reviewsReceived', 'rating')->withCount('reviewsReceived'), 'driverProfile.activeVehicle'])
                ->where('load_id', $load->id)
                ->latest()
                ->get()
            : collect();

        $pending = $all->where('status', 'pending')->map(function (Offer $offer): array {
            $driver = $offer->driverProfile;
            $user = $driver?->user;

            return [
                'offer' => $offer,
                'driver' => $driver,
                'user' => $user,
                'vehicle' => $driver?->activeVehicle,
                'rating' => $user?->reviews_received_avg_rating !== null ? round((float) $user->reviews_received_avg_rating, 1) : null,
                'reviews_count' => (int) ($user?->reviews_received_count ?? 0),
            ];
        });

        $pending = match ($this->sortBy) {
            'rating' => $pending->sortByDesc(fn (array $row) => [$row['rating'] ?? 0, $row['reviews_count']]),
            'newest' => $pending->sortByDesc(fn (array $row) => $row['offer']->created_at?->timestamp ?? 0),
            default => $pending->sortBy(fn (array $row) => (float) $row['offer']->amount),
        };

        return [
            'load' => $load,
            'pendingOffers' => $pending->values(),
            'closedOffers' => $all->whereIn('status', ['rejected', 'withdrawn', 'expired'])->values(),
            'acceptedOffer' => $all->firstWhere('status', 'accepted'),
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-semibold">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
                &larr; İlanlarıma dön
            </a>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
                <span>İlana gelen teklifler</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 tabular-nums text-xs font-bold border border-brand-500/20">#{{ $loadId }}</span>
            </h2>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-xs text-neutral-500 dark:text-neutral-400">Sırala:</span>
            <select wire:model.live="sortBy" class="form-input">
                <option value="amount">En düşük tutar</option>
                <option value="rating">En yüksek puan</option>
                <option value="newest">En yeni</option>
            </select>
        </div>
    </div>

    @if($load)
        <div class="p-5 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
            <div class="lg:col-span-2 min-w-0">
                <span class="text-neutral-500 block">Rota</span>
                <span class="text-neutral-900 dark:text-white font-bold break-words">{{ $load->pickup_location }} &rarr; {{ $load->delivery_location }}</span>
            </div>
            <div>
                <span class="text-neutral-500 block">Araç & yük</span>
                <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $vehicleTypes[$load->vehicle_type] ?? $load->vehicle_type }} · {{ $load->goods_type }}</span>
                <span class="text-neutral-500 block mt-1">Yükleme: {{ $load->pickup_date?->format('d.m.Y') ?? '—' }}</span>
            </div>
            <div>
                <span class="text-neutral-500 block">İlan bedeli</span>
                <span class="text-brand-400 font-bold tabular-nums text-sm">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</span>
                <span class="text-neutral-500 block mt-1">Durum: <span class="text-neutral-700 dark:text-neutral-300">{{ $load->statusLabel() }}</span></span>
            </div>
        </div>

        @if($load->status !== 'active_seeking')
            <div class="p-4 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-xs text-neutral-700 dark:text-neutral-300 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <span>Bu ilan artık teklif kabul etmiyor. Durum: <strong class="text-neutral-900 dark:text-white">{{ $load->statusLabel() }}</strong></span>
                @if($load->status === 'driver_assigned' && $load->escrow_status === 'pending_payment')
                    <a href="{{ route('cargo-owner.finance.payment', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-center">Ödemeye git</a>
                @elseif(in_array($load->status, ['driver_assigned', 'on_the_way', 'delivered', 'disputed', 'completed'], true))
                    <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-900 dark:text-white font-semibold text-center">Sevkiyatı görüntüle</a>
                @endif
            </div>
        @endif

        <div class="space-y-4">
            @forelse($pendingOffers as $row)
                @php
                    $offer = $row['offer'];
                    $driver = $row['driver'];
                    $user = $row['user'];
                    $vehicle = $row['vehicle'];
                    $name = $user?->full_name ?: 'Şoför';
                @endphp
                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 hover:border-neutral-300 dark:hover:border-neutral-700/80 rounded-2xl p-6 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                    <div class="space-y-3 flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 flex items-center justify-center font-black text-brand-500 text-base shrink-0">
                                {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-bold text-neutral-900 dark:text-white">{{ $name }}</span>
                                    @if($driver?->isKycApproved())
                                        <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold">Belgeleri doğrulandı</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                                    @if($row['rating'] !== null)
                                        <span class="text-amber-600 dark:text-amber-400 font-semibold">{{ number_format($row['rating'], 1, ',', '.') }} / 5</span>
                                        <span class="text-neutral-600">·</span>
                                        <span>{{ $row['reviews_count'] }} değerlendirme</span>
                                    @else
                                        <span>Henüz değerlendirme yok</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-500 dark:text-neutral-400 pt-1">
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">Plaka:</span>
                                <span class="text-neutral-800 dark:text-neutral-200 font-mono font-semibold">{{ $vehicle?->plate ?: '—' }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">Araç:</span>
                                <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $vehicle ? \App\Support\VehicleTypes::label($vehicle->vehicle_type).' · '.$vehicle->plate : 'Aktif araç bilgisi yok' }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">Tahmini süre:</span>
                                <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $offer->estimated_days ? $offer->estimated_days.' gün' : 'Belirtilmedi' }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">Teklif tarihi:</span>
                                <span class="text-neutral-800 dark:text-neutral-200">{{ $offer->created_at?->format('d.m.Y H:i') }}</span>
                            </div>
                            @if($offer->expires_at)
                                <div class="flex items-center gap-1">
                                    <span class="text-neutral-500">Geçerlilik:</span>
                                    <span class="text-neutral-800 dark:text-neutral-200">{{ $offer->expires_at->format('d.m.Y H:i') }}</span>
                                </div>
                            @endif
                        </div>

                        @if($offer->message)
                            <p class="text-xs text-neutral-700 dark:text-neutral-300 bg-neutral-950/60 p-3 rounded-xl border border-neutral-200 dark:border-neutral-800/80 leading-relaxed break-words">{{ $offer->message }}</p>
                        @endif
                    </div>

                    <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-200 dark:border-neutral-800">
                        <div class="text-left lg:text-right">
                            <span class="text-[11px] text-neutral-500 uppercase tracking-wider block">Teklif tutarı</span>
                            <div class="text-3xl font-black text-neutral-900 dark:text-white tabular-nums">
                                {{ number_format((float) $offer->amount, 2, ',', '.') }} <span class="text-brand-500 text-xl">₺</span>
                            </div>
                        </div>

                        @if($load->status === 'active_seeking')
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                                <button type="button" wire:click="rejectOffer({{ $offer->id }})" wire:confirm="Bu teklifi reddetmek istediğinize emin misiniz?" wire:loading.attr="disabled" class="px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 text-xs font-semibold transition-colors">
                                    Reddet
                                </button>
                                <button type="button" wire:click="acceptOffer({{ $offer->id }})" wire:confirm="{{ $name }} adlı şoförün {{ number_format((float) $offer->amount, 2, ',', '.') }} ₺ tutarındaki teklifini kabul etmek istediğinize emin misiniz? Diğer teklifler reddedilecek." wire:loading.attr="disabled" class="px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/25 transition-all flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <span>Kabul et ve ödemeye geç</span>
                                </button>
                            </div>
                        @endif
                    </div>

                </div>
            @empty
                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-3">
                    <h4 class="text-base font-bold text-neutral-900 dark:text-white">{{ $load->status === 'active_seeking' ? 'Henüz bekleyen teklif yok' : 'Bekleyen teklif yok' }}</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">
                        @if($load->status === 'active_seeking')
                            İlanınız şoför havuzunda yayında. Teklif geldiğinde e-posta ile bilgilendirilirsiniz.
                        @elseif($acceptedOffer)
                            {{ $acceptedOffer->driverProfile?->user?->full_name ?: 'Şoför' }} adlı şoförün {{ number_format((float) $acceptedOffer->amount, 2, ',', '.') }} ₺ tutarındaki teklifi kabul edildi.
                        @else
                            Bu ilan için teklif süreci kapandı.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if($closedOffers->isNotEmpty())
            <div x-data="{ open: false }" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl">
                <button type="button" x-on:click="open = !open" class="w-full flex items-center justify-between p-4 text-xs font-semibold text-neutral-700 dark:text-neutral-300 hover:text-neutral-900 dark:hover:text-white">
                    <span>Kapanmış teklifler ({{ $closedOffers->count() }})</span>
                    <span x-text="open ? 'Gizle' : 'Göster'" class="text-neutral-500"></span>
                </button>
                <div x-show="open" x-cloak class="border-t border-neutral-200 dark:border-neutral-800 divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach($closedOffers as $offer)
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                            <div class="min-w-0">
                                <span class="text-neutral-800 dark:text-neutral-200 font-semibold">{{ $offer->driverProfile?->user?->full_name ?: 'Şoför' }}</span>
                                <span class="text-neutral-500"> · {{ $offer->created_at?->format('d.m.Y H:i') }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format((float) $offer->amount, 2, ',', '.') }} ₺</span>
                                <span class="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-500 dark:text-neutral-400 text-[11px] font-bold">{{ \App\Models\Offer::STATUS_LABELS[$offer->status] ?? $offer->status }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center text-xs text-neutral-500 dark:text-neutral-400">İlan bulunamadı.</div>
    @endif

</div>
