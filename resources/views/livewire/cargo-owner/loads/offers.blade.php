<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\Offer;
use App\Services\OfferAcceptanceService;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Gelen Şoför Teklifleri & Seçim')]
class extends Component {
    public int $loadId = 0;
    public ?Load $load = null;
    public string $sortBy = 'price_asc';
    public bool $driverModalOpen = false;
    public ?array $selectedDriver = null;

    public function mount(int $loadId): void
    {
        $user=Auth::user(); abort_unless($user?->cargoOwnerProfile,403);
        $this->loadId=$loadId;
        $this->load=Load::query()->whereKey($loadId)->where('cargo_owner_profile_id',$user->cargoOwnerProfile->id)->firstOrFail();
    }
    public function getOffersProperty(): array
    {
        $query=Offer::query()->with(['driverProfile.user','driverProfile.activeVehicle'])->where('load_id',$this->loadId)->where('status','pending');
        $this->sortBy==='rating_desc' ? $query->latest() : $query->orderBy('amount');
        return $query->get()->map(function(Offer $offer):array {
            $profile=$offer->driverProfile; $vehicle=$profile?->activeVehicle;
            return ['id'=>$offer->id,'driver_id'=>$profile?->id,'driver_name'=>$profile?->user?->full_name ?? 'Şoför','rating'=>0.0,'reviews_count'=>0,
            'kyc_verified'=>$profile?->kyc_status==='approved','vehicle_plate'=>$vehicle?->plate ?? '—','vehicle_model'=>trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')) ?: '—',
            'vehicle_type'=>$vehicle?->vehicle_type ?? '—','offered_price'=>(float)$offer->amount,'estimated_eta'=>'Belirtilmedi','message'=>$offer->message ?? ''];
        })->all();
    }
    public function showDriverProfile(int $driverId): void
    {
        $this->selectedDriver=collect($this->offers)->firstWhere('driver_id',$driverId); $this->driverModalOpen=$this->selectedDriver!==null;
    }
    public function acceptOffer(int $offerId, float $price): void
    {
        abort_unless($this->load,404); $offer=Offer::query()->whereKey($offerId)->where('load_id',$this->load->id)->firstOrFail();
        app(OfferAcceptanceService::class)->accept($this->load,$offer,(int)Auth::id());
        $this->redirect(route('cargo-owner.finance.payment',$this->load->id),navigate:true);
    }
}; ?>

