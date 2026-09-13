<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Canlı Sevkiyatlarım ve Takip')]
class extends Component {
    public string $filter = 'all'; // 'all', 'on_the_way', 'delivered'

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function with(): array
    {
        $user = Auth::user();
        $shipments = collect();

        if ($user && $user->cargoOwnerProfile) {
            $query = Load::with(['driverProfile.user', 'driverProfile.activeVehicle'])
                ->where('cargo_owner_profile_id', (int) $user->cargoOwnerProfile->id);

            if ($this->filter === 'on_the_way') {
                $query->whereIn('status', ['driver_assigned', 'on_the_way']);
            } elseif ($this->filter === 'delivered') {
                $query->where('status', 'delivered');
            } else {
                $query->whereIn('status', ['driver_assigned', 'on_the_way', 'delivered', 'disputed']);
            }

            $shipments = $query->latest()->get();
        }

        return [
            'shipments' => $shipments,
        ];
    }
}; ?>

<div class="space-y-6">

    <!-- Başlık ve Filtreler -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Canlı Sevkiyatlarım & GPS Takip</h2>
            <p class="text-xs text-neutral-400 mt-1">Yolda olan araçlarınızı anlık izleyin, teslim kanıtlarını (POD) onaylayarak süreci tamamlayın.</p>
        </div>

        <!-- Filtre Butonları -->
        <div class="flex items-center gap-2">
            <button type="button" wire:click="setFilter('all')" class="px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors {{ $filter === 'all' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Tümü
            </button>
            <button type="button" wire:click="setFilter('on_the_way')" class="px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors {{ $filter === 'on_the_way' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Yolda Olanlar
            </button>
            <button type="button" wire:click="setFilter('delivered')" class="px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors {{ $filter === 'delivered' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Teslim Edilenler
            </button>
        </div>
    </div>

    <!-- Sevkiyat Kartları Listesi -->
    <div class="space-y-4">
        @forelse($shipments as $shipment)
            <div class="bg-neutral-900 border border-neutral-800 hover:border-neutral-700/80 rounded-2xl p-6 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                <!-- Sol Bölüm: Durum Rozetleri, Rota ve Şoför Bilgisi -->
                <div class="space-y-3 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-[11px] font-bold">
                            #NVL-{{ str_pad((string)$shipment->id, 5, '0', STR_PAD_LEFT) }}
                        </span>

                        @if($shipment->status === 'on_the_way')
                            <span class="px-2.5 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 text-[10px] font-bold uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-brand-500 animate-ping"></span>
                                Araç Seyir Halinde (Canlı GPS)
                            </span>
                        @elseif($shipment->status === 'driver_assigned')
                            <span class="px-2.5 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-[10px] font-bold uppercase tracking-wider">
                                Şoför Yükleme Noktasına Gidiyor
                            </span>
                        @elseif($shipment->status === 'delivered')
                            <span class="px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold uppercase tracking-wider">
                                ✓ Başarıyla Teslim Edildi
                            </span>
                        @elseif($shipment->status === 'disputed')
                            <span class="px-2.5 py-1 rounded-full bg-rose-500/10 border border-rose-500/20 text-rose-400 text-[10px] font-bold uppercase tracking-wider">
                                ⚠ Uyuşmazlık İnceleniyor
                            </span>
                        @endif

                        <span class="text-xs text-neutral-500 font-medium">
                            {{ $shipment->created_at?->format('d.m.Y H:i') }}
                        </span>
                    </div>

                    <!-- Rota -->
                    <div class="flex items-center space-x-3 text-sm font-bold text-white">
                        <span>{{ $shipment->pickup_location }}</span>
                        <span class="text-brand-500">&rarr;</span>
                        <span>{{ $shipment->delivery_location }}</span>
                    </div>

                    <!-- Şoför ve Araç Bilgisi -->
                    <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-400">
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Sürücü:</span>
                            <span class="text-neutral-200 font-semibold">{{ $shipment->driverProfile?->user?->full_name ?? 'Atanmış Sürücü' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Araç Tipi:</span>
                            <span class="text-neutral-200 font-medium uppercase">{{ str_replace('_', ' ', $shipment->vehicle_type) }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Yük:</span>
                            <span class="text-neutral-200 font-medium">{{ $shipment->goods_type }} ({{ number_format($shipment->weight) }} Kg)</span>
                        </div>
                    </div>
                </div>

                <!-- Sağ Bölüm: Escrow Durumu ve Aksiyon Butonu -->
                <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                    <div class="text-left lg:text-right">
                        <span class="text-[10px] text-neutral-500 uppercase tracking-wider block">Bloke Navlun Bedeli</span>
                        <div class="text-2xl font-black text-white font-mono">
                            {{ number_format((float)$shipment->price, 2, ',', '.') }} <span class="text-brand-500 text-base">₺</span>
                        </div>
                        <span class="text-[10px] {{ $shipment->escrow_status === 'paid_in_escrow' ? 'text-brand-400 font-semibold' : 'text-emerald-400' }}">
                            {{ $shipment->escrow_status === 'paid_in_escrow' ? 'PayTR Havuzunda Bloke' : 'Şoföre Aktarıldı' }}
                        </span>
                    </div>

                    <a href="{{ route('cargo-owner.shipments.show', $shipment->id) }}" class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-2 active:scale-95">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <span>Canlı Radar & Teslimat Onayı</span>
                    </a>
                </div>

            </div>
        @empty
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Sevkiyat Kaydı Bulunamadı</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">İlanlarınıza gelen teklifleri onaylayıp güvenli ödemeyi tamamladığınızda sevkiyatlarınız burada listelenecektir.</p>
                </div>
            </div>
        @endforelse
    </div>

</div>
