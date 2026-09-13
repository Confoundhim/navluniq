<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Yük Sahibi Gösterge Paneli & Canlı Radar')]
class extends Component {
    public int $activeLoadsCount = 0;
    public int $pendingOffersCount = 0;
    public int $completedShipmentsCount = 0;
    public float $escrowBalance = 0.0;
    public $activeShipment = null;
    public array $timeline = [];

    public function mount(): void
    {
        $this->loadDashboardData();
    }

    public function loadDashboardData(): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $cargoOwnerProfile = $user->cargoOwnerProfile;

        if ($cargoOwnerProfile) {
            $profileId = (int) $cargoOwnerProfile->id;

            $this->activeLoadsCount = Load::where('cargo_owner_profile_id', $profileId)
                ->where('status', 'active_seeking')
                ->count();

            $this->completedShipmentsCount = Load::where('cargo_owner_profile_id', $profileId)
                ->where('status', 'delivered')
                ->count();

            $this->escrowBalance = (float) Load::where('cargo_owner_profile_id', $profileId)
                ->where('escrow_status', 'paid_in_escrow')
                ->sum('price');

            $this->activeShipment = Load::with(['driverProfile.user'])
                ->where('cargo_owner_profile_id', $profileId)
                ->whereIn('status', ['driver_assigned', 'on_the_way'])
                ->latest()
                ->first();
        }

        $this->timeline = [];
    }
}; ?>

