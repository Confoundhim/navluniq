<?php

use App\Models\DriverVehicle;
use App\Models\Load;
use App\Services\LoadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('İlanlarım ve Teklifler')]
class extends Component {
    use WithPagination;

    public string $activeTab = 'active';

    private const ACTIVE_STATUSES = [Load::STATUS_ACTIVE, Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED, Load::STATUS_DISPUTED];

    private const PAST_STATUSES = [Load::STATUS_COMPLETED, Load::STATUS_CANCELLED];

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['active', 'past'], true) ? $tab : 'active';
        $this->resetPage();
    }

    public function cancelLoad(int $loadId, LoadService $loads): void
    {
        $profile = Auth::user()->cargoOwnerProfile;
        $load = Load::query()->whereKey($loadId)->where('cargo_owner_profile_id', $profile?->id)->first();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı.');

            return;
        }

        try {
            $loads->cancel($load, $profile);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', '#'.$load->id.' numaralı ilanınız iptal edildi.');
    }

    public function repeatLoad(int $loadId, LoadService $loads): void
    {
        $profile = Auth::user()->cargoOwnerProfile;
        $load = Load::query()->whereKey($loadId)->where('cargo_owner_profile_id', $profile?->id)->first();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı.');

            return;
        }

        try {
            $new = $loads->repeat($load, $profile);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'İlan #'.$new->id.' olarak yeniden yayınlandı. Yükleme tarihi yarın olarak ayarlandı.');
        $this->setTab('active');
    }

    public function with(): array
    {
        $profileId = (int) Auth::user()->cargoOwnerProfile?->id;

        $loads = Load::query()
            ->where('cargo_owner_profile_id', $profileId)
            ->whereIn('status', $this->activeTab === 'past' ? self::PAST_STATUSES : self::ACTIVE_STATUSES)
            ->with(['shipment'])
            ->withCount(['offers as pending_offers_count' => fn ($q) => $q->where('status', 'pending')])
            ->latest()
            ->paginate(50);

        return [
            'loads' => $loads,
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
        ];
    }
}; ?>

