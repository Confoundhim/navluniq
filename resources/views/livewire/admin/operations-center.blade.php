<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\Load;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;

new class extends Component {
    // Filtreleme
    public string $statusFilter = 'all'; // 'all', 'active_seeking', 'on_the_way', 'delivered', 'cancelled'
    public string $search = '';

    // Detay Paneli Durumları
    public ?int $selectedLoadId = null;
    public $selectedLoad = null;

    // Fiyat Müdahale Modal Durumları
    public bool $showEditModal = false;
    public float $newPrice = 0;

    public function mount()
    {
        if (!auth()->user()->can('view operations')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * Tıklanan İlanın Detaylarını Panelde Açar
     */
    public function selectLoad(int $loadId)
    {
        $this->selectedLoadId = $loadId;
        $this->selectedLoad = Load::with(['cargoOwnerProfile.user', 'driverProfile.user'])->find($loadId);
    }

    /**
     * Paneli Kapatır
     */
    public function closePanel()
    {
        $this->selectedLoadId = null;
        $this->selectedLoad = null;
    }

    /**
     * Fiyat Güncelleme Modalı Aç
     */
    public function openEditModal()
    {
        $this->newPrice = $this->selectedLoad->price;
        $this->showEditModal = true;
    }

    /**
     * İlan Fiyatına Müdahale (Admin Yetkisi)
     */
    public function updatePrice()
    {
        if (!auth()->user()->can('manage operations')) {
            abort(403);
        }

        $this->validate([
            'newPrice' => 'required|numeric|min:500'
        ]);

        $this->selectedLoad->update([
            'price' => $this->newPrice
        ]);

        $this->showEditModal = false;
        $this->selectedLoad->refresh();
        session()->flash('success', 'Yük navlun bedeli başarıyla güncellendi.');
    }

    /**
     * İlanı Askıya Alma / Geri Yayına Alma
     */
    public function toggleSuspend()
    {
        if (!auth()->user()->can('manage operations')) {
            abort(403);
        }

        // Eğer sevkiyat yoldaysa askıya alınamaz
        if (in_array($this->selectedLoad->status, ['on_the_way', 'delivered'])) {
            session()->flash('error', 'Yolda olan veya teslim edilmiş sevkiyatlar askıya alınamaz.');
            return;
        }

        $newStatus = $this->selectedLoad->status === 'cancelled' ? 'active_seeking' : 'cancelled';

        $this->selectedLoad->update([
            'status' => $newStatus,
            'escrow_status' => $newStatus === 'cancelled' ? 'refunded_to_owner' : 'pending_payment'
        ]);

        $this->selectedLoad->refresh();
        session()->flash('success', $newStatus === 'cancelled' ? 'İlan askıya alındı ve yayından kaldırıldı.' : 'İlan başarıyla geri yayına alındı.');
    }

    /**
     * İlan Filtreleme Sorgusu
     */
    private function getLoadsQuery()
    {
        $query = Load::query()->with(['cargoOwnerProfile.user', 'driverProfile.user']);

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search) {
            $query->where(function($q) {
                $q->where('pickup_location', 'like', "%{$this->search}%")
                  ->orWhere('delivery_location', 'like', "%{$this->search}%")
                  ->orWhere('goods_type', 'like', "%{$this->search}%");
            });
        }

        return $query->latest()->get();
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in" x-data="{
    map: null,
    markers: [],

    initMap() {
        // Harita Koyu Tema Katmanı (CartoDB Dark Matter)
        const darkLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap, © CartoDB'
        });

        this.map = L.map('operations-map', {
            center: [39.0, 35.2], // Türkiye Coğrafi Merkezi
            zoom: 6,
            layers: [darkLayer]
        });

        this.loadMarkers();
    },

    loadMarkers() {
        // Eski işaretçileri temizle
        this.markers.forEach(m => this.map.removeLayer(m));
        this.markers = [];

        // Simüle Canlı Araç ve Sevkiyat Noktaları Verileri
        const locations = [
            { lat: 39.9208, lng: 32.8541, name: 'Çankaya, Ankara (Yükleme Noktası)', iconColor: '#f97316' },
            { lat: 40.9818, lng: 29.0318, name: 'Kadıköy, İstanbul (Teslimat Noktası)', iconColor: '#10b981' },
            
        ];

        locations.forEach(loc => {
            const marker = L.circleMarker([loc.lat, loc.lng], {
                radius: loc.isTruck ? 10 : 8,
                fillColor: loc.iconColor,
                color: '#ffffff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.9
            }).addTo(this.map).bindPopup(`<div class='text-xs font-semibold text-neutral-900'>${loc.name}</div>`);

            this.markers.push(marker);
        });

        // Tır rotasını simüle eden mavi çizgi çiz (Ankara -> İstanbul Otobanı)
        const routeLine = L.polyline([
            [39.9208, 32.8541],
            [40.3500, 30.8000],
        ], { color: '#3b82f6', weight: 3, dashArray: '5, 10' }).addTo(this.map);
        this.markers.push(routeLine);
    }
}" x-init="initMap()" @refreshMap.window="loadMarkers()">

    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Harita (Canlı Radar) Bölümü -->
    <div class="space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white uppercase tracking-wider">CANLI RADAR EKRANI</h2>
            <!-- Simüle İlan Üretme -->
            @if(\App\Models\Load::count() === 0)
@endif
        </div>
        <div class="w-full h-80 rounded-3xl overflow-hidden border border-neutral-200/40 dark:border-neutral-800/50 shadow-apple-lg relative" id="operations-map" wire:ignore></div>
    </div>

    <!-- Filtreler ve Seçim Barları -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white/70 dark:bg-neutral-800/70 backdrop-blur-apple p-3 rounded-2xl border border-neutral-100 dark:border-neutral-800/50 shadow-apple-sm">

        <!-- Durum Filtreleri (Sıralı Segmentler) -->
        <div class="flex items-center space-x-2 w-full md:w-auto overflow-x-auto">
            @foreach([
                'all' => 'Tüm Yükler',
                'active_seeking' => 'Yük Bekleyenler',
                'driver_assigned' => 'Sürücü Atananlar',
                'on_the_way' => 'Yolda Olanlar',
                'delivered' => 'Teslim Edilenler',
                'cancelled' => 'Askıdakiler'
            ] as $status => $label)
                <button wire:click="$set('statusFilter', '{{ $status }}')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all duration-300 border {{ $statusFilter === $status ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 border-neutral-900' : 'border-neutral-200/50 dark:border-neutral-700/30 text-neutral-500 hover:bg-neutral-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <!-- Arama Kutusu -->
        <div class="relative w-full md:w-64">
            <input type="text" wire:model.live="search" placeholder="Güzergah veya yük cinsi ara..." class="w-full pl-9 pr-4 py-2 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all duration-300">
            <svg class="w-4 h-4 text-neutral-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
    </div>

    <!-- İlan Listesi ve Detay Split-Screen Gridi -->
    <div class="grid grid-cols-1 {{ $selectedLoadId ? 'lg:grid-cols-2' : '' }} gap-8 items-start">

        <!-- Sol Bölüm: İlan Listesi -->
        <div class="apple-glass rounded-3xl overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                        <th class="p-5">Rota / Güzergah</th>
                        <th class="p-5">Yük Cinsi / Araç</th>
                        <th class="p-5">Fiyat / Escrow</th>
                        <th class="p-5">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30 text-xs">
                    @forelse($this->getLoadsQuery() as $load)
                        <tr wire:click="selectLoad({{ $load->id }})" class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200 cursor-pointer {{ $selectedLoadId === $load->id ? 'bg-brand-500/5 dark:bg-brand-500/10' : '' }}">
                            <td class="p-5">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $load->pickup_location }}</div>
                                <div class="text-[11px] text-neutral-400 mt-1">→ {{ $load->delivery_location }}</div>
                            </td>
                            <td class="p-5 text-neutral-500 dark:text-neutral-400">
                                <div class="font-semibold">{{ $load->goods_type }}</div>
                                <div class="text-[11px] mt-0.5 capitalize">{{ $load->vehicle_type }} ({{ $load->weight }} kg)</div>
                            </td>
                            <td class="p-5">
                                <div class="font-bold text-neutral-950 dark:text-white">₺{{ number_format($load->price, 2) }}</div>
                                @php
                                    $escrowClasses = [
                                        'pending_payment' => 'text-amber-500',
                                        'paid_in_escrow' => 'text-emerald-500',
                                        'released_to_driver' => 'text-blue-500',
                                        'refunded_to_owner' => 'text-red-500',
                                    ];
                                    $escrowLabels = [
                                        'pending_payment' => 'Ödeme Bekliyor',
                                        'paid_in_escrow' => 'Blokeli Güvende',
                                        'released_to_driver' => 'Sürücüye Aktarıldı',
                                        'refunded_to_owner' => 'İade Edildi',
                                    ];
                                @endphp
                                <div class="text-[10px] font-semibold mt-0.5 {{ $escrowClasses[$load->escrow_status] ?? '' }}">
                                    {{ $escrowLabels[$load->escrow_status] ?? '' }}
                                </div>
                            </td>
                            <td class="p-5">
                                @php
                                    $statusClasses = [
                                        'active_seeking' => 'bg-amber-500/10 text-amber-600',
                                        'driver_assigned' => 'bg-blue-500/10 text-blue-600',
                                        'on_the_way' => 'bg-emerald-500/10 text-emerald-600',
                                        'delivered' => 'bg-neutral-500/10 text-neutral-600',
                                        'cancelled' => 'bg-red-500/10 text-red-600',
                                    ];
                                    $statusLabels = [
                                        'active_seeking' => 'Aranıyor',
                                        'driver_assigned' => 'Atandı',
                                        'on_the_way' => 'Yolda',
                                        'delivered' => 'Teslim Edildi',
                                        'cancelled' => 'Askıda',
                                    ];
                                @endphp
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold {{ $statusClasses[$load->status] ?? '' }}">
                                    {{ $statusLabels[$load->status] ?? '' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-12 text-center text-neutral-400 dark:text-neutral-500">
                                <svg class="w-8 h-8 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span class="font-medium">Filtrelere uygun yük sevkiyat kaydı bulunamadı.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Sağ Bölüm: Çift Taraflı (Split-screen) Detay Paneli -->
        @if($selectedLoad)
            <div class="apple-glass rounded-3xl p-6 space-y-6 animate-slide-up sticky top-28">
                <!-- Üst Kontrol Butonları -->
                <div class="flex justify-between items-center border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                    <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Operasyonel Detay Paneli</h2>
                    <button wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Detay Kartı ve Güzergah -->
                <div class="space-y-4">
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40 text-xs space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <span class="text-neutral-400 block">Yük Sahibi (Gönderici)</span>
                                <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedLoad->cargoOwnerProfile->user->full_name ?? 'Bilinmiyor' }}</span>
                            </div>
                            <div>
                                <span class="text-neutral-400 block">Taşıyıcı (Şoför)</span>
                                <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedLoad->driverProfile->user->full_name ?? 'Atanmadı' }}</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <span class="text-neutral-400 block">Güzergah Mesafe/Yük</span>
                                <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedLoad->goods_type }} ({{ $selectedLoad->weight }} kg)</span>
                            </div>
                            <div>
                                <span class="text-neutral-400 block">e-İrsaliye No</span>
                                <span class="font-bold font-mono tracking-wider text-neutral-900 dark:text-white">{{ $selectedLoad->e_irsaliye_no ?? 'Yasal Belge Eksik' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Escrow (PayTR Güvenli Havuz) Durum Kartı -->
                <div class="p-4 bg-brand-500/5 rounded-2xl border border-brand-500/10 text-xs flex justify-between items-center">
                    <div>
                        <span class="font-semibold text-brand-500 block">PayTR Güvenli Havuz (Escrow) Durumu</span>
                        <span class="text-[11px] text-neutral-500 mt-1 block">Yük sahibi ödemeyi yaptıktan sonra bedel blokelenir.</span>
                    </div>
                    @php
                        $escrowBadges = [
                            'pending_payment' => 'bg-amber-500/10 text-amber-600',
                            'paid_in_escrow' => 'bg-emerald-500/10 text-emerald-600',
                            'released_to_driver' => 'bg-blue-500/10 text-blue-600',
                            'refunded_to_owner' => 'bg-red-500/10 text-red-600',
                        ];
                    @endphp
                    <span class="px-3 py-1 rounded-full font-bold text-[10px] {{ $escrowBadges[$selectedLoad->escrow_status] ?? '' }}">
                        {{ $escrowLabels[$selectedLoad->escrow_status] ?? '' }}
                    </span>
                </div>

                <!-- Manuel Yönetici Müdahale Paneli -->
                <div class="space-y-3 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <label class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">MANUEL ADMİN MÜDAHALE İSTASYONU</label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Navlun Bedeli Düzenleme -->
                        <button wire:click="openEditModal" class="btn-apple-secondary py-2.5 text-xs">
                            Fiyatı Düzenle (₺)
                        </button>

                        <!-- Askıya Alma / Geri Çekme Butonu -->
                        @if($selectedLoad->status === 'cancelled')
                            <button wire:click="toggleSuspend" class="btn-apple-primary py-2.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white">
                                Yayına Geri Al
                            </button>
                        @else
                            <button wire:click="toggleSuspend" class="btn-apple-secondary py-2.5 text-xs text-red-600 hover:bg-red-50 hover:border-red-200">
                                Askıya Al / İptal Et
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- FİYAT DÜZENLEME MODAL (Apple Tarzı) -->
    @if($showEditModal)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center overflow-y-auto p-4 bg-black/40 backdrop-blur-sm animate-fade-in">
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-3xl w-full max-w-sm mx-4 border border-neutral-100 dark:border-neutral-700/50 shadow-apple-lg">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Navlun Fiyatına Müdahale</h3>
                    <button wire:click="$set('showEditModal', false)" class="p-1.5 rounded-full hover:bg-neutral-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Sevkiyatın navlun bedeline yönetici sıfatıyla manuel müdahale etmektesiniz. Lütfen yeni fiyatı giriniz.</p>

                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-neutral-500">Yeni Navlun Bedeli (₺)</label>
                        <input type="number" wire:model="newPrice" class="w-full p-4 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-sm font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all duration-300">
                        @error('newPrice') <span class="text-red-500 text-[11px] block font-medium pl-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex justify-end space-x-3 pt-2">
                        <button wire:click="$set('showEditModal', false)" class="px-4 py-2 bg-neutral-100 dark:bg-neutral-700 text-neutral-900 dark:text-white text-xs rounded-xl hover:bg-neutral-200">Vazgeç</button>
                        <button wire:click="updatePrice" class="px-4 py-2 bg-brand-500 hover:bg-brand-600 text-white text-xs rounded-xl font-semibold">Fiyatı Güncelle</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