<div class="space-y-6" x-data="{
    initMap() {
        if (typeof L === 'undefined') return;
        const el = document.getElementById('cargoRadarMap');
        if (!el || el._leaflet_id) return;

        const map = L.map('cargoRadarMap', { zoomControl: false }).setView([39.0, 35.0], 6);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 18
        }).addTo(map);

        const ankara = [39.9334, 32.8597];
        const izmir = [38.4237, 27.1428];
        const truckPos = [39.1500, 29.9800];

        L.circleMarker(ankara, { radius: 6, color: '#3b82f6', fillColor: '#3b82f6', fillOpacity: 0.9 }).addTo(map).bindPopup('Çıkış: Ankara');
        L.circleMarker(izmir, { radius: 6, color: '#10b981', fillColor: '#10b981', fillOpacity: 0.9 }).addTo(map).bindPopup('Varış: İzmir');
        L.circleMarker(truckPos, { radius: 8, color: '#ffffff', weight: 2, fillColor: '#f97316', fillOpacity: 1 }).addTo(map).bindPopup('06 TR 992 (82 km/s)');

        const polyline = L.polyline([ankara, truckPos, izmir], { color: '#f97316', weight: 3, opacity: 0.8, dashArray: '6, 6' }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [30, 30] });
    }
}" x-init="initMap()">

    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-neutral-900 via-neutral-900 to-neutral-850 border border-neutral-800 p-6 md:p-8">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 text-xs font-semibold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                    NavlunIQ Akıllı Sevkiyat Ağı
                </div>
                <h2 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                    Hoş Geldiniz, <span class="text-transparent bg-clip-text bg-gradient-to-r from-white via-neutral-200 to-neutral-400">{{ auth()->user()?->full_name }}</span>
                </h2>
                <p class="text-sm text-neutral-400 max-w-2xl leading-relaxed">
                    İlanlarınızı yayınlayın, belgeleri yapay zeka ile onaylanmış profesyonel şoförlerden teklif toplayın ve sevkiyatınızı PayTR havuz korumasıyla anlık haritada takip edin.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('cargo-owner.loads.create') }}" class="px-5 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-sm shadow-xl shadow-brand-500/20 transition-all duration-200 active:scale-95 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Yeni İlan Yayınla</span>
                </a>
            </div>
        </div>
        <div class="absolute -right-20 -top-20 w-80 h-80 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">

        <div class="bg-neutral-900 border border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Yayındaki İlanlar</span>
                <span class="p-2.5 rounded-xl bg-blue-500/10 text-blue-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-white tracking-tight">{{ $activeLoadsCount }}</div>
            <div class="text-xs text-neutral-500 mt-2 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                <span>Teklif toplanıyor</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Bekleyen Şoför Teklifleri</span>
                <span class="p-2.5 rounded-xl bg-amber-500/10 text-amber-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-white tracking-tight">2</div>
            <div class="text-xs text-amber-400 mt-2 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <span>Onayınızı bekliyor</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Başarılı Sevkiyat</span>
                <span class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-white tracking-tight">{{ $completedShipmentsCount }}</div>
            <div class="text-xs text-emerald-400 mt-2 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>%100 Güvenli Teslimat</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800/80 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200 group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Havuzda (Escrow) Bloke</span>
                <span class="p-2.5 rounded-xl bg-brand-500/10 text-brand-400 group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-extrabold text-white tracking-tight font-mono">
                {{ number_format($escrowBalance, 2, ',', '.') }} <span class="text-brand-500 text-xl">₺</span>
            </div>
            <div class="text-xs text-neutral-500 mt-2 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-500"></span>
                <span>Teslimat onayında aktarılır</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden flex flex-col">
            <div class="p-5 border-b border-neutral-800 flex items-center justify-between bg-neutral-900/60">
                <div class="flex items-center space-x-3">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping"></span>
                    <h3 class="text-sm font-bold text-white tracking-tight">Aktif Sevkiyat Radarı (Canlı Takip)</h3>
                </div>
                <span class="text-xs text-neutral-400 bg-neutral-800 px-3 py-1 rounded-lg border border-neutral-700">
                    GPS & PWA Canlı Akış
                </span>
            </div>

            <div class="relative w-full h-80 bg-neutral-950" wire:ignore id="cargoRadarMap"></div>

            <div class="p-4 bg-neutral-900/90 border-t border-neutral-800 flex flex-wrap items-center justify-between gap-4 text-xs">
                <div class="flex items-center space-x-4">
                    <div>
                        <span class="text-neutral-500 block">Sürücü & Araç</span>
                        <span class="text-neutral-200 font-semibold">Mehmet Demir (06 TR 992)</span>
                    </div>
                    <div class="border-l border-neutral-800 pl-4">
                        <span class="text-neutral-500 block">Rota</span>
                        <span class="text-neutral-200 font-semibold">Ankara &rarr; İzmir</span>
                    </div>
                    <div class="border-l border-neutral-800 pl-4">
                        <span class="text-neutral-500 block">Kalan Süre (ETA)</span>
                        <span class="text-brand-400 font-bold font-mono">~ 2 Sa 40 Dk</span>
                    </div>
                </div>
                <a href="{{ route('cargo-owner.shipments.index') }}" class="px-4 py-2 bg-neutral-800 hover:bg-neutral-700 text-white rounded-xl font-medium transition-colors">
                    Geniş Ekranda Aç
                </a>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-4 border-b border-neutral-800 mb-4">
                    <h3 class="text-sm font-bold text-white tracking-tight">Son Operasyon Bildirimleri</h3>
                    <span class="text-[11px] text-brand-400 font-medium cursor-pointer hover:underline">Tümünü Gör</span>
                </div>

                <div class="space-y-4">
                    @foreach($timeline as $item)
                        <div class="relative pl-6 pb-4 border-l border-neutral-800 last:border-l-0 last:pb-0">
                            <span class="absolute -left-1.5 top-0.5 w-3 h-3 rounded-full border-2 border-neutral-900 {{ $item['type'] === 'escrow' ? 'bg-brand-500' : ($item['type'] === 'shipment' ? 'bg-blue-500' : 'bg-emerald-500') }}"></span>
                            <div class="text-xs font-bold text-neutral-200">{{ $item['title'] }}</div>
                            <div class="text-xs text-neutral-400 mt-1 leading-relaxed">{{ $item['desc'] }}</div>
                            <div class="text-[10px] text-neutral-500 mt-1.5 font-mono">{{ $item['time'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="pt-4 border-t border-neutral-800 mt-4">
                <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800/80 flex items-center justify-between">
                    <div class="text-xs text-neutral-400">Yardıma mı ihtiyacınız var?</div>
                    <a href="{{ route('cargo-owner.support.index') }}" class="text-xs font-semibold text-brand-400 hover:text-brand-300">Destek Biletleri &rarr;</a>
                </div>
            </div>
        </div>

    </div>

</div>
