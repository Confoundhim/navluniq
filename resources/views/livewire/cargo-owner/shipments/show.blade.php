<?php

use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Shipment;
use App\Services\DriverLocationService;
use App\Services\ReviewService;
use App\Services\ShipmentService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Sevkiyat Takibi')]
class extends Component {
    #[Locked]
    public int $loadId = 0;

    public int $rating = 5;

    public string $review_comment = '';

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;

        if (! $this->ownerLoad()) {
            session()->flash('error_message', 'Sevkiyat bulunamadı veya size ait değil.');
            $this->redirect(route('cargo-owner.shipments.index'), navigate: true);
        }
    }

    private function ownerLoad(): ?Load
    {
        return Load::query()
            ->whereKey($this->loadId)
            ->where('cargo_owner_profile_id', (int) Auth::user()->cargoOwnerProfile?->id)
            ->with(['shipment.vehicle', 'shipment.evidence.uploader', 'driverProfile.user', 'driverProfile.activeVehicle'])
            ->first();
    }

    public function approveDelivery(ShipmentService $shipments): void
    {
        $load = $this->ownerLoad();

        if (! $load || ! $load->shipment) {
            session()->flash('error_message', 'Onaylanacak sevkiyat bulunamadı.');

            return;
        }

        try {
            $shipments->approveDelivery($load->shipment, Auth::user());
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Teslimat onaylandı. Şoförün hakedişi ödeme sırasına alındı.');
    }

    public function submitReview(ReviewService $reviews): void
    {
        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_comment' => 'nullable|string|max:1000',
        ], [
            'rating.min' => 'Lütfen 1 ile 5 arasında bir puan seçin.',
            'rating.max' => 'Lütfen 1 ile 5 arasında bir puan seçin.',
        ]);

        $load = $this->ownerLoad();
        if (! $load) {
            session()->flash('error_message', 'Sevkiyat bulunamadı.');

            return;
        }

        try {
            $reviews->submit($load, Auth::user(), $this->rating, $this->review_comment !== '' ? $this->review_comment : null);
        } catch (\RuntimeException $e) {
            $this->addError('rating', $e->getMessage());

            return;
        }

        $this->reset(['review_comment']);
        $this->rating = 5;
        session()->flash('success_message', 'Değerlendirmeniz kaydedildi.');
    }

    /** Canlı konum bloğu wire:poll ile çağırır; yeni rota izini tarayıcıya iletir. */
    public function refreshTrail(DriverLocationService $locations): void
    {
        $load = $this->ownerLoad();
        $shipment = $load?->shipment;

        if (! $shipment || ! in_array($shipment->status, [Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED], true)) {
            return;
        }

        $trail = $locations->trailFor($shipment);
        if ($trail['latest']) {
            $this->dispatch('trail-updated', trail: $trail['trail'], latest: $trail['latest']->lat_lng, recordedAt: $trail['latest']->recorded_at?->format('d.m.Y H:i'));
        }
    }

    public function with(): array
    {
        $load = $this->ownerLoad();
        $shipment = $load?->shipment;
        $user = Auth::user();

        $trail = ['latest' => null, 'trail' => []];
        if ($shipment && in_array($shipment->status, [Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED], true)) {
            $trail = app(DriverLocationService::class)->trailFor($shipment);
        }

        $timeline = $load ? [
            ['label' => 'İlan yayınlandı', 'at' => $load->published_at ?? $load->created_at],
            ['label' => 'Şoför atandı', 'at' => $shipment?->created_at],
            ['label' => 'Ödeme havuza alındı', 'at' => $load->isPaid() ? ($load->paymentOrders()->where('status', 'paid')->latest('paid_at')->value('paid_at')) : null, 'done' => $load->isPaid()],
            ['label' => 'Yük teslim alındı', 'at' => $shipment?->pickup_confirmed_at],
            ['label' => 'Yola çıkıldı', 'at' => $shipment?->in_transit_at],
            ['label' => 'Teslim edildi', 'at' => $shipment?->delivered_at],
            ['label' => 'Teslimat onaylandı', 'at' => $shipment?->owner_approved_at],
        ] : [];

        return [
            'load' => $load,
            'shipment' => $shipment,
            'driverUser' => $load?->driverProfile?->user,
            'vehicle' => $shipment?->vehicle ?? $load?->driverProfile?->activeVehicle,
            'openDispute' => $load?->openDispute(),
            'trail' => $trail['trail'],
            'latest' => $trail['latest'],
            'timeline' => $timeline,
            'hasReviewed' => $load ? app(ReviewService::class)->hasReviewed($load, $user) : false,
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
            'evidenceTypes' => ['pod' => 'Teslimat kanıtı', 'pickup' => 'Yükleme kanıtı', 'damage' => 'Hasar kaydı'],
        ];
    }
}; ?>

