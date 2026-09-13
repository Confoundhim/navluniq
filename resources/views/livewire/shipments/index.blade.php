<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Canlı Sevkiyatlarım')]
class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $shipments = collect();

        if ($user && $user->cargoOwnerProfile) {
            $shipments = Load::with(['driverProfile.user'])
                ->where('cargo_owner_profile_id', $user->cargoOwnerProfile->id)
                ->whereIn('status', ['driver_assigned', 'on_the_way', 'delivered'])
                ->latest()
                ->get();
        }

        return [
            'shipments' => $shipments,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="border-b border-neutral-800 pb-4">
        <h2 class="text-xl font-bold text-white tracking-tight">Canlı Sevkiyatlarım</h2>
        <p class="text-xs text-neutral-400 mt-1">Yolda olan ve tamamlanan tüm taşımalarınızın canlı GPS takibi ve teslimat onayları.</p>
    </div>

    <div class="space-y-4">
        @forelse($shipments as $shipment)
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full {{ $shipment->status === 'on_the_way' ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' }} text-[10px] font-bold uppercase">
                            {{ $shipment->status === 'on_the_way' ? 'Yolda (Canlı Takip)' : ($shipment->status === 'delivered' ? 'Teslim Edildi' : 'Şoför Atandı') }}
                        </span>
                        <span class="text-xs text-neutral-500 font-mono">#NVL-{{ str_pad((string)$shipment->id, 5, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <div class="text-sm font-bold text-white">
                        {{ $shipment->pickup_location }} &rarr; {{ $shipment->delivery_location }}
                    </div>
                </div>
                <div>
                    <a href="{{ route('cargo-owner.shipments.show', $shipment->id) }}" class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs transition-colors shadow-lg shadow-brand-500/20">
                        Sevkiyatı Takip Et &rarr;
                    </a>
                </div>
            </div>
        @empty
            <div class="p-12 text-center text-xs text-neutral-500 bg-neutral-900 rounded-2xl border border-neutral-800">
                Aktif bir sevkiyatınız bulunmuyor.
            </div>
        @endforelse
    </div>
</div>
