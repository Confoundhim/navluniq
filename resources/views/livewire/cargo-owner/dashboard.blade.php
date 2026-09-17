<?php

use App\Models\Load;
use App\Models\Offer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Genel Bakış')]
class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $profileId = (int) $user->cargoOwnerProfile?->id;
        $base = Load::query()->where('cargo_owner_profile_id', $profileId);

        $activeShipment = (clone $base)
            ->with(['driverProfile.user', 'driverProfile.activeVehicle', 'shipment.vehicle'])
            ->whereIn('status', [Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED])
            ->latest('updated_at')
            ->first();

        return [
            'activeLoadsCount' => (clone $base)->where('status', Load::STATUS_ACTIVE)->count(),
            'pendingOffersCount' => Offer::query()
                ->where('status', 'pending')
                ->whereHas('cargoLoad', fn ($q) => $q->where('cargo_owner_profile_id', $profileId))
                ->count(),
            'inTransitCount' => (clone $base)->where('status', Load::STATUS_ON_THE_WAY)->count(),
            'escrowBalance' => (float) (clone $base)
                ->whereIn('escrow_status', [Load::ESCROW_PAID, Load::ESCROW_ON_HOLD, Load::ESCROW_RELEASE_APPROVED])
                ->sum('price'),
            'latestLoads' => (clone $base)
                ->withCount(['offers as pending_offers_count' => fn ($q) => $q->where('status', 'pending')])
                ->latest()
                ->take(5)
                ->get(),
            'activeShipment' => $activeShipment,
        ];
    }
}; ?>

