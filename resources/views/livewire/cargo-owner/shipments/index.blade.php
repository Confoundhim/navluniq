<?php

use App\Models\DriverVehicle;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Canlı Sevkiyatlarım ve Takip')]
class extends Component {
    use WithPagination;

    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'active', 'delivered', 'completed'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function with(): array
    {
        $profileId = (int) Auth::user()->cargoOwnerProfile?->id;

        $statuses = match ($this->filter) {
            'active' => [Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY],
            'delivered' => [Load::STATUS_DELIVERED],
            'completed' => [Load::STATUS_COMPLETED],
            default => [Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED, Load::STATUS_COMPLETED, Load::STATUS_DISPUTED],
        };

        $shipments = Load::query()
            ->where('cargo_owner_profile_id', $profileId)
            ->whereIn('status', $statuses)
            ->whereHas('shipment')
            ->with(['shipment.vehicle', 'driverProfile.user', 'driverProfile.activeVehicle'])
            ->latest('updated_at')
            ->paginate(15);

        return [
            'shipments' => $shipments,
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
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Sevkiyatlarım</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Şoför atanmış ilanlarınızı takip edin, teslimat kanıtlarını inceleyip onaylayın.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach(['all' => 'Tümü', 'active' => 'Yolda / bekliyor', 'delivered' => 'Onay bekliyor', 'completed' => 'Tamamlanan'] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" class="px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors {{ $filter === $key ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white border border-neutral-200 dark:border-neutral-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="space-y-4">
        @forelse($shipments as $load)
            @php
                $shipment = $load->shipment;
                $vehicle = $shipment?->vehicle ?? $load->driverProfile?->activeVehicle;
                $statusTone = match ($load->status) {
                    'driver_assigned' => 'bg-blue-500/10 border-blue-500/20 text-blue-600 dark:text-blue-400',
                    'on_the_way' => 'bg-brand-500/10 border-brand-500/20 text-brand-400',
                    'delivered' => 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400',
                    'completed' => 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400',
                    'disputed' => 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400',
                    default => 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300',
                };
            @endphp
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 hover:border-neutral-300 dark:hover:border-neutral-700/80 rounded-2xl p-6 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                <div class="space-y-3 flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-300 tabular-nums text-[11px] font-bold">#{{ $load->id }}</span>
                        <span class="px-2.5 py-1 rounded-full border text-[11px] font-bold {{ $statusTone }}">{{ $load->statusLabel() }}</span>
                        <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $load->escrowLabel() }}</span>
                        <span class="text-xs text-neutral-500 font-medium">{{ $load->updated_at?->format('d.m.Y H:i') }}</span>
                    </div>

                    <div class="text-sm font-bold text-neutral-900 dark:text-white break-words">
                        {{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}
                    </div>

                    <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-500 dark:text-neutral-400">
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Şoför:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-semibold">{{ $load->driverProfile?->user?->full_name ?: 'Henüz atanmadı' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Plaka:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-mono font-semibold">{{ $vehicle?->plate ?: '—' }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Araç:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $vehicleTypes[$vehicle?->vehicle_type ?? $load->vehicle_type] ?? $load->vehicle_type }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-neutral-500">Yük:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $load->goods_type }} ({{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg)</span>
                        </div>
                        @if($shipment?->delivered_at)
                            <div class="flex items-center gap-1.5">
                                <span class="text-neutral-500">Teslim:</span>
                                <span class="text-neutral-800 dark:text-neutral-200">{{ $shipment->delivered_at->format('d.m.Y H:i') }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-200 dark:border-neutral-800">
                    <div class="text-left lg:text-right">
                        <span class="text-[11px] text-neutral-500 uppercase tracking-wider block">Navlun bedeli</span>
                        <div class="text-2xl font-black text-neutral-900 dark:text-white tabular-nums">
                            {{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} <span class="text-brand-500 text-base">₺</span>
                        </div>
                    </div>

                    <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="w-full sm:w-auto px-5 py-2.5 rounded-xl {{ $load->status === 'delivered' ? 'bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/20' : 'bg-brand-500 hover:bg-brand-600 shadow-brand-500/20' }} text-white font-bold text-xs shadow-lg transition-all flex items-center justify-center gap-2">
                        <span>{{ $load->status === 'delivered' ? 'Teslimatı onayla' : ($load->status === 'driver_assigned' && $load->escrow_status === 'pending_payment' ? 'Ödeme ve detay' : 'Sevkiyatı görüntüle') }}</span>
                    </a>
                </div>

            </div>
        @empty
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-100 dark:bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-neutral-900 dark:text-white">Henüz sevkiyatınız yok</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">Bir teklifi kabul ettiğinizde sevkiyat kaydı oluşur ve burada listelenir.</p>
                </div>
            </div>
        @endforelse
    </div>

    @if($shipments->hasPages())
        <div class="text-xs">{{ $shipments->links() }}</div>
    @endif

</div>