<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <a href="{{ route('cargo-owner.loads.index') }}" class="text-xs text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
                &larr; İlanlarıma Geri Dön
            </a>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span>İlana Gelen Teklifler</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 font-mono text-xs font-bold border border-brand-500/20">
                    #NVL-{{ str_pad((string)$loadId, 5, '0', STR_PAD_LEFT) }}
                </span>
            </h2>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-xs text-neutral-400">Sırala:</span>
            <select wire:model.live="sortBy" class="bg-neutral-900 border border-neutral-800 rounded-xl px-3 py-2 text-xs text-neutral-200 focus:border-brand-500 focus:outline-none">
                <option value="price_asc">En Düşük Fiyat</option>
                <option value="rating_desc">En Yüksek Şoför Puanı</option>
            </select>
        </div>
    </div>

    @if($load)
        <div class="p-5 rounded-2xl bg-neutral-900 border border-neutral-800 flex flex-wrap items-center justify-between gap-4 text-xs">
            <div class="flex items-center gap-3">
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                <div>
                    <span class="text-neutral-500 block">Rota</span>
                    <span class="text-white font-bold">{{ $load->pickup_location }} &rarr; {{ $load->delivery_location }}</span>
                </div>
            </div>
            <div>
                <span class="text-neutral-500 block">Araç & Yük</span>
                <span class="text-neutral-200 font-medium uppercase">{{ str_replace('_', ' ', $load->vehicle_type) }} • {{ $load->goods_type }}</span>
            </div>
            <div>
                <span class="text-neutral-500 block">Hedef Bütçeniz</span>
                <span class="text-brand-400 font-bold font-mono text-sm">{{ number_format((float)$load->price, 2, ',', '.') }} ₺</span>
            </div>
        </div>
    @endif

    <div class="space-y-4">
        @foreach($this->offers as $offer)
            <div class="bg-neutral-900 border border-neutral-800 hover:border-neutral-700/80 rounded-2xl p-6 transition-all duration-200 flex flex-col lg:flex-row lg:items-center justify-between gap-6">

                <div class="space-y-3 flex-1">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-neutral-800 border border-neutral-700 flex items-center justify-center font-black text-brand-500 text-base shrink-0">
                            {{ strtoupper(substr($offer['driver_name'], 0, 1)) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="showDriverProfile({{ $offer['driver_id'] }})" class="text-sm font-bold text-white hover:text-brand-400 transition-colors">
                                    {{ $offer['driver_name'] }}
                                </button>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold">
                                    ✓ KYC Onaylı
                                </span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-neutral-400 mt-0.5">
                                <span class="text-amber-400 font-semibold flex items-center gap-1">
                                    ★ {{ $offer['rating'] }}
                                </span>
                                <span class="text-neutral-600">•</span>
                                <span>{{ $offer['reviews_count'] }} Sevkiyat Yorumu</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 text-xs text-neutral-400 pt-1">
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Plaka:</span>
                            <span class="text-neutral-200 font-mono font-semibold">{{ $offer['vehicle_plate'] }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Araç:</span>
                            <span class="text-neutral-200 font-medium">{{ $offer['vehicle_model'] }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-neutral-500">Tahmini Teslim:</span>
                            <span class="text-emerald-400 font-medium">{{ $offer['estimated_eta'] }}</span>
                        </div>
                    </div>

                    <p class="text-xs text-neutral-300 bg-neutral-950/60 p-3 rounded-xl border border-neutral-800/80 italic leading-relaxed">
                        "{{ $offer['message'] }}"
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col items-start sm:items-center lg:items-end justify-between gap-4 border-t lg:border-t-0 pt-4 lg:pt-0 border-neutral-800">
                    <div class="text-left lg:text-right">
                        <span class="text-[10px] text-neutral-500 uppercase tracking-wider block">Şoförün Teklifi</span>
                        <div class="text-3xl font-black text-white font-mono">
                            {{ number_format((float)$offer['offered_price'], 2, ',', '.') }} <span class="text-brand-500 text-xl">₺</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <button type="button" wire:click="showDriverProfile({{ $offer['driver_id'] }})" class="px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                            Profili İncele
                        </button>

                        <button type="button" wire:click="acceptOffer({{ $offer['id'] }}, {{ $offer['offered_price'] }})" class="flex-1 sm:flex-none px-6 py-2.5 rounded-xl bg-gradient-to-r from-brand-500 to-amber-500 hover:from-brand-600 hover:to-amber-600 text-white font-bold text-xs shadow-lg shadow-brand-500/25 transition-all flex items-center justify-center gap-2 active:scale-95">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>Teklifi Kabul Et & Öde</span>
                        </button>
                    </div>
                </div>

            </div>
        @endforeach
    </div>

    @if($driverModalOpen && $selectedDriver)
        <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/80 backdrop-blur-sm" wire:click="$set('driverModalOpen', false)"></div>
            <div class="relative w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-brand-500/10 text-brand-500 border border-brand-500/20 flex items-center justify-center font-black text-lg">
                            {{ strtoupper(substr($selectedDriver['driver_name'], 0, 1)) }}
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-white">{{ $selectedDriver['driver_name'] }}</h3>
                            <span class="text-xs text-emerald-400 font-semibold">✓ Ehliyet, SRC ve Psikoteknik AI Onaylı</span>
                        </div>
                    </div>
                    <button wire:click="$set('driverModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800/80">
                        <span class="text-neutral-500 block">Araç Plakası</span>
                        <span class="text-white font-bold font-mono">{{ $selectedDriver['vehicle_plate'] }}</span>
                    </div>
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800/80">
                        <span class="text-neutral-500 block">Araç Tipi & Model</span>
                        <span class="text-white font-semibold">{{ $selectedDriver['vehicle_model'] }}</span>
                    </div>
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800/80">
                        <span class="text-neutral-500 block">Platform Başarı Puanı</span>
                        <span class="text-amber-400 font-bold">★ {{ $selectedDriver['rating'] }} / 5.0</span>
                    </div>
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800/80">
                        <span class="text-neutral-500 block">Tamamlanan İş Sayısı</span>
                        <span class="text-emerald-400 font-bold">{{ $selectedDriver['reviews_count'] }} Başarılı Teslimat</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <span class="text-xs font-bold text-neutral-300 block">Son Yük Sahibi Değerlendirmeleri</span>
                    <div class="space-y-2 text-xs">
                        <div class="p-3 rounded-xl bg-neutral-950 border border-neutral-800/60 space-y-1">
                            <div class="flex items-center justify-between text-neutral-400 text-[11px]">
                                <span class="font-semibold text-neutral-200">Kaya İnşaat A.Ş.</span>
                                <span class="text-amber-400">★★★★★</span>
                            </div>
                            <p class="text-neutral-400">"Vaktinde geldi, yükü hasarsız teslim etti. Çok profesyonel."</p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('driverModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Kapat
                    </button>
                    <button type="button" wire:click="acceptOffer({{ $selectedDriver['id'] }}, {{ $selectedDriver['offered_price'] }})" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        Bu Şoförü Seç & Ödemeye Geç
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