<div wire:poll.15s class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    <div class="relative overflow-hidden rounded-2xl bg-neutral-900 dark:bg-neutral-900 border border-neutral-800 text-white p-6 md:p-8">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-300 text-xs font-semibold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-brand-500"></span>
                    NavlunIQ Yük Sahibi Paneli
                </div>
                <h2 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                    Hoş geldiniz, <span class="text-transparent bg-clip-text bg-gradient-to-r from-white via-neutral-200 to-neutral-400">{{ auth()->user()?->full_name }}</span>
                </h2>
                <p class="text-sm text-neutral-300 max-w-2xl leading-relaxed">
                    İlan yayınlayın, belgeleri doğrulanmış şoförlerden teklif toplayın ve sevkiyatınızı bu panelden takip edin. Navlun bedeli, teslimat onayına kadar güvenli havuzda tutulur.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('cargo-owner.loads.create') }}" wire:navigate class="btn-primary py-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Yeni ilan yayınla</span>
                </a>
            </div>
        </div>
        <div class="absolute -right-20 -top-20 w-80 h-80 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">

        <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-300 dark:hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Yayındaki ilanlar</span>
                <span class="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-neutral-900 dark:text-white tracking-tight">{{ $activeLoadsCount }}</div>
            <div class="text-xs text-neutral-500 mt-2">Teklif toplanıyor</div>
        </a>

        <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-300 dark:hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Bekleyen teklifler</span>
                <span class="p-2.5 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-neutral-900 dark:text-white tracking-tight">{{ $pendingOffersCount }}</div>
            <div class="text-xs text-neutral-500 mt-2">Değerlendirmenizi bekliyor</div>
        </a>

        <a href="{{ route('cargo-owner.shipments.index') }}" wire:navigate class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-300 dark:hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Yoldaki sevkiyatlar</span>
                <span class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-neutral-900 dark:text-white tracking-tight">{{ $inTransitCount }}</div>
            <div class="text-xs text-neutral-500 mt-2">Şoför yükü taşıyor</div>
        </a>

        <a href="{{ route('cargo-owner.finance.index') }}" wire:navigate class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-300 dark:hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Güvenli havuzda</span>
                <span class="p-2.5 rounded-xl bg-brand-500/10 text-brand-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-extrabold text-neutral-900 dark:text-white tracking-tight font-mono">
                {{ number_format($escrowBalance, 2, ',', '.') }} <span class="text-brand-500 text-lg">₺</span>
            </div>
            <div class="text-xs text-neutral-500 mt-2">Teslimat onayına kadar bloke</div>
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden flex flex-col">
            <div class="p-5 border-b border-neutral-200 dark:border-neutral-800 flex items-center justify-between bg-white/60 dark:bg-neutral-900/60">
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white tracking-tight">Son ilanlarınız</h3>
                <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="text-[11px] text-brand-400 font-medium hover:underline">Tümünü gör</a>
            </div>

            @if($latestLoads->isEmpty())
                <div class="p-10 text-center space-y-3">
                    <p class="text-sm font-bold text-neutral-900 dark:text-white">Henüz ilanınız yok</p>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">İlk ilanınızı yayınladığınızda şoförlerden gelen teklifler burada görünür.</p>
                    <a href="{{ route('cargo-owner.loads.create') }}" wire:navigate class="btn-primary py-2 text-xs">İlan oluştur</a>
                </div>
            @else
                <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach($latestLoads as $load)
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                            <div class="space-y-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-md bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-300 tabular-nums text-[11px] font-bold">#{{ $load->id }}</span>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold border
                                        {{ $load->status === 'active_seeking' ? 'bg-blue-500/10 border-blue-500/20 text-blue-600 dark:text-blue-400' : ($load->status === 'completed' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($load->status === 'cancelled' ? 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-500 dark:text-neutral-400' : ($load->status === 'disputed' ? 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400' : 'bg-brand-500/10 border-brand-500/20 text-brand-400'))) }}">
                                        {{ $load->statusLabel() }}
                                    </span>
                                    <span class="text-neutral-500">{{ $load->created_at?->format('d.m.Y H:i') }}</span>
                                </div>
                                <div class="text-sm font-semibold text-neutral-900 dark:text-white truncate">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                                <div class="text-neutral-500">
                                    {{ \App\Models\DriverVehicle::getVehicleTypes()[$load->vehicle_type] ?? $load->vehicle_type }} · {{ $load->goods_type }}
                                    @if($load->status === 'active_seeking') · {{ (int) $load->pending_offers_count }} bekleyen teklif @endif
                                </div>
                            </div>
                            <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0">
                                <span class="tabular-nums font-bold text-neutral-900 dark:text-white">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</span>
                                @if($load->status === 'active_seeking')
                                    <a href="{{ route('cargo-owner.loads.offers', $load->id) }}" wire:navigate class="px-3 py-1.5 rounded-lg bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-900 dark:text-white font-semibold">Teklifler</a>
                                @elseif(in_array($load->status, ['driver_assigned', 'on_the_way', 'delivered', 'disputed', 'completed'], true))
                                    <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-3 py-1.5 rounded-lg bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-900 dark:text-white font-semibold">Sevkiyat</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-5 space-y-4">
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white tracking-tight">Son aktif sevkiyat</h3>

                @if($activeShipment)
                    @php
                        $driverUser = $activeShipment->driverProfile?->user;
                        $vehicle = $activeShipment->shipment?->vehicle ?? $activeShipment->driverProfile?->activeVehicle;
                    @endphp
                    <div class="space-y-3 text-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 text-[11px] font-bold">{{ $activeShipment->statusLabel() }}</span>
                            <span class="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $activeShipment->escrowLabel() }}</span>
                        </div>
                        <div class="text-sm font-semibold text-neutral-900 dark:text-white">{{ $activeShipment->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $activeShipment->delivery_location }}</div>
                        <div class="p-3 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-1.5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500">Şoför</span>
                                <span class="text-neutral-800 dark:text-neutral-200 font-semibold text-right">{{ $driverUser?->full_name ?: 'Henüz atanmadı' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500">Araç</span>
                                <span class="text-neutral-800 dark:text-neutral-200 text-right">{{ $vehicle ? \App\Support\VehicleTypes::label($vehicle->vehicle_type).' · '.$vehicle->plate : 'Araç bilgisi yok' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500">Plaka</span>
                                <span class="text-neutral-900 dark:text-white font-mono font-bold">{{ $vehicle?->plate ?: '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500">Yükleme tarihi</span>
                                <span class="text-neutral-800 dark:text-neutral-200">{{ $activeShipment->pickup_date?->format('d.m.Y') ?? '—' }}</span>
                            </div>
                        </div>
                        <a href="{{ route('cargo-owner.shipments.show', $activeShipment->id) }}" wire:navigate class="w-full px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs flex items-center justify-center">Sevkiyatı görüntüle</a>
                    </div>
                @else
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Henüz aktif bir sevkiyatınız yok. Bir teklifi kabul edip ödemeyi tamamladığınızda sevkiyat burada görünür.</p>
                @endif
            </div>

            <div class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 flex items-center justify-between">
                <div class="text-xs text-neutral-500 dark:text-neutral-400">Yardıma mı ihtiyacınız var?</div>
                <a href="{{ route('cargo-owner.support.index') }}" wire:navigate class="text-xs font-semibold text-brand-400 hover:text-brand-300">Destek biletleri &rarr;</a>
            </div>
        </div>

    </div>

</div>