<div wire:poll.15s class="space-y-6">

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
            <a href="{{ route('cargo-owner.shipments.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
                &larr; Sevkiyatlarıma dön
            </a>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
                <span>Sevkiyat takibi</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 tabular-nums text-xs font-bold border border-brand-500/20">#{{ $loadId }}</span>
            </h2>
        </div>

        @if($load)
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-3 py-1.5 rounded-full bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-neutral-800 dark:text-neutral-200 text-xs font-bold">{{ $load->statusLabel() }}</span>
                <span class="px-3 py-1.5 rounded-full bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 text-xs font-bold">{{ $load->escrowLabel() }}</span>
            </div>
        @endif
    </div>

    @if($load)
        @php
            $canApprove = $shipment && $shipment->status === 'delivered' && $load->status === 'delivered' && ! $openDispute;
            $canDispute = in_array($load->status, ['on_the_way', 'delivered'], true) && $load->escrow_status === 'paid_in_escrow' && ! $openDispute;
            $canReview = in_array($load->status, ['delivered', 'completed'], true) && ! $hasReviewed && $driverUser;
            $isLive = $shipment && $shipment->status === 'in_transit';
            $showMap = $shipment && in_array($shipment->status, ['in_transit', 'delivered'], true) && $latest;
        @endphp

        @if($load->status === 'driver_assigned' && $load->escrow_status === 'pending_payment')
            <div class="p-5 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="text-xs text-neutral-800 dark:text-neutral-200 leading-relaxed">
                    <span class="font-bold text-neutral-900 dark:text-white block mb-0.5">Ödeme bekleniyor</span>
                    Şoför, navlun bedeli güvenli havuza yatırılmadan sevkiyatı başlatamaz.
                </div>
                <a href="{{ route('cargo-owner.finance.payment', $load->id) }}" wire:navigate class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs text-center shadow-lg shadow-brand-500/20">Ödemeye git</a>
            </div>
        @endif

        @if($openDispute)
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-700 dark:text-rose-300 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <span>Bu sevkiyat için açık bir uyuşmazlık var ({{ $openDispute->created_at?->format('d.m.Y H:i') }}). Havuz ödemesi karar verilene kadar askıda.</span>
                <a href="{{ route('cargo-owner.disputes.index', ['load' => $load->id]) }}" wire:navigate class="px-4 py-2 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-neutral-900 dark:text-white font-semibold text-center">Uyuşmazlığı görüntüle</a>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-2 space-y-6">

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden isolate z-0" @if($isLive) wire:poll.30s="refreshTrail" @endif>
                    <div class="p-4 border-b border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full {{ $isLive && $latest ? 'bg-emerald-500 animate-pulse' : 'bg-neutral-600' }}"></span>
                            <span class="font-bold text-neutral-900 dark:text-white">Canlı konum</span>
                        </div>
                        @if($latest)
                            <span class="text-neutral-500 dark:text-neutral-400" x-data="{ at: @js($latest->recorded_at?->format('d.m.Y H:i')) }" x-on:trail-updated.window="at = $event.detail.recordedAt || at">Son konum: <span class="text-neutral-800 dark:text-neutral-200" x-text="at"></span></span>
                        @endif
                    </div>

                    @if($showMap)
                        <div
                            x-data="{
                                init() {
                                    if (typeof L === 'undefined') return;
                                    const el = document.getElementById('ownerTrackMap');
                                    if (!el || el._leaflet_id) return;
                                    const trail = @js($trail);
                                    const latest = @js($latest->lat_lng);
                                    const map = L.map(el, { zoomControl: true }).setView(latest, 11);
                                    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap &copy; CARTO' }).addTo(map);
                                    el._ntLine = L.polyline(trail, { color: '#f97316', weight: 4, opacity: 0.85 }).addTo(map);
                                    el._ntMarker = L.circleMarker(latest, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#f97316', fillOpacity: 1 }).addTo(map);
                                    if (trail.length > 1) { map.fitBounds(el._ntLine.getBounds(), { padding: [30, 30] }); }
                                    el._ntMap = map;
                                },
                                update(detail) {
                                    const el = document.getElementById('ownerTrackMap');
                                    if (!el || !el._ntMap || !detail || !detail.latest) return;
                                    el._ntLine.setLatLngs(detail.trail || []);
                                    el._ntMarker.setLatLng(detail.latest);
                                    el._ntMap.panTo(detail.latest);
                                }
                            }"
                            x-on:trail-updated.window="update($event.detail)"
                        >
                            <div wire:ignore id="ownerTrackMap" class="h-72 rounded-2xl"></div>
                        </div>
                    @else
                        <div class="p-10 text-center text-xs text-neutral-500 dark:text-neutral-400">
                            @if($shipment && $shipment->status === 'awaiting_pickup')
                                Şoför yola çıktığında canlı konum burada görünür.
                            @else
                                Şoför konumu henüz paylaşılmadı.
                            @endif
                        </div>
                    @endif
                </div>

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                    <h3 class="section-title">Rota ve yük</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div class="p-3 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800">
                            <span class="text-neutral-500 block mb-0.5">Yükleme adresi</span>
                            <span class="text-neutral-900 dark:text-white font-medium break-words">{{ $load->pickup_location }}</span>
                            <span class="text-neutral-500 block mt-1">{{ $load->pickup_date?->format('d.m.Y') ?? '—' }}</span>
                        </div>
                        <div class="p-3 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800">
                            <span class="text-neutral-500 block mb-0.5">Teslimat adresi</span>
                            <span class="text-neutral-900 dark:text-white font-medium break-words">{{ $load->delivery_location }}</span>
                            <span class="text-neutral-500 block mt-1">{{ $load->delivery_date ? 'En geç '.$load->delivery_date->format('d.m.Y') : 'Teslim tarihi belirtilmedi' }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4 text-xs text-neutral-500 dark:text-neutral-400">
                        <span>Yük: <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $load->goods_type }}</span></span>
                        <span>Ağırlık: <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg</span></span>
                        @if($load->volume)
                            <span>Hacim: <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $load->volume }} m³</span></span>
                        @endif
                        <span>Araç tipi: <span class="text-neutral-800 dark:text-neutral-200 font-medium">{{ $vehicleTypes[$load->vehicle_type] ?? $load->vehicle_type }}</span></span>
                        @if($load->e_irsaliye_no)
                            <span>e-İrsaliye: <span class="text-neutral-800 dark:text-neutral-200 tabular-nums">{{ $load->e_irsaliye_no }}</span></span>
                        @endif
                        @if($load->e_irsaliye_path)
                            <a href="{{ route('files.e-irsaliye', $load->id) }}" target="_blank" rel="noopener" class="text-brand-400 hover:underline">e-İrsaliye belgesi</a>
                        @endif
                    </div>
                </div>

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                    <h3 class="section-title">Sevkiyat zaman çizelgesi</h3>
                    <div class="space-y-3">
                        @foreach($timeline as $step)
                            @php $done = $step['done'] ?? ($step['at'] !== null); @endphp
                            <div class="relative pl-6 text-xs">
                                <span class="absolute left-0 top-0.5 w-3 h-3 rounded-full border-2 {{ $done ? 'bg-brand-500 border-brand-500' : 'bg-white dark:bg-neutral-900 border-neutral-300 dark:border-neutral-700' }}"></span>
                                <div class="font-semibold {{ $done ? 'text-neutral-900 dark:text-white' : 'text-neutral-500' }}">{{ $step['label'] }}</div>
                                <div class="text-[11px] text-neutral-500 tabular-nums">{{ $step['at']?->format('d.m.Y H:i') ?? ($done ? 'Tamamlandı' : 'Bekleniyor') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <h3 class="section-title">Teslimat kanıtları</h3>
                        @if($canApprove)
                            <button type="button" wire:click="approveDelivery" wire:confirm="Teslimatı onayladığınızda havuzdaki navlun bedeli şoförün hakedişi olarak ödeme sırasına alınır. Onaylıyor musunuz?" wire:loading.attr="disabled" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition-all">
                                <span wire:loading.remove wire:target="approveDelivery">Teslimatı onayla</span>
                                <span wire:loading wire:target="approveDelivery">Onaylanıyor...</span>
                            </button>
                        @endif
                    </div>

                    @if($shipment && $shipment->status === 'delivered' && $shipment->auto_approval_due_at)
                        <p class="text-[11px] text-neutral-500">Onay vermezseniz teslimat {{ $shipment->auto_approval_due_at->format('d.m.Y H:i') }} tarihinde otomatik olarak onaylanır.</p>
                    @endif

                    @if($shipment && $shipment->evidence->isNotEmpty())
                        <div class="space-y-2 text-xs">
                            @foreach($shipment->evidence as $evidence)
                                <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="text-neutral-900 dark:text-white font-semibold">{{ $evidenceTypes[$evidence->type] ?? $evidence->type }}</div>
                                        <div class="text-[11px] text-neutral-500">
                                            {{ ($evidence->captured_at ?? $evidence->created_at)?->format('d.m.Y H:i') }}
                                            @if($evidence->uploader) · {{ $evidence->uploader->full_name }} @endif
                                        </div>
                                        @if(! empty($evidence->metadata['note']))
                                            <div class="text-neutral-700 dark:text-neutral-300 mt-1 break-words">{{ $evidence->metadata['note'] }}</div>
                                        @endif
                                    </div>
                                    <a href="{{ route('files.evidence', $evidence->id) }}" target="_blank" rel="noopener" class="text-brand-400 hover:underline font-semibold shrink-0">Belgeyi aç</a>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Henüz teslimat kanıtı yüklenmedi.</p>
                    @endif
                </div>

                @if($canReview)
                    <form wire:submit.prevent="submitReview" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                        <h3 class="section-title">Şoförü değerlendirin</h3>
                        <div class="flex items-center gap-2">
                            @for($i = 1; $i <= 5; $i++)
                                <button type="button" wire:click="$set('rating', {{ $i }})" class="p-1 transition-transform hover:scale-110" aria-label="{{ $i }} puan">
                                    <svg class="w-7 h-7 {{ $i <= $rating ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-700' }}" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                </button>
                            @endfor
                            <span class="text-xs text-neutral-500 dark:text-neutral-400 ml-2">{{ $rating }} / 5</span>
                        </div>
                        @error('rating') <span class="text-rose-500 text-[11px] block">{{ $message }}</span> @enderror
                        <div>
                            <label class="form-label">Yorumunuz (isteğe bağlı)</label>
                            <textarea wire:model="review_comment" rows="3" maxlength="1000" class="form-input"></textarea>
                            @error('review_comment') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" wire:loading.attr="disabled" class="btn-primary py-2 text-xs">Değerlendirmeyi gönder</button>
                        </div>
                    </form>
                @elseif($hasReviewed)
                    <div class="p-4 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-xs text-neutral-500 dark:text-neutral-400">Bu sevkiyat için değerlendirmeniz kaydedildi.</div>
                @endif
            </div>

            <div class="space-y-6">

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                    <h3 class="section-title">Şoför ve araç</h3>

                    @if($driverUser)
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl bg-brand-500/10 text-brand-500 border border-brand-500/20 flex items-center justify-center font-black text-lg shrink-0">
                                {{ mb_strtoupper(mb_substr($driverUser->first_name ?: 'S', 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $driverUser->full_name }}</div>
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                    @if($load->isPaid() && $driverUser->phone)
                                        <a href="tel:0{{ Phone::normalize($driverUser->phone) ?? preg_replace('/\D/', '', $driverUser->phone) }}" class="tabular-nums text-brand-400 hover:underline">{{ Phone::format(Phone::normalize($driverUser->phone) ?? $driverUser->phone) }}</a>
                                    @else
                                        Telefon, ödeme havuza alındıktan sonra görünür.
                                    @endif
                                </div>
                                @if($load->driverProfile?->isKycApproved())
                                    <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">Belgeleri doğrulandı</div>
                                @endif
                            </div>
                        </div>

                        <div class="space-y-2 text-xs border-t border-neutral-200 dark:border-neutral-800 pt-4">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500 dark:text-neutral-400">Plaka</span>
                                <span class="text-neutral-900 dark:text-white font-mono font-bold">{{ $vehicle?->plate ?: '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500 dark:text-neutral-400">Marka / model</span>
                                <span class="text-neutral-800 dark:text-neutral-200 text-right">{{ $vehicle ? \App\Support\VehicleTypes::label($vehicle->vehicle_type) : '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-neutral-500 dark:text-neutral-400">Araç tipi</span>
                                <span class="text-neutral-800 dark:text-neutral-200">{{ $vehicle ? ($vehicleTypes[$vehicle->vehicle_type] ?? $vehicle->vehicle_type) : '—' }}</span>
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Henüz şoför atanmadı.</p>
                    @endif
                </div>

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                    <h3 class="section-title">Güvenli havuz</h3>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-neutral-500 dark:text-neutral-400">Navlun bedeli</span>
                        <span class="text-brand-400 font-bold tabular-nums text-sm">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-neutral-500 dark:text-neutral-400">Durum</span>
                        <span class="text-neutral-900 dark:text-white font-semibold text-right">{{ $load->escrowLabel() }}</span>
                    </div>
                    <p class="text-[11px] text-neutral-500 leading-relaxed">
                        @if($load->escrow_status === 'pending_payment')
                            Ödeme henüz alınmadı.
                        @elseif($load->escrow_status === 'paid_in_escrow')
                            Teslimatı onayladığınızda bedel şoförün hakedişi olarak ödeme sırasına alınır.
                        @elseif($load->escrow_status === 'on_hold')
                            Uyuşmazlık karara bağlanana kadar ödeme askıda.
                        @elseif(in_array($load->escrow_status, ['release_approved', 'released_to_driver'], true))
                            Hakediş şoföre aktarım sürecinde ya da aktarıldı.
                        @else
                            İade süreci tamamlandı.
                        @endif
                    </p>
                    @if($load->status === 'driver_assigned' && $load->escrow_status === 'pending_payment')
                        <a href="{{ route('cargo-owner.finance.payment', $load->id) }}" wire:navigate class="w-full px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-center block">Ödemeye git</a>
                    @endif
                </div>

                @if($canDispute)
                    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
                        <h3 class="section-title">Sorun mu var?</h3>
                        <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">Hasar, eksik teslimat veya başka bir sorun için uyuşmazlık açabilirsiniz. Uyuşmazlık açıldığında havuzdaki ödeme karar verilene kadar askıya alınır.</p>
                        <a href="{{ route('cargo-owner.disputes.index', ['load' => $load->id]) }}" wire:navigate class="w-full py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-rose-500/10 text-neutral-700 dark:text-neutral-300 hover:text-rose-400 text-xs font-semibold border border-neutral-700/60 transition-colors flex items-center justify-center">Uyuşmazlık aç</a>
                    </div>
                @endif

            </div>
        </div>
    @else
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center text-xs text-neutral-500 dark:text-neutral-400">Sevkiyat bulunamadı.</div>
    @endif

</div>
