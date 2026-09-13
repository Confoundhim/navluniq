<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.driver')]
#[Title('Canlı Navigasyon & Teslimat (POD)')]
class extends Component {
    use WithFileUploads;

    public int $loadId = 0;
    public ?Load $load = null;

    // Yasal Zorunluluk: U-ETDS Onay Kutusu
    public bool $uetds_confirmed = false;

    // POD (Teslim Kanıtı) Yükleme Modalı
    public bool $podUploadModalOpen = false;
    public $pod_document = null;

    // Yük Sahibi Değerlendirme Modalı
    public bool $ratingModalOpen = false;
    public int $owner_rating = 5;
    public string $owner_comment = '';

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;
        $user = Auth::user();
        if ($user && $user->driverProfile) {
            $driverId = (int) $user->driverProfile->id;

            $this->load = Load::with(['cargoOwnerProfile.user'])
                ->where('id', $loadId)
                ->where('driver_profile_id', $driverId)
                ->first();

            // Eğer ID eşleşmezse şoförün ilk aktif yükünü getir
            if (!$this->load) {
                $this->load = Load::with(['cargoOwnerProfile.user'])
                    ->where('driver_profile_id', $driverId)
                    ->latest()
                    ->first();

                if ($this->load) {
                    $this->loadId = (int) $this->load->id;
                }
            }

            if ($this->load && $this->load->status === 'on_the_way') {
                $this->uetds_confirmed = true;
            }
        }
    }

    /**
     * Sevkiyat Durumunu 'Yüklendi / Yolda' Olarak Günceller (U-ETDS Şartlı)
     */
    public function updateStatusToOnWay(): void
    {
        if (!$this->uetds_confirmed) {
            $this->addError('uetds_confirmed', 'Yola çıkmadan önce U-ETDS bildirimini onaylamanız yasal zorunluluktur.');
            return;
        }

        if ($this->load) {
            $this->load->update([
                'status' => 'on_the_way',
            ]);
            session()->flash('success_message', 'Sevkiyat durumu "Yolda" olarak güncellendi. Yük sahibine canlı takip bildirimi iletildi.');
        }
    }

    /**
     * Teslim Kanıtı (POD) Belgesini Yükler ve Yük Sahibine Onaya Gönderir
     */
    public function submitPOD(): void
    {
        $this->validate([
            'pod_document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'pod_document.required' => 'Lütfen imzalı irsaliye veya teslimat fotoğrafını yükleyiniz.',
        ]);

        if ($this->load) {
            $path = $this->pod_document->store('private/pod_documents', 'local');

            // İlan durumunu teslim edildi olarak güncelle ve yük sahibine onay aç
            $this->load->update([
                'status' => 'delivered',
            ]);

            $this->podUploadModalOpen = false;
            $this->ratingModalOpen = true; // Yük sahibine puan verme modalını aç
            session()->flash('success_message', 'Teslimat kanıtı (POD) başarıyla yüklendi! Yük sahibi onayı ve ödeme kontrollerinden sonra hak ediş süreci başlatılacaktır.');
        }
    }

    /**
     * Yük Sahibine Puan ve Yorum Gönderir
     */
    public function submitOwnerRating(): void
    {
        $this->ratingModalOpen = false;
        session()->flash('success_message', 'Değerlendirmeniz kaydedildi. Hayırlı ve bol kazançlı seferler dileriz!');
    }
}; ?>

