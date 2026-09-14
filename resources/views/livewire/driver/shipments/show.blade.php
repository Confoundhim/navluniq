<?php

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
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.driver')]
#[Title('Sevkiyat Detayı')]
class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $loadId = 0;

    public $pod_file = null;

    public string $pod_note = '';

    public int $rating = 5;

    public string $review_comment = '';

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;

        if (! $this->ownedLoadQuery()->exists()) {
            session()->flash('error_message', 'Sevkiyat bulunamadı veya size ait değil.');
            $this->redirect(route('driver.shipments.index'), navigate: true);
        }
    }

    private function ownedLoadQuery()
    {
        return Load::query()
            ->where('driver_profile_id', Auth::user()->driverProfile?->id ?? 0)
            ->whereKey($this->loadId);
    }

    private function ownedShipment(): ?Shipment
    {
        $profileId = Auth::user()->driverProfile?->id ?? 0;

        return Shipment::query()->where('load_id', $this->loadId)->where('driver_profile_id', $profileId)->first();
    }

    public function startTransit(ShipmentService $shipments): void
    {
        $profile = Auth::user()->driverProfile;
        $shipment = $this->ownedShipment();

        if (! $profile || ! $shipment) {
            session()->flash('error_message', 'Sevkiyat bulunamadı.');

            return;
        }

        try {
            $shipments->startTransit($shipment, $profile);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'Sevkiyat yola çıktı olarak kaydedildi. Konum paylaşımını açarak yük sahibinin sizi takip etmesini sağlayabilirsiniz.');
    }

    public function markDelivered(ShipmentService $shipments): void
    {
        $this->validate([
            'pod_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'pod_note' => 'nullable|string|max:500',
        ], [
            'pod_file.required' => 'Teslimat kanıtı olarak bir fotoğraf veya PDF yükleyin.',
            'pod_file.mimes' => 'Dosya JPG, PNG veya PDF olmalıdır.',
            'pod_file.max' => 'Dosya en fazla 10 MB olabilir.',
        ]);

        $profile = Auth::user()->driverProfile;
        $shipment = $this->ownedShipment();

        if (! $profile || ! $shipment) {
            $this->addError('pod_file', 'Sevkiyat bulunamadı.');

            return;
        }

        try {
            $shipments->markDelivered($shipment, $profile, $this->pod_file, trim($this->pod_note) ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('pod_file', $e->getMessage());

            return;
        }

        $this->reset(['pod_file', 'pod_note']);
        session()->flash('success_message', 'Teslimat kanıtı yüklendi. Yük sahibinin onayı bekleniyor.');
    }

    public function submitReview(ReviewService $reviews): void
    {
        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_comment' => 'nullable|string|max:1000',
        ]);

        $load = $this->ownedLoadQuery()->first();
        if (! $load) {
            $this->addError('rating', 'Sevkiyat bulunamadı.');

            return;
        }

        try {
            $reviews->submit($load, Auth::user(), $this->rating, trim($this->review_comment) ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('rating', $e->getMessage());

            return;
        }

        $this->reset(['rating', 'review_comment']);
        session()->flash('success_message', 'Değerlendirmeniz kaydedildi.');
    }

    public function with(): array
    {
        $user = Auth::user();
        $load = $this->ownedLoadQuery()
            ->with(['cargoOwnerProfile.user', 'driverProfile', 'shipment.evidence.uploader', 'shipment.vehicle', 'payout'])
            ->first();
        $shipment = $load?->shipment;

        $trail = $shipment ? app(DriverLocationService::class)->trailFor($shipment) : ['latest' => null, 'trail' => []];
        $latest = $trail['latest'];

        $hasReviewed = $load ? app(ReviewService::class)->hasReviewed($load, $user) : true;

        return [
            'load' => $load,
            'shipment' => $shipment,
            'latestLocation' => $latest,
            'trailPoints' => $trail['trail'],
            'mapCenter' => $latest ? [(float) $latest->latitude, (float) $latest->longitude] : [39.0, 35.0],
            'mapZoom' => $latest ? 12 : 6,
            'canReview' => $load && in_array($load->status, [Load::STATUS_DELIVERED, Load::STATUS_COMPLETED], true) && ! $hasReviewed,
            'hasReviewed' => $hasReviewed,
            'ownerPhone' => $load && $load->isPaid() ? $load->cargoOwnerProfile?->user?->phone : null,
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <a href="{{ route('driver.shipments.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold">&larr; Sevkiyatlarım</a>
        <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight mt-1">Sevkiyat Detayı</h2>
    </div>

    @if(! $load)
        <div class="p-6 bg-white dark:bg-neutral-900 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-2xl text-center text-xs text-neutral-500 dark:text-neutral-400">Sevkiyat bulunamadı veya size ait değil.</div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-2 space-y-6">

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div class="text-base font-bold text-neutral-900 dark:text-white">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
                        <div class="flex flex-wrap gap-2">
                            <span class="px-2.5 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-400 font-bold text-[11px]">{{ $load->statusLabel() }}</span>
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border {{ $load->isPaid() ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-amber-500/10 border-amber-500/20 text-amber-400' }}">{{ $load->escrowLabel() }}</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800">
                            <div class="text-neutral-500">Yükleme tarihi</div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $load->pickup_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}</div>
                        </div>
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800">
                            <div class="text-neutral-500">Teslim tarihi</div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $load->delivery_date?->format('d.m.Y H:i') ?? 'Belirtilmemiş' }}</div>
                        </div>
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800">
                            <div class="text-neutral-500">Yük</div>
                            <div class="text-neutral-900 dark:text-white font-semibold">
                                {{ $load->goods_type }}
                                @if($load->weight) · {{ number_format((int) ($load->weight ?? 0), 0, ',', '.') }} kg @endif
                                @if($load->volume) · {{ number_format((int) ($load->volume ?? 0), 0, ',', '.') }} m³ @endif
                            </div>
                        </div>
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800">
                            <div class="text-neutral-500">Navlun bedeli</div>
                            <div class="text-neutral-900 dark:text-white tabular-nums font-bold">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</div>
                        </div>
                        @if($load->e_irsaliye_no || $load->e_irsaliye_path)
                            <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 sm:col-span-2 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div>
                                    <div class="text-neutral-500">e-İrsaliye</div>
                                    <div class="text-neutral-900 dark:text-white font-mono">{{ $load->e_irsaliye_no ?: 'Numara belirtilmemiş' }}</div>
                                </div>
                                @if($load->e_irsaliye_path)
                                    <a href="{{ route('files.e-irsaliye', $load->id) }}" target="_blank" rel="noopener" class="text-brand-400 font-bold hover:underline">Belgeyi görüntüle</a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                @if($shipment)
                    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                        <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Sevkiyat aşaması</h3>

                        @if($shipment->status === \App\Models\Shipment::STATUS_AWAITING_PICKUP)
                            @if($load->escrow_status === \App\Models\Load::ESCROW_PAID)
                                <p class="text-xs text-neutral-700 dark:text-neutral-300">Navlun bedeli havuzda bloke edildi. Yükü teslim aldığınızda yola çıktığınızı bildirin.</p>
                                <button type="button" wire:click="startTransit" wire:confirm="Yükü teslim aldığınızı ve yola çıktığınızı onaylıyor musunuz?" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs" wire:loading.attr="disabled">
                                    Yükü aldım, yola çıktım
                                </button>
                            @else
                                <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs">
                                    Yük sahibi ödemeyi yapmadan yola çıkamazsınız. Ödeme havuza yatırıldığında bu sayfada yola çıkma düğmesi görünecektir.
                                </div>
                            @endif
                        @elseif($shipment->status === \App\Models\Shipment::STATUS_IN_TRANSIT)
                            <p class="text-xs text-neutral-700 dark:text-neutral-300">Yola çıkış: {{ $shipment->in_transit_at?->format('d.m.Y H:i') ?? 'Kayıt yok' }}. Teslimatı tamamladığınızda imzalı irsaliye veya teslimat fotoğrafını yükleyin.</p>
                            <form wire:submit.prevent="markDelivered" class="space-y-3 text-xs border-t border-neutral-200 dark:border-neutral-800 pt-4">
                                <div>
                                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Teslimat kanıtı (JPG, PNG, PDF; en fazla 10 MB)</label>
                                    <input type="file" wire:model="pod_file" accept="image/jpeg,image/png,application/pdf" class="w-full text-neutral-500 dark:text-neutral-400 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-900 dark:file:text-white">
                                    @error('pod_file') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                                    <div wire:loading wire:target="pod_file" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                                </div>
                                <div>
                                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Not (isteğe bağlı)</label>
                                    <textarea wire:model="pod_note" rows="2" maxlength="500" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none"></textarea>
                                    @error('pod_note') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="markDelivered">Teslim ettim, kanıtı yükle</span>
                                    <span wire:loading wire:target="markDelivered">Yükleniyor...</span>
                                </button>
                            </form>
                        @elseif($shipment->status === \App\Models\Shipment::STATUS_DELIVERED)
                            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs space-y-1">
                                <div class="font-bold">Yük sahibinin onayı bekleniyor.</div>
                                <div>Teslim: {{ $shipment->delivered_at?->format('d.m.Y H:i') ?? 'Kayıt yok' }}.
                                    @if($shipment->auto_approval_due_at)
                                        Yük sahibi {{ $shipment->auto_approval_due_at->format('d.m.Y H:i') }} tarihine kadar yanıt vermezse teslimat otomatik onaylanır.
                                    @endif
                                </div>
                            </div>
                        @elseif($shipment->status === \App\Models\Shipment::STATUS_COMPLETED)
                            <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-xs space-y-2">
                                <div class="font-bold">Sevkiyat tamamlandı.</div>
                                @if($load->payout)
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                        <div>Navlun: <span class="tabular-nums font-bold text-neutral-900 dark:text-white">{{ number_format((float) ($load->payout->total_amount ?? 0), 2, ',', '.') }} ₺</span></div>
                                        <div>Komisyon: <span class="tabular-nums text-neutral-900 dark:text-white">{{ number_format((float) ($load->payout->commission_amount ?? 0), 2, ',', '.') }} ₺</span></div>
                                        <div>Net hakediş: <span class="tabular-nums font-bold text-neutral-900 dark:text-white">{{ number_format((float) ($load->payout->net_amount ?? 0), 2, ',', '.') }} ₺</span></div>
                                    </div>
                                    <div>Durum: {{ \App\Models\Payout::STATUS_LABELS[$load->payout->status] ?? $load->payout->status }}</div>
                                @else
                                    <div>Hakediş kaydı henüz oluşmadı.</div>
                                @endif
                                <a href="{{ route('driver.wallet.index') }}" wire:navigate class="inline-block font-bold underline">Cüzdana git</a>
                            </div>
                        @elseif($shipment->status === \App\Models\Shipment::STATUS_DISPUTED)
                            <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs">
                                Bu sevkiyat için yük sahibi uyuşmazlık açtı. Savunmanızı uyuşmazlık sayfasından iletebilirsiniz.
                                <a href="{{ route('driver.disputes.index') }}" wire:navigate class="font-bold underline">Uyuşmazlıklar</a>
                            </div>
                        @else
                            <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 text-xs">Sevkiyat iptal edildi.</div>
                        @endif
                    </div>

                    @if($shipment->status === \App\Models\Shipment::STATUS_IN_TRANSIT)
                        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden" wire:ignore
                            x-data="{
                                shipmentId: {{ (int) $shipment->id }},
                                endpoint: @js(route('driver.location.store')),
                                center: @js($mapCenter),
                                zoom: {{ (int) $mapZoom }},
                                trail: @js($trailPoints),
                                map: null,
                                marker: null,
                                line: null,
                                enabled: false,
                                watchId: null,
                                lastSentAt: 0,
                                lastSentLabel: null,
                                error: null,
                                init() {
                                    this.$nextTick(() => this.initMap());
                                    window.addEventListener('livewire:navigating', () => this.stop(), { once: true });
                                },
                                destroy() { this.stop(); },
                                csrf() { const m = document.querySelector('meta[name=csrf-token]'); return m ? m.content : ''; },
                                initMap() {
                                    if (typeof L === 'undefined' || this.map) return;
                                    const el = this.$refs.map;
                                    if (!el || el._leaflet_id) return;
                                    this.map = L.map(el, { zoomControl: true }).setView(this.center, this.zoom);
                                    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                                        maxZoom: 18,
                                        attribution: '&copy; OpenStreetMap katkıda bulunanlar &copy; CARTO'
                                    }).addTo(this.map);
                                    if (this.trail.length > 0) {
                                        this.line = L.polyline(this.trail, { color: '#f97316', weight: 4, opacity: 0.9 }).addTo(this.map);
                                        this.marker = L.circleMarker(this.trail[this.trail.length - 1], { radius: 9, color: '#ffffff', weight: 3, fillColor: '#f97316', fillOpacity: 1 }).addTo(this.map);
                                    }
                                },
                                updateMarker(lat, lng) {
                                    if (!this.map) return;
                                    const point = [lat, lng];
                                    this.trail.push(point);
                                    if (this.line) { this.line.addLatLng(point); } else { this.line = L.polyline(this.trail, { color: '#f97316', weight: 4, opacity: 0.9 }).addTo(this.map); }
                                    if (this.marker) { this.marker.setLatLng(point); } else { this.marker = L.circleMarker(point, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#f97316', fillOpacity: 1 }).addTo(this.map); }
                                    this.map.setView(point, Math.max(this.map.getZoom(), 12));
                                },
                                toggle() { this.enabled ? this.stop() : this.start(); },
                                start() {
                                    if (!('geolocation' in navigator)) { this.error = 'Tarayıcınız konum paylaşımını desteklemiyor.'; return; }
                                    this.error = null;
                                    this.enabled = true;
                                    this.watchId = navigator.geolocation.watchPosition(
                                        (position) => this.onPosition(position),
                                        (err) => { this.error = 'Konum alınamadı: ' + err.message; },
                                        { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 }
                                    );
                                },
                                stop() {
                                    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
                                    this.watchId = null;
                                    this.enabled = false;
                                },
                                onPosition(position) {
                                    const now = Date.now();
                                    if (now - this.lastSentAt < 15000) return;
                                    this.lastSentAt = now;
                                    const c = position.coords;
                                    const num = (v) => (typeof v === 'number' && isFinite(v)) ? v : null;
                                    fetch(this.endpoint, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                                        body: JSON.stringify({ lat: c.latitude, lng: c.longitude, speed: num(c.speed), heading: num(c.heading), accuracy: num(c.accuracy), shipment_id: this.shipmentId })
                                    }).then((response) => {
                                        if (!response.ok) throw new Error('Sunucu yanıtı ' + response.status);
                                        this.lastSentLabel = new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                        this.error = null;
                                        this.updateMarker(c.latitude, c.longitude);
                                    }).catch((e) => { this.error = 'Konum gönderilemedi: ' + e.message; });
                                }
                            }">
                            <div class="p-4 border-b border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                                <div class="space-y-1">
                                    <div class="font-bold text-neutral-900 dark:text-white">Canlı konum paylaşımı</div>
                                    <div class="text-neutral-500 dark:text-neutral-400">
                                        Konum paylaşımı:
                                        <span class="font-bold" :class="enabled ? 'text-emerald-400' : 'text-neutral-700 dark:text-neutral-300'" x-text="enabled ? 'Açık' : 'Kapalı'"></span>
                                        <template x-if="lastSentLabel"><span> · Son gönderim: <span class="font-mono" x-text="lastSentLabel"></span></span></template>
                                        <template x-if="!lastSentLabel"><span> · Bu oturumda henüz konum gönderilmedi</span></template>
                                    </div>
                                    <div class="text-rose-400" x-show="error" x-text="error" x-cloak></div>
                                </div>
                                <button type="button" @click="toggle()" class="shrink-0 px-4 py-2 rounded-xl font-bold border transition-colors" :class="enabled ? 'bg-rose-500/10 border-rose-500/30 text-rose-300' : 'bg-brand-500 border-brand-500 text-white hover:bg-brand-600'">
                                    <span x-text="enabled ? 'Paylaşımı durdur' : 'Paylaşımı başlat'"></span>
                                </button>
                            </div>
                            <div class="relative w-full h-80 bg-neutral-50 dark:bg-neutral-950 z-0" x-ref="map"></div>
                            <div class="p-3 text-[11px] text-neutral-500 border-t border-neutral-200 dark:border-neutral-800">
                                @if($latestLocation)
                                    Sunucuya kaydedilen son konum: {{ $latestLocation->recorded_at?->format('d.m.Y H:i') }}
                                @else
                                    Bu sevkiyat için henüz kaydedilmiş konum yok. Paylaşımı başlattığınızda konumunuz yük sahibiyle paylaşılır.
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
                        <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Yüklenen kanıtlar</h3>
                        @forelse($shipment->evidence as $evidence)
                            <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                                <div>
                                    <div class="text-neutral-900 dark:text-white font-semibold">{{ $evidence->type === 'pod' ? 'Teslimat kanıtı' : $evidence->type }}</div>
                                    <div class="text-[11px] text-neutral-500">
                                        {{ $evidence->captured_at?->format('d.m.Y H:i') ?? $evidence->created_at?->format('d.m.Y H:i') }}
                                        @if($evidence->uploader) · {{ $evidence->uploader->full_name }} @endif
                                        @if(! empty($evidence->metadata['note'])) · {{ $evidence->metadata['note'] }} @endif
                                    </div>
                                </div>
                                <a href="{{ route('files.evidence', $evidence->id) }}" target="_blank" rel="noopener" class="text-brand-400 font-bold hover:underline">Görüntüle</a>
                            </div>
                        @empty
                            <div class="text-xs text-neutral-500">Henüz kanıt yüklenmedi.</div>
                        @endforelse
                    </div>
                @else
                    <div class="p-6 bg-white dark:bg-neutral-900 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-2xl text-center text-xs text-neutral-500 dark:text-neutral-400">Bu ilan için sevkiyat kaydı henüz oluşmadı.</div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                    <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Yük sahibi</h3>
                    <div class="text-neutral-900 dark:text-white font-bold">{{ $load->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }}</div>
                    @if($ownerPhone)
                        <a href="tel:0{{ \App\Support\Phone::normalize($ownerPhone) ?? $ownerPhone }}" class="text-brand-400 font-mono font-bold hover:underline">{{ \App\Support\Phone::format($ownerPhone) }}</a>
                    @else
                        <div class="text-neutral-500">İletişim numarası, yük sahibi havuz ödemesini yaptıktan sonra görünür.</div>
                    @endif
                </div>

                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs">
                    <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Ödeme durumu</h3>
                    <div class="text-neutral-900 dark:text-white font-bold">{{ $load->escrowLabel() }}</div>
                    <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        @switch($load->escrow_status)
                            @case(\App\Models\Load::ESCROW_PENDING)
                                Yük sahibi ödemeyi yapmadan yola çıkamazsınız.
                                @break
                            @case(\App\Models\Load::ESCROW_PAID)
                                Navlun bedeli havuzda bloke. Teslimat onaylandığında hakedişiniz komisyon düşülerek ödeme sırasına alınır.
                                @break
                            @case(\App\Models\Load::ESCROW_ON_HOLD)
                                Uyuşmazlık karara bağlanana kadar ödeme askıda tutulur.
                                @break
                            @case(\App\Models\Load::ESCROW_RELEASE_APPROVED)
                                Hakedişiniz onaylandı; finans ekibi banka transferini tamamladığında bilgilendirilirsiniz.
                                @break
                            @case(\App\Models\Load::ESCROW_RELEASED)
                                Hakedişiniz kayıtlı banka hesabınıza aktarıldı.
                                @break
                            @case(\App\Models\Load::ESCROW_REFUNDED)
                                Navlun bedeli yük sahibine iade edildi.
                                @break
                            @default
                                Ödeme durumu güncellenmedi.
                        @endswitch
                    </p>
                    @if($load->isPaid() && $load->driverProfile)
                        <div class="pt-2 border-t border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400">
                            Komisyon oranınız: %{{ number_format($load->driverProfile->commissionRate(), 1, ',', '.') }}
                        </div>
                    @endif
                </div>

                @if($shipment?->vehicle)
                    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-1 text-xs">
                        <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Atanan araç</h3>
                        <div class="text-neutral-900 dark:text-white font-mono font-bold">{{ $shipment->vehicle->plate }}</div>
                        <div class="text-neutral-500 dark:text-neutral-400">{{ $shipment->vehicle->brand }} {{ $shipment->vehicle->model }}</div>
                    </div>
                @endif

                @if($canReview)
                    <form wire:submit.prevent="submitReview" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                        <h3 class="text-xs font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Yük sahibini değerlendir</h3>
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Puan</label>
                            <select wire:model="rating" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                                @for($i = 5; $i >= 1; $i--)
                                    <option value="{{ $i }}">{{ $i }} / 5</option>
                                @endfor
                            </select>
                            @error('rating') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Yorum (isteğe bağlı)</label>
                            <textarea wire:model="review_comment" rows="3" maxlength="1000" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none"></textarea>
                            @error('review_comment') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit" class="w-full px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">Değerlendirmeyi gönder</button>
                    </form>
                @elseif($hasReviewed && in_array($load->status, [\App\Models\Load::STATUS_DELIVERED, \App\Models\Load::STATUS_COMPLETED], true))
                    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 text-xs text-neutral-500 dark:text-neutral-400">Bu sevkiyat için değerlendirmenizi gönderdiniz.</div>
                @endif
            </div>
        </div>
    @endif
</div>
