<?php

use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Services\OfferService;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('İlan Havuzu ve Tekliflerim')]
class extends Component {
    use WithPagination;

    public string $tab = 'pool';

    public string $search = '';

    public string $vehicleType = '';

    public bool $offerModalOpen = false;

    #[Locked]
    public ?int $selectedLoadId = null;

    public string $amount = '';

    public string $estimated_days = '1';

    public string $message = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedVehicleType(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['pool', 'offers', 'external'], true) ? $tab : 'pool';
        $this->resetPage();
    }

    private function profile()
    {
        return Auth::user()->driverProfile;
    }

    /** Teklif verilebilir açık ilanlar: şoförün aktif teklifi olan ilanlar hariç. */
    private function poolQuery(): Builder
    {
        $profileId = $this->profile()?->id ?? 0;

        return Load::query()
            ->with('cargoOwnerProfile.user')
            ->where('status', Load::STATUS_ACTIVE)
            ->where('visibility', 'public')
            ->whereDoesntHave('offers', fn (Builder $q) => $q->where('driver_profile_id', $profileId)->whereIn('status', ['pending', 'accepted']))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w->where('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term));
            })
            ->when($this->vehicleType !== '', fn (Builder $q) => $q->where('vehicle_type', $this->vehicleType))
            ->latest('published_at')
            ->latest('id');
    }

    private function externalQuery(): Builder
    {
        $premium = $this->profile()?->isPremium() ?? false;

        return ScrapedLoad::query()
            ->with('scraper')
            ->where('status', 'parsed_success')
            ->where('visibility', 'public')
            ->when(! $premium, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('available_to_free_at')->orWhere('available_to_free_at', '<=', now())))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(fn (Builder $w) => $w->where('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term));
            })
            ->latest('id');
    }

    public function openOffer(int $loadId): void
    {
        $load = $this->poolQuery()->whereKey($loadId)->first();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı veya artık teklif kabul etmiyor.');

            return;
        }

        $this->selectedLoadId = $load->id;
        $this->amount = $load->price !== null ? number_format((float) $load->price, 2, '.', '') : '';
        $this->estimated_days = '1';
        $this->message = '';
        $this->resetErrorBag();
        $this->offerModalOpen = true;
    }

    public function closeOffer(): void
    {
        $this->offerModalOpen = false;
        $this->selectedLoadId = null;
        $this->resetErrorBag();
    }

    public function submitOffer(OfferService $offers): void
    {
        $min = Settings::float('min_load_price');

        $this->validate([
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:99999999'],
            'estimated_days' => ['required', 'integer', 'min:1', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required' => 'Teklif tutarı zorunludur.',
            'amount.numeric' => 'Teklif tutarı sayısal olmalıdır.',
            'amount.min' => 'Teklif tutarı en az '.number_format($min, 2, ',', '.').' ₺ olmalıdır.',
            'estimated_days.min' => 'Tahmini süre en az 1 gün olmalıdır.',
            'estimated_days.max' => 'Tahmini süre en fazla 30 gün olabilir.',
        ]);

        $profile = $this->profile();
        $load = $this->selectedLoadId ? Load::query()->whereKey($this->selectedLoadId)->first() : null;

        if (! $profile || ! $load) {
            $this->addError('amount', 'İlan bulunamadı.');

            return;
        }

        try {
            $offers->submit($profile, $load, (float) $this->amount, trim($this->message) ?: null, (int) $this->estimated_days);
        } catch (\RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->closeOffer();
        $this->reset(['amount', 'estimated_days', 'message']);
        $this->tab = 'offers';
        $this->resetPage();
        session()->flash('success_message', 'Teklifiniz iletildi. Yük sahibi değerlendirdiğinde bilgilendirileceksiniz.');
    }

    public function withdrawOffer(OfferService $offers, int $offerId): void
    {
        $profile = $this->profile();
        $offer = Offer::query()->where('driver_profile_id', $profile?->id ?? 0)->whereKey($offerId)->first();

        if (! $profile || ! $offer) {
            session()->flash('error_message', 'Teklif bulunamadı.');

            return;
        }

        try {
            $offers->withdraw($offer, $profile);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Teklifiniz geri çekildi.');
    }

    public function with(): array
    {
        $profile = $this->profile();
        $profileId = $profile?->id ?? 0;

        $data = [
            'profile' => $profile,
            'kycApproved' => $profile?->isKycApproved() ?? false,
            'hasActiveVehicle' => $profile ? $profile->activeVehicle()->exists() : false,
            'isPremium' => $profile?->isPremium() ?? false,
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
            'minPrice' => Settings::float('min_load_price'),
            'selectedLoad' => $this->selectedLoadId ? Load::query()->with('cargoOwnerProfile.user')->whereKey($this->selectedLoadId)->first() : null,
            'loads' => null,
            'offers' => null,
            'externalLoads' => null,
        ];

        if ($this->tab === 'offers') {
            $data['offers'] = Offer::query()->with('cargoLoad')->where('driver_profile_id', $profileId)->latest('id')->paginate(15);
        } elseif ($this->tab === 'external') {
            $data['externalLoads'] = $this->externalQuery()->paginate(15);
        } else {
            $data['loads'] = $this->poolQuery()->paginate(15);
        }

        return $data;
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">İlan Havuzu ve Tekliflerim</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Açık ilanlara teklif verin, tekliflerinizi takip edin ve dış kaynaklı ilanları inceleyin.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach(['pool' => 'İlan havuzu', 'offers' => 'Tekliflerim', 'external' => 'Dış kaynak ilanlar'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    class="px-4 py-2 rounded-xl font-bold border transition-colors {{ $tab === $key ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-white dark:bg-neutral-900 border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if(! $kycApproved)
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <span>Teklif verebilmek için sürücü belgelerinizin onaylanmış olması gerekir.</span>
            <a href="{{ route('driver.profile.index') }}" wire:navigate class="shrink-0 font-bold underline">Belgeleri yükle</a>
        </div>
    @elseif(! $hasActiveVehicle)
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <span>Teklif verebilmek için en az bir aktif aracınız olmalı.</span>
            <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="shrink-0 font-bold underline">Araç ekle</a>
        </div>
    @endif

    @if($tab === 'pool')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Rota ara</label>
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Şehir veya ilçe" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Araç türü</label>
                    <select wire:model.live="vehicleType" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        <option value="">Tümü</option>
                        @foreach($vehicleTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="space-y-3">
                @forelse($loads as $load)
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 hover:border-brand-500/40 rounded-xl transition-colors flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                        <div class="space-y-1.5 flex-1">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">
                                {{ $load->goods_type }} · {{ $vehicleTypes[$load->vehicle_type] ?? $load->vehicle_type }}
                                @if($load->weight) · {{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg @endif
                                @if($load->volume) · {{ number_format((int) ($load->volume ?? 0), 0, ',', '.') }} m³ @endif
                            </div>
                            <div class="text-neutral-500">
                                Yükleme: {{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}
                                @if($load->delivery_date) · Teslim: {{ $load->delivery_date->format('d.m.Y H:i') }} @endif
                                · Yük sahibi: {{ $load->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}
                            </div>
                        </div>
                        <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                            <div class="text-lg font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</div>
                            <button type="button" wire:click="openOffer({{ $load->id }})" class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">Teklif ver</button>
                        </div>
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Filtrelerinize uyan açık ilan bulunmuyor.</div>
                @endforelse
            </div>

            @if($loads && $loads->hasPages())
                <div class="pt-2">{{ $loads->links() }}</div>
            @endif
        </div>
    @endif

    @if($tab === 'offers')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
            @forelse($offers as $offer)
                @php $offerLoad = $offer->cargoLoad; @endphp
                <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                    <div class="space-y-1.5 flex-1">
                        <div class="text-sm font-bold text-neutral-900 dark:text-white">
                            @if($offerLoad)
                                {{ $offerLoad->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $offerLoad->delivery_location }}
                            @else
                                İlan kaldırılmış
                            @endif
                        </div>
                        <div class="text-neutral-500 dark:text-neutral-400">
                            Teklifim: <span class="text-neutral-900 dark:text-white tabular-nums font-bold">{{ number_format((float) ($offer->amount ?? 0), 2, ',', '.') }} ₺</span>
                            @if($offer->estimated_days) · {{ (int) $offer->estimated_days }} gün @endif
                            · Verildi: {{ $offer->created_at?->format('d.m.Y H:i') }}
                        </div>
                        <div class="text-neutral-500">
                            @if($offer->status === 'pending' && $offer->expires_at)
                                Geçerlilik: {{ $offer->expires_at->format('d.m.Y H:i') }} tarihine kadar
                            @elseif($offer->responded_at)
                                Sonuçlandı: {{ $offer->responded_at->format('d.m.Y H:i') }}
                            @endif
                            @if($offerLoad) · İlan durumu: {{ $offerLoad->statusLabel() }} @endif
                        </div>
                    </div>
                    <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border
                            {{ $offer->status === 'accepted' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : ($offer->status === 'pending' ? 'bg-amber-500/10 border-amber-500/20 text-amber-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300') }}">
                            {{ \App\Models\Offer::STATUS_LABELS[$offer->status] ?? $offer->status }}
                        </span>
                        @if($offer->status === 'pending')
                            <button type="button" wire:click="withdrawOffer({{ $offer->id }})" wire:confirm="Teklifinizi geri çekmek istediğinize emin misiniz?" class="text-rose-400 hover:text-rose-300 font-bold">Geri çek</button>
                        @elseif($offer->status === 'accepted' && $offerLoad)
                            <a href="{{ route('driver.shipments.show', $offerLoad->id) }}" wire:navigate class="text-brand-400 font-bold hover:underline">Sevkiyata git</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz teklif vermediniz.</div>
            @endforelse

            @if($offers && $offers->hasPages())
                <div class="pt-2">{{ $offers->links() }}</div>
            @endif
        </div>
    @endif

    @if($tab === 'external')
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Rota ara</label>
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Şehir veya ilçe" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                </div>
                <div class="text-[11px] text-neutral-500 sm:self-end leading-relaxed">
                    Bu ilanlar izinli dış kaynaklardan derlenir; NavlunIQ havuz ödemesi kapsamında değildir. Teklif ve anlaşma doğrudan ilan sahibiyle yapılır.
                    @if(! $isPremium)
                        Yeni dış kaynak ilanlar önce premium üyelere açılır; telefon numarasının tamamı premium üyelere gösterilir.
                        <a href="{{ route('driver.premium.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Premium</a>
                    @endif
                </div>
            </div>

            <div class="space-y-3">
                @forelse($externalLoads as $item)
                    @php $plainPhone = $item->plainPhone(); $fullPhone = $isPremium && $plainPhone ? \App\Support\Phone::format($plainPhone) : null; @endphp
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                        <div class="space-y-1.5 flex-1">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $item->pickup_location ?: 'Belirtilmemiş' }} <span class="text-amber-400">&rarr;</span> {{ $item->delivery_location ?: 'Belirtilmemiş' }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">
                                {{ $item->goods_type ?: 'Yük türü belirtilmemiş' }}
                                @if($item->weight) · {{ number_format((int) ($item->weight ?? 0), 0, ',', '.') }} kg @endif
                                @if($item->scraper) · Kaynak: {{ $item->scraper->name }} @endif
                            </div>
                            <div class="text-neutral-500">Derlendi: {{ $item->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 border-neutral-200 dark:border-neutral-800 pt-3 sm:pt-0">
                            <div class="text-neutral-900 dark:text-white font-mono font-bold">
                                @if($item->price !== null)
                                    {{ number_format((float) $item->price, 2, ',', '.') }} ₺
                                @else
                                    Fiyat belirtilmemiş
                                @endif
                            </div>
                            @if($fullPhone)
                                                                <div class="flex items-center gap-3">
                                    <a href="tel:+90{{ $plainPhone }}" class="text-brand-400 font-mono font-bold hover:underline">{{ $fullPhone }}</a>
                                    <a href="https://wa.me/90{{ $plainPhone }}" target="_blank" rel="noopener" class="text-emerald-400 font-bold hover:underline">WhatsApp</a>
                                </div>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400 font-mono">{{ $plainPhone ? '0'.substr($plainPhone, 0, 3).' *** ** '.substr($plainPhone, -2) : 'Bilinmiyor' }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Şu anda görüntülenebilir dış kaynak ilan yok.</div>
                @endforelse
            </div>

            @if($externalLoads && $externalLoads->hasPages())
                <div class="pt-2">{{ $externalLoads->links() }}</div>
            @endif
        </div>
    @endif

    @if($offerModalOpen && $selectedLoad)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeOffer"></div>
            <form wire:submit.prevent="submitOffer" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Teklif ver</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $selectedLoad->pickup_location }} &rarr; {{ $selectedLoad->delivery_location }} · İlan fiyatı {{ number_format((float) ($selectedLoad->price ?? 0), 2, ',', '.') }} ₺</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Teklif tutarı (₺)</label>
                        <input type="number" step="0.01" min="{{ $minPrice }}" wire:model="amount" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white font-mono focus:border-brand-500 focus:outline-none">
                        @error('amount') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        <span class="text-[11px] text-neutral-500 mt-1 block">Asgari {{ number_format($minPrice, 2, ',', '.') }} ₺</span>
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tahmini süre (gün)</label>
                        <input type="number" min="1" max="30" wire:model="estimated_days" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white font-mono focus:border-brand-500 focus:outline-none">
                        @error('estimated_days') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Mesaj (isteğe bağlı)</label>
                    <textarea wire:model="message" rows="3" maxlength="1000" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none"></textarea>
                    @error('message') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                @if(! $kycApproved)
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300">
                        Teklif verebilmek için belgelerinizin onaylanmış olması gerekir.
                        <a href="{{ route('driver.profile.index') }}" wire:navigate class="font-bold underline">Belgeleri yükle</a>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" wire:click="closeOffer" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 font-semibold">Kapat</button>
                    </div>
                @else
                    @if(! $hasActiveVehicle)
                        <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300">
                            Teklif verebilmek için aktif bir aracınız olmalı.
                            <a href="{{ route('driver.vehicles.index') }}" wire:navigate class="font-bold underline">Araç ekle</a>
                        </div>
                    @endif
                    <div class="flex gap-3 pt-2">
                        <button type="button" wire:click="closeOffer" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 font-semibold">Vazgeç</button>
                        <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="submitOffer">Teklifi gönder</span>
                            <span wire:loading wire:target="submitOffer">Gönderiliyor...</span>
                        </button>
                    </div>
                @endif
            </form>
        </div>
    @endif
</div>