<div class="space-y-6" x-data="{
    initDriverMap() {
        if (typeof L === 'undefined') return;
        const mapEl = document.getElementById('driverNavMap');
        if (!mapEl || mapEl._leaflet_id) return;

        const map = L.map('driverNavMap', { zoomControl: true }).setView([39.0, 35.0], 7);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 18,
            attribution: '&copy; NavlunIQ Canlı GPS Navigasyon'
        }).addTo(map);

        const pickup = [39.9334, 32.8597]; // Ankara
        const delivery = [38.4237, 27.1428]; // İzmir
        const truck = [39.1500, 29.9800]; // Kütahya

        L.circleMarker(pickup, { radius: 7, color: '#3b82f6', fillColor: '#3b82f6', fillOpacity: 1 }).addTo(map).bindPopup('<b>Yükleme Noktası</b><br>Ostim OSB / Ankara');
        L.circleMarker(delivery, { radius: 7, color: '#10b981', fillColor: '#10b981', fillOpacity: 1 }).addTo(map).bindPopup('<b>Teslimat Noktası</b><br>Aliağa OSB / İzmir');

        // Kamyon Pini
        L.circleMarker(truck, { radius: 10, color: '#ffffff', weight: 3, fillColor: '#f97316', fillOpacity: 1 }).addTo(map).bindPopup('<b>Aracınız (06 TR 992)</b><br>Hız: 84 km/s');

        const polyline = L.polyline([pickup, truck, delivery], { color: '#f97316', weight: 4, opacity: 0.9, dashArray: '8, 8' }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [50, 50] });
    }
}" x-init="initDriverMap()">

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
            <a href="{{ route('driver.shipments.index') }}" class="text-xs text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
                &larr; Sevkiyatlarıma Geri Dön
            </a>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span>Canlı Navigasyon & Sefer Yönetimi</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 font-mono text-xs font-bold border border-brand-500/20">
                    #NVL-{{ str_pad((string)$loadId, 5, '0', STR_PAD_LEFT) }}
                </span>
            </h2>
        </div>

        <div class="flex items-center gap-2">
            @if($load && $load->status !== 'delivered')
                <button type="button" wire:click="$set('podUploadModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2 active:scale-95">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span>Teslimat Kanıtı (POD) Yükle</span>
                </button>
            @else
                <span class="px-4 py-2 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold">
                    ✓ Teslim Edildi (Onay Bekleniyor)
                </span>
            @endif
        </div>
    </div>

    @if($load)
        <!-- AŞAMA KONTROL ÇUBUĞU VE YASAL U-ETDS ONAYI -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-6">

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
                <div class="space-y-1">
                    <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Aşama Yönetimi</span>
                    <div class="text-sm font-bold text-white">
                        Mevcut Durum:
                        <span class="text-brand-400">
                            {{ $load->status === 'driver_assigned' ? 'Yükleme Noktasına Gidiliyor' : ($load->status === 'on_the_way' ? 'Seyir Halinde (Yolda)' : 'Teslimat Yapıldı') }}
                        </span>
                    </div>
                </div>

                <!-- Aşama Aksiyon Butonu -->
                @if($load->status === 'driver_assigned')
                    <div class="space-y-2">
                        <button type="button" wire:click="updateStatusToOnWay" class="w-full md:w-auto px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>Yüklendi & Yola Çıktım</span>
                        </button>
                    </div>
                @endif
            </div>

            <!-- Yasal U-ETDS Zorunlu Onay Kutusu -->
            @if($load->status === 'driver_assigned')
                <div class="p-4 rounded-xl bg-neutral-950 border border-brand-500/30 space-y-2">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.live="uetds_confirmed" class="mt-0.5 w-4 h-4 rounded bg-neutral-900 border-neutral-700 text-brand-500 focus:ring-brand-500/20">
                        <div class="space-y-0.5">
                            <span class="text-xs font-bold text-white">Yasal Bildirim: U-ETDS Sefer Bildirimini Yaptım</span>
                            <p class="text-[11px] text-neutral-400 leading-relaxed">
                                Ulaştırma ve Altyapı Bakanlığı mevzuatı gereği, yükleme noktasından ayrılmadan önce U-ETDS sistemine sefer kaydı girilmesi yasal zorunluluktur.
                            </p>
                        </div>
                    </label>
                    @error('uetds_confirmed') <span class="text-rose-500 text-[11px] mt-1 block font-semibold">{{ $message }}</span> @enderror
                </div>
            @endif

        </div>

        <!-- ANA ÇALIŞMA ALANI: HARİTA VE YÜK SAHİBİ BİLGİLERİ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Sol 2 Kolon: Canlı GPS Navigasyon Haritası -->
            <div class="lg:col-span-2 bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden flex flex-col isolate z-0">
                <div class="p-4 border-b border-neutral-800 flex items-center justify-between bg-neutral-900/60 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-bold text-white">Arka Plan GPS Aktif (PWA)</span>
                    </div>
                    <span class="text-neutral-400 font-mono">Pil Tasarruf Modu Devrede</span>
                </div>

                <div class="relative w-full h-96 bg-neutral-950 z-0" wire:ignore id="driverNavMap"></div>

                <div class="p-4 bg-neutral-900/95 border-t border-neutral-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                        <span class="text-neutral-500 block">Kamyon Hızı</span>
                        <span class="text-white font-bold font-mono text-sm">84 km/s</span>
                    </div>
                    <div>
                        <span class="text-neutral-500 block">Varışa Kalan Süre</span>
                        <span class="text-brand-400 font-bold font-mono text-sm">~ 2 Sa 40 Dk</span>
                    </div>
                    <div>
                        <span class="text-neutral-500 block">Kalan Mesafe</span>
                        <span class="text-emerald-400 font-bold font-mono text-sm">218 km</span>
                    </div>
                </div>
            </div>

            <!-- Sağ 1 Kolon: Yük Sahibi, Yük Bilgisi ve Hak Ediş -->
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-6">

                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider border-b border-neutral-800 pb-3">Sefer Detayları</h3>

                <!-- Yük Sahibi Bilgisi -->
                <div class="space-y-2">
                    <span class="text-neutral-500 text-xs block">Yük Sahibi / Firma:</span>
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 space-y-1 text-xs">
                        <div class="text-white font-bold">{{ $load->cargoOwnerProfile?->company_title ?? 'Yılmaz Lojistik A.Ş.' }}</div>
                        <div class="text-neutral-400">Yetkili: {{ $load->cargoOwnerProfile?->user?->full_name ?? 'Ahmet Yılmaz' }}</div>
                        <div class="text-neutral-400">İletişim: <b class="text-brand-400 font-mono">{{ $load->cargoOwnerProfile?->user?->phone ?? '0532 100 20 30' }}</b></div>
                    </div>
                </div>

                <!-- Rota & e-İrsaliye -->
                <div class="space-y-3 text-xs border-t border-neutral-800 pt-4">
                    <div>
                        <span class="text-neutral-500 block">Yükleme Adresi:</span>
                        <span class="text-neutral-200">{{ $load->pickup_location }}</span>
                    </div>
                    <div>
                        <span class="text-neutral-500 block">Teslimat Adresi:</span>
                        <span class="text-neutral-200">{{ $load->delivery_location }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-500">Yük & Tonaj:</span>
                        <span class="text-white font-medium">{{ $load->goods_type }} ({{ number_format($load->weight) }} Kg)</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-500">e-İrsaliye Numarası:</span>
                        <span class="text-white font-mono font-bold">{{ $load->e_irsaliye_no ?? 'GIB202600008891' }}</span>
                    </div>
                </div>

                <!-- Hak Ediş Güvencesi -->
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 space-y-1 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Net Alacağınız (%95):</span>
                        <span class="text-emerald-400 font-bold font-mono text-base">{{ number_format(((float)$load->price) * 0.95, 2, ',', '.') }} ₺</span>
                    </div>
                    <p class="text-[11px] text-neutral-400 leading-relaxed">
                        Paranız PayTR havuzunda %100 bloke garantilidir. Teslimat belgesi onaylandığında banka hesabınıza aktarılır.
                    </p>
                </div>

            </div>

        </div>
    @endif

    <!-- POD (Teslim Kanıtı) Yükleme Modalı (z-[9999]) -->
    @if($podUploadModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('podUploadModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <div>
                        <h3 class="text-base font-bold text-white">Teslimat Kanıtı (POD) Yükle</h3>
                        <p class="text-xs text-neutral-400 mt-0.5">İmzalı teslim fişini veya yükün boşaltılmış halini fotoğraflayarak yükleyin.</p>
                    </div>
                    <button wire:click="$set('podUploadModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">İmzalı İrsaliye / Teslimat Fotoğrafı <span class="text-brand-500">*</span></label>
                        <input type="file" wire:model="pod_document" class="w-full text-xs text-neutral-400 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-800 file:text-white hover:file:bg-neutral-700 cursor-pointer">
                        @error('pod_document') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="p-3.5 rounded-xl bg-neutral-950 border border-neutral-800 text-[11px] text-neutral-400 leading-relaxed">
                        💡 Bu görsel anında yük sahibinin paneline iletilecek ve bloke edilmiş olan <b class="text-emerald-400 font-mono">{{ number_format(((float)($load?->price ?? 0)) * 0.95, 2, ',', '.') }} ₺</b> hak edişinizin serbest bırakılmasını tetikleyecektir.
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('podUploadModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="submitPOD" class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white text-xs font-bold shadow-lg shadow-emerald-500/20 transition-all">
                        Belgeyi Gönder & Teslimatı Bitir
                    </button>
                </div>

            </div>
        </div>
    @endif

    <!-- Yük Sahibine Puan ve Yorum Modalı (z-[9999]) -->
    @if($ratingModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('ratingModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="text-center space-y-2">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center mx-auto text-xl font-bold">
                        ✓
                    </div>
                    <h3 class="text-base font-bold text-white">Teslimat Başarıyla Kaydedildi!</h3>
                    <p class="text-xs text-neutral-400">Yük sahibinin iletişimini ve yükleme kolaylığını değerlendirin.</p>
                </div>

                <!-- Yıldız Seçimi -->
                <div class="flex items-center justify-center gap-2 py-2">
                    @for($i = 1; $i <= 5; $i++)
                        <button type="button" wire:click="$set('owner_rating', {{ $i }})" class="text-2xl transition-transform hover:scale-125 {{ $i <= $owner_rating ? 'text-amber-400' : 'text-neutral-700' }}">
                            ★
                        </button>
                    @endfor
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-300 mb-1.5">Yorumunuz (Opsiyonel)</label>
                    <textarea wire:model="owner_comment" rows="3" placeholder="Yük tam vaktinde hazırlandı, ödeme havuzda hazırdı..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-xs text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                </div>

                <button type="button" wire:click="submitOwnerRating" class="w-full py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                    Değerlendirmeyi Tamamla
                </button>

            </div>
        </div>
    @endif

</div>