<div wire:poll.8s class="space-y-6">

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
        <div class="flex items-center gap-2">
            <button type="button" wire:click="setTab('active')" class="px-4 py-2 rounded-xl text-xs font-semibold transition-colors {{ $activeTab === 'active' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white border border-neutral-200 dark:border-neutral-800' }}">
                Aktif ilanlar
            </button>
            <button type="button" wire:click="setTab('past')" class="px-4 py-2 rounded-xl text-xs font-semibold transition-colors {{ $activeTab === 'past' ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/20' : 'bg-white dark:bg-neutral-900 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white border border-neutral-200 dark:border-neutral-800' }}">
                Geçmiş
            </button>
        </div>

        <a href="{{ route('cargo-owner.loads.create') }}" wire:navigate class="btn-primary py-2 text-xs">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni ilan</span>
        </a>
    </div>

    <div class="space-y-4">
        @forelse($loads as $load)
            @php
                $statusTone = match ($load->status) {
                    'active_seeking' => 'bg-blue-500/10 border-blue-500/20 text-blue-600 dark:text-blue-400',
                    'driver_assigned', 'on_the_way' => 'bg-brand-500/10 border-brand-500/20 text-brand-400',
                    'delivered' => 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400',
                    'completed' => 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400',
                    'disputed' => 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400',
                    default => 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-500 dark:text-neutral-400',
                };
                $pendingPayment = $load->status === 'driver_assigned' && $load->escrow_status === 'pending_payment';
            @endphp
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 hover:border-neutral-300 dark:hover:border-neutral-700/80 rounded-2xl p-5 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                <div class="space-y-3 flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-300 tabular-nums text-[11px] font-bold">#{{ $load->id }}</span>
                        <span class="px-2.5 py-1 rounded-full border text-[11px] font-bold {{ $statusTone }}">{{ $load->statusLabel() }}</span>
                        @if($load->status !== 'active_seeking' && $load->status !== 'cancelled')
                            <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $load->escrowLabel() }}</span>
                        @endif
                        @if($load->status === 'active_seeking')
                            <span class="px-2.5 py-1 rounded-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ (int) $load->pending_offers_count }} teklif</span>
                        @endif
                        <span class="text-xs text-neutral-500 font-medium">{{ ($load->published_at ?? $load->created_at)?->format('d.m.Y H:i') }}</span>
                    </div>

                    <div class="text-sm font-bold text-neutral-900 dark:text-white break-words">
                        {{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}
                    </div>

                    <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-500 dark:text-neutral-400">
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Araç:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($load->vehicle_type), $load->bodyLabel(), $load->loadKindLabel()])) }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Yük:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $load->goods_type }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Ağırlık:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Yükleme:</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $load->pickup_date?->format('d.m.Y') ?? '—' }}</span>
                        </div>
                        @if($load->e_irsaliye_no)
                            <div class="flex items-center gap-1">
                                <span class="text-neutral-500">e-İrsaliye:</span>
                                <span class="text-neutral-700 dark:text-neutral-300 tabular-nums">{{ $load->e_irsaliye_no }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-200 dark:border-neutral-800">
                    <div class="text-left lg:text-right">
                        <span class="text-[11px] text-neutral-500 uppercase tracking-wider block">{{ $load->status === 'active_seeking' ? 'Navlun bedeli' : 'Anlaşılan bedel' }}</span>
                        <div class="text-2xl font-black text-neutral-900 dark:text-white tabular-nums">
                            {{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} <span class="text-brand-500 text-lg">₺</span>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                        @if($load->status === 'active_seeking')
                            <a href="{{ route('cargo-owner.loads.offers', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-1.5">
                                Teklifler
                            </a>
                            <button type="button" wire:click="cancelLoad({{ $load->id }})" wire:confirm="Bu ilanı iptal etmek istediğinize emin misiniz? Bekleyen teklifler reddedilecek." class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-rose-500/10 text-neutral-700 dark:text-neutral-300 hover:text-rose-400 text-xs font-semibold transition-colors">
                                İptal et
                            </button>
                        @elseif($pendingPayment)
                            <a href="{{ route('cargo-owner.finance.payment', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center">
                                Ödemeye git
                            </a>
                            <button type="button" wire:click="cancelLoad({{ $load->id }})" wire:confirm="Şoför ataması yapılmış bu ilanı iptal etmek istediğinize emin misiniz?" class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-rose-500/10 text-neutral-700 dark:text-neutral-300 hover:text-rose-400 text-xs font-semibold transition-colors">
                                İptal et
                            </button>
                        @elseif($load->status === 'delivered')
                            <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-xs shadow-lg shadow-emerald-500/20 transition-all flex items-center justify-center">
                                Teslimatı onayla
                            </a>
                        @elseif(in_array($load->status, ['completed', 'cancelled'], true))
                            <button type="button" wire:click="repeatLoad({{ $load->id }})" wire:confirm="Bu ilan aynı bilgilerle ve yarınki yükleme tarihiyle yeniden yayınlanacak. Devam edilsin mi?" class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-800 dark:text-neutral-200 text-xs font-semibold transition-colors flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span>Tekrar yayınla</span>
                            </button>
                            @if($load->status === 'completed')
                                <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-800 dark:text-neutral-200 text-xs font-semibold transition-colors flex items-center justify-center">Detay</a>
                            @endif
                        @else
                            <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center">
                                Sevkiyatı görüntüle
                            </a>
                        @endif
                    </div>
                </div>

            </div>
        @empty
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-100 dark:bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-neutral-900 dark:text-white">{{ $activeTab === 'past' ? 'Henüz tamamlanmış veya iptal edilmiş ilanınız yok' : 'Henüz aktif ilanınız yok' }}</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">Yeni bir ilan oluşturarak belgeleri doğrulanmış şoförlerden teklif toplamaya başlayabilirsiniz.</p>
                </div>
                <a href="{{ route('cargo-owner.loads.create') }}" wire:navigate class="btn-primary py-2 text-xs">
                    <span>Yeni ilan oluştur</span>
                </a>
            </div>
        @endforelse
    </div>

    @if($loads->hasPages())
        <div class="text-xs">{{ $loads->links() }}</div>
    @endif

</div>
