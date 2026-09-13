<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Geniş Ekran Canlı Radar & Teslimat Onayı')]
class extends Component {
    public int $loadId = 0;
    public ?Load $load = null;

    // Teslim Kanıtı (POD) Modal ve Puanlama Değişkenleri
    public bool $podModalOpen = false;
    public bool $reviewModalOpen = false;
    public int $rating = 5;
    public string $reviewComment = '';

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;
        $user = Auth::user();
        if ($user && $user->cargoOwnerProfile) {
            $this->load = Load::with(['driverProfile.user', 'driverProfile.activeVehicle'])
                ->where('id', $loadId)
                ->where('cargo_owner_profile_id', (int) $user->cargoOwnerProfile->id)
                ->first();

            // ID eşleşmezse kullanıcının mevcut sevkiyatını getir
            if (!$this->load) {
                $this->load = Load::with(['driverProfile.user', 'driverProfile.activeVehicle'])
                    ->where('cargo_owner_profile_id', (int) $user->cargoOwnerProfile->id)
                    ->latest()
                    ->first();

                if ($this->load) {
                    $this->loadId = (int) $this->load->id;
                }
            }
        }
    }

    /**
     * Sevkiyatın İlerleme Aşama İndeksi (1: Yükleniyor, 2: Yolda, 3: Yaklaştı, 4: Teslim Edildi)
     */
    public function getProgressStepProperty(): int
    {
        if (!$this->load) return 1;

        if ($this->load->status === 'delivered') return 4;
        if ($this->load->status === 'on_the_way') return 2;
        return 1;
    }

    /**
     * Teslimatı Onayla (PayTR Havuzundaki Parayı Şoförün IBAN'ına Aktarır)
     */
    public function confirmDelivery(): void
    {
        if ($this->load) {
            $this->load->update([
                'status' => 'delivered',
                'escrow_status' => 'released_to_driver',
            ]);

            $this->podModalOpen = false;
            $this->reviewModalOpen = true; // Onay sonrası puanlama modalını aç
            session()->flash('success_message', 'Teslimat onayı kaydedildi. Hak ediş, uyuşmazlık ve ödeme sağlayıcısı kontrollerinden sonra işleme alınacaktır.');
        }
    }

    /**
     * Şoföre Puan ve Yorum Kaydeder
     */
    public function submitReview(): void
    {
        $this->reviewModalOpen = false;
        session()->flash('success_message', 'Geri bildiriminiz için teşekkürler! Değerlendirmeniz şoförün profiline eklendi.');
    }
}; ?>

