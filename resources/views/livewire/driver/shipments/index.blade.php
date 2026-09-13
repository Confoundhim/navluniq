<?php

use App\Models\Load;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('Sevkiyatlarım')]
class extends Component {
    use WithPagination;

    public string $tab = 'active';

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'past' ? 'past' : 'active';
        $this->resetPage();
    }

    public function with(): array
    {
        $profileId = Auth::user()->driverProfile?->id ?? 0;

        $statuses = $this->tab === 'past'
            ? [Load::STATUS_COMPLETED, Load::STATUS_CANCELLED]
            : [Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED, Load::STATUS_DISPUTED];

        return [
            'loads' => Load::query()
                ->with(['cargoOwnerProfile.user', 'shipment'])
                ->where('driver_profile_id', $profileId)
                ->whereHas('shipment')
                ->whereIn('status', $statuses)
                ->latest('id')
                ->paginate(15),
        ];
    }
}; ?>

<div class="space-y-6">

    <div class="border-b border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Sevkiyatlarım</h2>
            <p class="text-xs text-neutral-400 mt-1">Teklifi kabul edilmiş yüklerinizin sevkiyat ve ödeme durumu.</p>
        </div>
        <div class="flex gap-2 text-xs">
            <button type="button" wire:click="setTab('active')" class="px-4 py-2 rounded-xl font-bold border transition-colors {{ $tab === 'active' ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-neutral-900 border-neutral-800 text-neutral-400 hover:text-white' }}">Aktif</button>
            <button type="button" wire:click="setTab('past')" class="px-4 py-2 rounded-xl font-bold border transition-colors {{ $tab === 'past' ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-neutral-900 border-neutral-800 text-neutral-400 hover:text-white' }}">Geçmiş</button>
        </div>
    </div>

    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-3">
        @forelse($loads as $load)
            <div class="p-4 bg-neutral-950 border border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                <div class="space-y-1.5 flex-1">
                    <div class="text-sm font-bold text-white">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                    <div class="text-neutral-400">
                        Yük sahibi: {{ $load->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}
                        · Yükleme: {{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}
                        · Navlun: <span class="text-white font-mono font-bold">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</span>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <span class="px-2.5 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 font-bold text-[10px]">{{ $load->statusLabel() }}</span>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $load->isPaid() ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-amber-500/10 border-amber-500/20 text-amber-400' }}">{{ $load->escrowLabel() }}</span>
                    </div>
                </div>
                <div class="shrink-0 border-t sm:border-t-0 border-neutral-800 pt-3 sm:pt-0">
                    <a href="{{ route('driver.shipments.show', $load->id) }}" wire:navigate class="inline-block px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">Sevkiyatı aç</a>
                </div>
            </div>
        @empty
            <div class="p-6 bg-neutral-950 border border-dashed border-neutral-800 rounded-xl text-center text-xs text-neutral-400">
                {{ $tab === 'past' ? 'Henüz tamamlanmış sevkiyatınız yok.' : 'Henüz aktif sevkiyatınız yok.' }}
            </div>
        @endforelse

        @if($loads->hasPages())
            <div class="pt-2">{{ $loads->links() }}</div>
        @endif
    </div>
</div>
