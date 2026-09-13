<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('İlanlarım ve Teklif Yönetimi')]
class extends Component {
    public string $activeTab = 'active';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function cancelLoad(int $loadId): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $cargoOwnerProfile = $user->cargoOwnerProfile;

        if ($cargoOwnerProfile) {
            $load = Load::where('id', $loadId)
                ->where('cargo_owner_profile_id', $cargoOwnerProfile->id)
                ->first();

            if ($load && $load->status === 'active_seeking') {
                $load->status = 'cancelled';
                $load->save();
                session()->flash('success_message', '#' . $load->id . ' numaralı ilanınız iptal edildi.');
            }
        }
    }

    public function repeatLoad(int $loadId): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $cargoOwnerProfile = $user->cargoOwnerProfile;

        if ($cargoOwnerProfile) {
            $oldLoad = Load::where('id', $loadId)
                ->where('cargo_owner_profile_id', $cargoOwnerProfile->id)
                ->first();

            if ($oldLoad) {
                $newLoad = $oldLoad->replicate();
                $newLoad->status = 'active_seeking';
                $newLoad->escrow_status = 'pending_payment';
                $newLoad->driver_profile_id = null;
                $newLoad->pickup_date = now()->addDay();
                $newLoad->delivery_date = now()->addDays(2);
                $newLoad->save();

                session()->flash('success_message', 'İlan başarıyla tekrarlandı ve yeni ilan olarak yayına alındı.');
            }
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $loads = collect();

        if ($user && $user->cargoOwnerProfile) {
            $query = Load::where('cargo_owner_profile_id', $user->cargoOwnerProfile->id);

            if ($this->activeTab === 'active') {
                $query->whereIn('status', ['active_seeking', 'driver_assigned']);
            } elseif ($this->activeTab === 'past') {
                $query->whereIn('status', ['delivered', 'cancelled']);
            }

            $loads = $query->latest()->get();
        }

        return [
            'loads' => $loads,
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success_message') }}</span>
            </div>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="setTab('active')" class="px-4 py-2 rounded-xl text-xs font-semibold transition-colors {{ $activeTab === 'active' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Yayındaki İlanlar (Teklif Toplayan)
            </button>
            <button type="button" wire:click="setTab('past')" class="px-4 py-2 rounded-xl text-xs font-semibold transition-colors {{ $activeTab === 'past' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-neutral-900 text-neutral-400 hover:text-white border border-neutral-800' }}">
                Geçmiş & Tamamlananlar
            </button>
        </div>

        <a href="{{ route('cargo-owner.loads.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs transition-colors shadow-lg shadow-brand-500/20">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni İlan Aç</span>
        </a>
    </div>

    <div class="space-y-4">
        @forelse($loads as $load)
            <div class="bg-neutral-900 border border-neutral-800 hover:border-neutral-700/80 rounded-2xl p-5 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                <div class="space-y-3 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-[11px] font-bold">
                            #NVL-{{ str_pad((string)$load->id, 5, '0', STR_PAD_LEFT) }}
                        </span>

                        @if($load->status === 'active_seeking')
                            <span class="px-2.5 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-[10px] font-bold uppercase tracking-wider flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                Teklif Topluyor
                            </span>
                        @elseif($load->status === 'delivered')
                            <span class="px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold uppercase tracking-wider">
                                ✓ Teslim Edildi
                            </span>
                        @elseif($load->status === 'cancelled')
                            <span class="px-2.5 py-1 rounded-full bg-neutral-800 text-neutral-500 text-[10px] font-bold uppercase tracking-wider">
                                İptal Edildi
                            </span>
                        @endif

                        <span class="text-xs text-neutral-500 font-medium">
                            {{ $load->created_at?->format('d.m.Y H:i') }}
                        </span>
                    </div>

                    <div class="flex items-center space-x-3 text-sm font-bold text-white">
                        <span>{{ $load->pickup_location }}</span>
                        <span class="text-brand-500">&rarr;</span>
                        <span>{{ $load->delivery_location }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-400">
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Araç:</span>
                            <span class="text-neutral-200 font-medium uppercase">{{ str_replace('_', ' ', $load->vehicle_type) }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Yük:</span>
                            <span class="text-neutral-200 font-medium">{{ $load->goods_type }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Ağırlık:</span>
                            <span class="text-neutral-200 font-medium">{{ number_format($load->weight) }} Kg</span>
                        </div>
                        @if($load->e_irsaliye_no)
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">e-İrsaliye:</span>
                                <span class="text-neutral-300 font-mono">{{ $load->e_irsaliye_no }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                    <div class="text-left lg:text-right">
                        <span class="text-[10px] text-neutral-500 uppercase tracking-wider block">Hedef Navlun Bütçesi</span>
                        <div class="text-2xl font-black text-white font-mono">
                            {{ number_format((float)$load->price, 2, ',', '.') }} <span class="text-brand-500 text-lg">₺</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        @if($load->status === 'active_seeking')
                            <a href="{{ route('cargo-owner.loads.offers', $load->id) }}" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <span>Teklifleri İncele</span>
                            </a>

                            <button type="button" wire:click="cancelLoad({{ $load->id }})" wire:confirm="Bu ilanı iptal etmek istediğinize emin misiniz?" class="p-2 rounded-xl bg-neutral-800 hover:bg-rose-500/10 text-neutral-400 hover:text-rose-400 transition-colors" title="İlanı İptal Et">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        @else
                            <button type="button" wire:click="repeatLoad({{ $load->id }})" class="px-4 py-2 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-200 text-xs font-semibold transition-colors flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span>Bu İlanı Tekrarla</span>
                            </button>
                        @endif
                    </div>
                </div>

            </div>
        @empty
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">İlan Bulunamadı</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">Yeni bir ilan oluşturarak onaylı şoförlerden teklif toplamaya başlayabilirsiniz.</p>
                </div>
                <a href="{{ route('cargo-owner.loads.create') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs shadow-lg shadow-brand-500/20 transition-all">
                    <span>Yeni İlan Oluştur</span>
                </a>
            </div>
        @endforelse
    </div>

</div>