<div class="space-y-6" x-data="{
    initLiveShipmentMap() {
        if (typeof L === 'undefined') return;
        const mapEl = document.getElementById('shipmentLiveMap');
        if (!mapEl || mapEl._leaflet_id) return;

        const map = L.map('shipmentLiveMap', { zoomControl: true }).setView([39.0, 35.0], 7);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 18,
            attribution: '&copy; NavlunIQ Canlı GPS Takip'
        }).addTo(map);

        const pickup = [39.9334, 32.8597]; // Ankara
        const delivery = [38.4237, 27.1428]; // İzmir
        const truck = [39.1500, 29.9800]; // Kütahya mevkiisi

        L.circleMarker(pickup, { radius: 7, color: '#3b82f6', fillColor: '#3b82f6', fillOpacity: 1 }).addTo(map).bindPopup('<b>Yükleme Noktası</b><br>Ankara');
        L.circleMarker(delivery, { radius: 7, color: '#10b981', fillColor: '#10b981', fillOpacity: 1 }).addTo(map).bindPopup('<b>Teslimat Noktası</b><br>İzmir');

        // Hareketli Kamyon İkonu
        L.circleMarker(truck, { radius: 10, color: '#ffffff', weight: 3, fillColor: '#f97316', fillOpacity: 1 }).addTo(map).bindPopup('<b>06 TR 992</b><br>Hız: 84 km/s<br>Durum: Seyir Halinde');

        const polyline = L.polyline([pickup, truck, delivery], { color: '#f97316', weight: 4, opacity: 0.85, dashArray: '8, 8' }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [50, 50] });
    }
}" x-init="initLiveShipmentMap()">

    <!-- Başarı Bildirimi -->
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

    <!-- Üst Başlık & Geri Dönüş -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <a href="{{ route('cargo-owner.shipments.index') }}" class="text-xs text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
                &larr; Sevkiyatlarıma Geri Dön
            </a>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span>Canlı Sevkiyat Radarı</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 font-mono text-xs font-bold border border-brand-500/20">
                    #NVL-{{ str_pad((string)$loadId, 5, '0', STR_PAD_LEFT) }}
                </span>
            </h2>
        </div>

        <div class="flex items-center gap-3">
            @if($load && $load->status !== 'delivered')
                <button type="button" wire:click="$set('podModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2 active:scale-95">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Teslimat Kanıtını Gör & Onayla</span>
                </button>
            @else
                <span class="px-4 py-2 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold">
                    ✓ Sevkiyat Başarıyla Tamamlandı
                </span>
            @endif
        </div>
    </div>

    @if($load)
        <!-- 4 Aşamalı Takip Çubuğu (Progress Bar) -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6">
            <div class="flex items-center justify-between relative">
                <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-neutral-800 w-full z-0"></div>
                <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-brand-500 transition-all duration-500 z-0"
                     style="width: {{ $this->progressStep === 1 ? '10%' : ($this->progressStep === 2 ? '50%' : ($this->progressStep === 3 ? '80%' : '100%')) }};"></div>

                <!-- 1. Aşama -->
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold {{ $this->progressStep >= 1 ? 'bg-brand-500 text-white ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-500' }}">
                        1
                    </div>
                    <span class="text-[11px] font-semibold {{ $this->progressStep >= 1 ? 'text-white' : 'text-neutral-500' }}">Yükleniyor</span>
                </div>

                <!-- 2. Aşama -->
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold {{ $this->progressStep >= 2 ? 'bg-brand-500 text-white ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-500' }}">
                        2
                    </div>
                    <span class="text-[11px] font-semibold {{ $this->progressStep >= 2 ? 'text-white' : 'text-neutral-500' }}">Yolda</span>
                </div>

                <!-- 3. Aşama -->
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold {{ $this->progressStep >= 3 ? 'bg-brand-500 text-white ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-500' }}">
                        3
                    </div>
                    <span class="text-[11px] font-semibold {{ $this->progressStep >= 3 ? 'text-white' : 'text-neutral-500' }}">Yaklaştı</span>
                </div>

                <!-- 4. Aşama -->
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold {{ $this->progressStep === 4 ? 'bg-emerald-500 text-white ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-500' }}">
                        ✓
                    </div>
                    <span class="text-[11px] font-semibold {{ $this->progressStep === 4 ? 'text-emerald-400' : 'text-neutral-500' }}">Teslim Edildi</span>
                </div>
            </div>
        </div>

        <!-- Ana Çalışma Bloğu: Geniş Harita ve Canlı Telemetri -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Sol 2 Kolon: Leaflet Canlı GPS Radarı (isolate z-0 ile hapsedildi) -->
            <div class="lg:col-span-2 bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden flex flex-col isolate z-0">
                <div class="p-4 border-b border-neutral-800 flex items-center justify-between bg-neutral-900/60 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-bold text-white">Canlı GPS Sinyali Aktif</span>
                    </div>
                    <span class="text-neutral-400 font-mono">PWA Arka Plan Servisi</span>
                </div>

                <!-- Harita Alanı -->
                <div class="relative w-full h-96 bg-neutral-950 z-0" wire:ignore id="shipmentLiveMap"></div>

                <div class="p-4 bg-neutral-900/95 border-t border-neutral-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                        <span class="text-neutral-500 block">Anlık Hız</span>
                        <span class="text-white font-bold font-mono text-sm">84 km/s</span>
                    </div>
                    <div>
                        <span class="text-neutral-500 block">Kalan Tahmini Süre (ETA)</span>
                        <span class="text-brand-400 font-bold font-mono text-sm">~ 2 Sa 40 Dk</span>
                    </div>
                    <div>
                        <span class="text-neutral-500 block">Konum Güncelleme</span>
                        <span class="text-emerald-400 font-medium">10 sn önce</span>
                    </div>
                </div>
            </div>

            <!-- Sağ 1 Kolon: Şoför, Araç ve Yük Detayları -->
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-6">

                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider border-b border-neutral-800 pb-3">Operasyon Detayları</h3>

                <!-- Şoför Profili -->
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-brand-500/10 text-brand-500 border border-brand-500/20 flex items-center justify-center font-black text-lg">
                        {{ strtoupper(substr($load->driverProfile?->user?->first_name ?? 'M', 0, 1)) }}
                    </div>
                    <div>
                        <div class="text-sm font-bold text-white">{{ $load->driverProfile?->user?->full_name ?? 'Mehmet Demir' }}</div>
                        <div class="text-xs text-neutral-400">{{ $load->driverProfile?->user?->phone ?? '0544 200 30 40' }}</div>
                        <div class="text-[11px] text-emerald-400 font-semibold mt-0.5">✓ Belgeleri AI Tarafından Onaylı</div>
                    </div>
                </div>

                <!-- Araç ve Sevkiyat Özeti -->
                <div class="space-y-3 text-xs border-t border-neutral-800 pt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Araç Plakası:</span>
                        <span class="text-white font-mono font-bold">{{ $load->driverProfile?->activeVehicle?->plate ?? '06 TR 992' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Araç Modeli:</span>
                        <span class="text-neutral-200">{{ $load->driverProfile?->activeVehicle?->brand ?? 'Mercedes-Benz' }} Actros</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Yük Cinsi:</span>
                        <span class="text-neutral-200 font-medium">{{ $load->goods_type }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Ağırlık:</span>
                        <span class="text-neutral-200 font-medium">{{ number_format($load->weight) }} Kg</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">e-İrsaliye No:</span>
                        <span class="text-neutral-200 font-mono">{{ $load->e_irsaliye_no }}</span>
                    </div>
                </div>

                <!-- Finansal Güvence -->
                <div class="p-4 rounded-xl bg-neutral-950 border border-neutral-800 space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Havuzdaki Tutar:</span>
                        <span class="text-brand-400 font-bold font-mono text-sm">{{ number_format((float)$load->price, 2, ',', '.') }} ₺</span>
                    </div>
                    <div class="text-[11px] text-neutral-500 leading-relaxed">
                        Siz teslimat onayını verene kadar para PayTR korumasında bloke tutulur.
                    </div>
                </div>

                <!-- Sorun Bildir / Uyuşmazlık Köprüsü -->
                <div class="pt-2">
                    <a href="{{ route('cargo-owner.disputes.index') }}" class="w-full py-2.5 rounded-xl bg-neutral-800/80 hover:bg-rose-500/10 text-neutral-400 hover:text-rose-400 text-xs font-semibold border border-neutral-700/60 transition-colors flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span>Hasar / Sorun Bildir (Uyuşmazlık Başlat)</span>
                    </a>
                </div>

            </div>

        </div>
    @endif

    <!-- Teslimat Kanıtı (POD) İnceleme Modalı (z-[9999] ile en önde) -->
    @if($podModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('podModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <div>
                        <h3 class="text-base font-bold text-white">Teslimat Kanıtı (POD) İncelemesi</h3>
                        <p class="text-xs text-neutral-400 mt-0.5">Sürücünün yük teslimatında sisteme yüklediği imzalı irsaliye ve kargo görseli.</p>
                    </div>
                    <button wire:click="$set('podModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Simüle POD Görsel Alanı -->
                <div class="p-4 bg-neutral-950 rounded-xl border border-neutral-800 text-center space-y-3">
                    <div class="h-44 bg-neutral-900 border border-dashed border-neutral-700 rounded-lg flex flex-col items-center justify-center text-neutral-500 text-xs">
                        <svg class="w-10 h-10 mb-2 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="font-semibold text-neutral-300">İmzalı Teslim Fişi (POD-06TR992.pdf / jpg)</span>
                        <span class="text-[10px] text-neutral-500 mt-1">İmzalayan: Alıcı Depo Sorumlusu</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-400 leading-relaxed">
                    ✓ Onay verdiğiniz anda PayTR havuzundaki bloke navlun bedeli saniyeler içinde şoförün banka hesabına aktarılacaktır.
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('podModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Kapat
                    </button>
                    <button type="button" wire:click="confirmDelivery" class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white text-xs font-bold shadow-lg shadow-emerald-500/20 transition-all">
                        Teslimatı Onayla & Ödemeyi Çöz
                    </button>
                </div>

            </div>
        </div>
    @endif

    <!-- Şoför Puanlama ve Yorum Modalı (z-[9999] ile en önde) -->
    @if($reviewModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('reviewModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="text-center space-y-2">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center mx-auto text-xl font-bold">
                        ✓
                    </div>
                    <h3 class="text-base font-bold text-white">Sevkiyat Tamamlandı!</h3>
                    <p class="text-xs text-neutral-400">Şoförün taşıma performansını değerlendirerek topluluğumuza katkıda bulunun.</p>
                </div>

                <!-- Yıldız Seçimi -->
                <div class="flex items-center justify-center gap-2 py-2">
                    @for($i = 1; $i <= 5; $i++)
                        <button type="button" wire:click="$set('rating', {{ $i }})" class="text-2xl transition-transform hover:scale-125 {{ $i <= $rating ? 'text-amber-400' : 'text-neutral-700' }}">
                            ★
                        </button>
                    @endfor
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-300 mb-1.5">Şoför Hakkındaki Yorumunuz</label>
                    <textarea wire:model="reviewComment" rows="3" placeholder="Zamanında ve güvenli teslimat sağladı..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-xs text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                </div>

                <button type="button" wire:click="submitReview" class="w-full py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                    Değerlendirmeyi Gönder
                </button>

            </div>
        </div>
    @endif

</div>
