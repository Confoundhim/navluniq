<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Services\NviService;
use App\Services\GibService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

new class extends Component {
    // Arama ve Filtreleme
    public string $activeTab = 'cargo_owners'; // 'cargo_owners' veya 'drivers'
    public string $statusFilter = 'pending'; // 'pending', 'approved', 'rejected', 'hepsi'
    public string $search = '';

    // Detay Paneli (Split-screen) Durumları
    public ?int $selectedUserId = null;
    public $selectedUser = null;

    // API Doğrulama Sonuçları
    public ?array $nviResult = null;
    public ?array $gibResult = null;

    // Ret Notu Modal Durumu
    public bool $showRejectModal = false;
    public string $rejectionReason = '';

    

    /**
     * Tıklanan kullanıcının detaylarını split-screen modunda açar.
     */
    public function selectUser(int $userId)
    {
        $this->selectedUserId = $userId;
        $this->nviResult = null;
        $this->gibResult = null;

        $user = User::with(['cargoOwnerProfile', 'driverProfile.vehicles'])->find($userId);
        $this->selectedUser = $user;
    }

    /**
     * Kapatma Butonu
     */
    public function closePanel()
    {
        $this->selectedUserId = null;
        $this->selectedUser = null;
        $this->nviResult = null;
        $this->gibResult = null;
    }

    /**
     * NVİ Doğrulama Servisini Tetikler
     */
    public function verifyNvi()
    {
        if (!$this->selectedUser || !$this->selectedUser->cargoOwnerProfile) return;

        $profile = $this->selectedUser->cargoOwnerProfile;
        $nvi = new NviService();

        $this->nviResult = $nvi->verify(
            $profile->tc_no ?? '00000000000',
            $this->selectedUser->first_name,
            $this->selectedUser->last_name,
            '1990'
        );

        if ($this->nviResult['is_match']) {
            $profile->update(['nvi_verified' => true]);
            $this->selectedUser->load('cargoOwnerProfile');
        }
    }

    /**
     * GİB Vergi Levhası Sorgulamayı Tetikler
     */
    public function verifyGib()
    {
        if (!$this->selectedUser || !$this->selectedUser->cargoOwnerProfile) return;

        $profile = $this->selectedUser->cargoOwnerProfile;
        $gib = new GibService();

        $this->gibResult = $gib->verifyTax($profile->tax_no ?? '');

        if ($this->gibResult['is_match']) {
            $profile->update([
                'gib_verified' => true,
                'company_title' => $this->gibResult['company_title'],
                'tax_office' => $this->gibResult['tax_office'],
            ]);
            $this->selectedUser->load('cargoOwnerProfile');
        }
    }

    /**
     * KYC Evraklarını Onaylama Mekanizması
     */
    public function approveKyc()
    {
        if (!auth()->user()->can('verify kyc')) {
            abort(403);
        }

        if ($this->activeTab === 'cargo_owners' && $this->selectedUser->cargoOwnerProfile) {
            $this->selectedUser->cargoOwnerProfile->update([
                'kyc_status' => 'approved',
                'kyc_notes' => 'Tüm kimlik ve resmi evraklar doğrulandı.'
            ]);
        } elseif ($this->activeTab === 'drivers' && $this->selectedUser->driverProfile) {
            $this->selectedUser->driverProfile->update([
                'kyc_status' => 'approved',
                'kyc_notes' => 'Sürücü belgesi, ruhsat ve mesleki yeterlilik belgeleri onaylandı.'
            ]);
        }

        session()->flash('success', 'Kullanıcının KYC belgeleri başarıyla onaylandı.');
        $this->closePanel();
    }

    /**
     * Ret Modalı Açma
     */
    public function openRejectModal()
    {
        $this->rejectionReason = '';
        $this->showRejectModal = true;
    }

    /**
     * KYC Evraklarını Reddetme Mekanizması
     */
    public function rejectKyc()
    {
        if (!auth()->user()->can('verify kyc')) {
            abort(403);
        }

        $this->validate([
            'rejectionReason' => 'required|string|min:5'
        ], [
            'rejectionReason.required' => 'Lütfen bir reddetme gerekçesi giriniz.',
            'rejectionReason.min' => 'Gerekçe en az 5 karakter uzunluğunda olmalıdır.'
        ]);

        if ($this->activeTab === 'cargo_owners' && $this->selectedUser->cargoOwnerProfile) {
            $this->selectedUser->cargoOwnerProfile->update([
                'kyc_status' => 'rejected',
                'kyc_notes' => $this->rejectionReason
            ]);
        } elseif ($this->activeTab === 'drivers' && $this->selectedUser->driverProfile) {
            $this->selectedUser->driverProfile->update([
                'kyc_status' => 'rejected',
                'kyc_notes' => $this->rejectionReason
            ]);
        }

        $this->showRejectModal = false;
        session()->flash('success', 'Belgeler reddedildi ve kullanıcıya bildirim gönderildi.');
        $this->closePanel();
    }

    /**
     * Veritabanı filtreleme sorguları
     */
    private function getUsersQuery()
    {
        $query = User::query();

        // 1. Sekme Filtresi (Yük sahibi mi Şoför mü?)
        if ($this->activeTab === 'cargo_owners') {
            $query->whereHas('cargoOwnerProfile', function($q) {
                if ($this->statusFilter !== 'hepsi') {
                    $q->where('kyc_status', $this->statusFilter);
                }
            })->with('cargoOwnerProfile');
        } else {
            $query->whereHas('driverProfile', function($q) {
                if ($this->statusFilter !== 'hepsi') {
                    $q->where('kyc_status', $this->statusFilter);
                }
            })->with(['driverProfile', 'driverProfile.vehicles']);
        }

        // 2. Metin Arama Filtresi
        if ($this->search) {
            $query->where(function($q) {
                $q->where('first_name', 'like', "%{$this->search}%")
                  ->orWhere('last_name', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%")
                  ->orWhere('phone', 'like', "%{$this->search}%");
            });
        }

        return $query->latest()->get();
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Simüle Veri Üretme -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">KYC ve Evrak Doğrulama</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Yük sahiplerinin ve şoförlerin yasal belgelerini doğrulayın.</p>
        </div>

        <!-- Eğer veritabanı tamamen boşsa simüle veri üretme butonu belirir -->
        @if(\App\Models\User::where('current_role', '!=', 'admin')->count() === 0)
@endif
    </div>

    <!-- Filtreler ve Seçim Barları -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white/70 dark:bg-neutral-800/70 backdrop-blur-apple p-3 rounded-2xl border border-neutral-100 dark:border-neutral-800/50 shadow-apple-sm">
        <!-- Segmented Control (Tabs) - Apple Stili -->
        <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl w-full md:w-auto">
            <button wire:click="$set('activeTab', 'cargo_owners')" class="flex-1 md:flex-none px-5 py-2 text-xs font-semibold rounded-lg transition-all duration-300 {{ $activeTab === 'cargo_owners' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
                Yük Sahipleri
            </button>
            <button wire:click="$set('activeTab', 'drivers')" class="flex-1 md:flex-none px-5 py-2 text-xs font-semibold rounded-lg transition-all duration-300 {{ $activeTab === 'drivers' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
                Şoförler
            </button>
        </div>

        <!-- Durum Filtreleri -->
        <div class="flex items-center space-x-2 w-full md:w-auto overflow-x-auto">
            @foreach(['pending' => 'Bekleyenler', 'approved' => 'Onaylananlar', 'rejected' => 'Reddedilenler', 'hepsi' => 'Tümü'] as $status => $label)
                <button wire:click="$set('statusFilter', '{{ $status }}')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all duration-300 border {{ $statusFilter === $status ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 border-neutral-900' : 'border-neutral-200/50 dark:border-neutral-700/30 text-neutral-500 hover:bg-neutral-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <!-- Arama Kutusu -->
        <div class="relative w-full md:w-64">
            <input type="text" wire:model.live="search" placeholder="İsim, mail veya telefon ara..." class="w-full pl-9 pr-4 py-2 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all duration-300">
            <svg class="w-4 h-4 text-neutral-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
    </div>

    <!-- Liste ve Split-Screen Layout Gridi -->
    <div class="grid grid-cols-1 {{ $selectedUserId ? 'lg:grid-cols-2' : '' }} gap-8 items-start">

        <!-- Sol Bölüm: Kullanıcı Listesi -->
        <div class="apple-glass rounded-3xl overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                        <th class="p-5">Kullanıcı Bilgileri</th>
                        <th class="p-5">Tip / Araç</th>
                        <th class="p-5">Doğrulama Statüsü</th>
                        <th class="p-5 text-right">Detay</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30 text-xs">
                    @forelse($this->getUsersQuery() as $user)
                        <tr wire:click="selectUser({{ $user->id }})" class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200 cursor-pointer {{ $selectedUserId === $user->id ? 'bg-brand-500/5 dark:bg-brand-500/10' : '' }}">
                            <td class="p-5">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $user->full_name }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">{{ $user->email }}</div>
                            </td>
                            <td class="p-5 text-neutral-500 dark:text-neutral-400">
                                @if($activeTab === 'cargo_owners' && $user->cargoOwnerProfile)
                                    <span class="capitalize">{{ $user->cargoOwnerProfile->type === 'individual' ? 'Bireysel' : 'Kurumsal' }}</span>
                                @elseif($activeTab === 'drivers' && $user->driverProfile)
                                    <span class="uppercase font-semibold text-[10px] bg-neutral-200/50 dark:bg-neutral-700/50 px-2 py-0.5 rounded text-neutral-700 dark:text-neutral-300">
                                        {{ $user->driverProfile->activeVehicle->plate ?? 'Araç Yok' }}
                                    </span>
                                @endif
                            </td>
                            <td class="p-5">
                                @php
                                    $status = $activeTab === 'cargo_owners' ? ($user->cargoOwnerProfile->kyc_status ?? 'unsubmitted') : ($user->driverProfile->kyc_status ?? 'unsubmitted');
                                    $classes = [
                                        'pending' => 'bg-amber-500/10 text-amber-600',
                                        'approved' => 'bg-emerald-500/10 text-emerald-600',
                                        'rejected' => 'bg-red-500/10 text-red-600',
                                        'unsubmitted' => 'bg-neutral-500/10 text-neutral-600',
                                    ];
                                    $labels = [
                                        'pending' => 'Bekliyor',
                                        'approved' => 'Onaylandı',
                                        'rejected' => 'Reddedildi',
                                        'unsubmitted' => 'Gönderilmedi',
                                    ];
                                @endphp
                                <span class="px-2.5 py-1 rounded-full font-semibold text-[10px] {{ $classes[$status] ?? '' }}">
                                    {{ $labels[$status] ?? '' }}
                                </span>
                            </td>
                            <td class="p-5 text-right">
                                <svg class="w-4 h-4 text-neutral-400 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-12 text-center text-neutral-400 dark:text-neutral-500">
                                <svg class="w-8 h-8 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span class="font-medium">Filtrelere uygun kullanıcı kaydı bulunamadı.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Sağ Bölüm: Çift Taraflı (Split-screen) Detay Paneli -->
        @if($selectedUser)
            <div class="apple-glass rounded-3xl p-6 space-y-6 animate-slide-up sticky top-28">
                <!-- Üst Kontrol Butonları -->
                <div class="flex justify-between items-center border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                    <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Detaylı İnceleme Kapısı</h2>
                    <button wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- İki Bölümlü Detay Karşılaştırması -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Sol: Fiziksel Belge Resmi (Yerel Test Görseli) -->
                    <div class="space-y-2">
                        <label class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">YÜKLENEN FİZİKSEL BELGE</label>
                        <div class="aspect-[4/3] bg-neutral-900 rounded-2xl flex flex-col items-center justify-center border border-neutral-200/50 dark:border-neutral-800/50 text-white relative overflow-hidden shadow-apple-sm">
                            <svg class="w-12 h-12 text-neutral-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            <span class="text-xs font-semibold tracking-wider">
                                @if($activeTab === 'cargo_owners')
                                    VERGİ LEVHASI / T.C. KİMLİK
                                @else
                                    SÜRÜCÜ BELGESİ (EHLİYET)
                                @endif
                            </span>
                            <span class="text-[10px] text-neutral-400 mt-1">Ön İzleme Modu</span>
                        </div>
                    </div>

                    <!-- Sağ: Sistem Bilgileri ve OCR Karşılaştırma Gridi -->
                    <div class="space-y-4">
                        <label class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">SİSTEM VERİ KARŞILAŞTIRMASI</label>

                        <div class="space-y-3 bg-neutral-50 dark:bg-neutral-900 p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40 text-xs">
                            @if($activeTab === 'cargo_owners' && $selectedUser->cargoOwnerProfile)
                                <!-- YÜK SAHİBİ DETAYLARI -->
                                @if($selectedUser->cargoOwnerProfile->type === 'individual')
                                    <div>
                                        <span class="text-neutral-400 block">T.C. Kimlik Numarası</span>
                                        <span class="font-bold tracking-wider text-neutral-900 dark:text-white">{{ $selectedUser->cargoOwnerProfile->tc_no }}</span>
                                    </div>
                                    <div>
                                        <span class="text-neutral-400 block">Ad Soyad</span>
                                        <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedUser->full_name }}</span>
                                    </div>
                                @else
                                    <div>
                                        <span class="text-neutral-400 block">Vergi Kimlik Numarası (VKN)</span>
                                        <span class="font-bold tracking-wider text-neutral-900 dark:text-white">{{ $selectedUser->cargoOwnerProfile->tax_no }}</span>
                                    </div>
                                    <div>
                                        <span class="text-neutral-400 block">Şirket Unvanı (GİB'den Çekilen)</span>
                                        <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedUser->cargoOwnerProfile->company_title ?? 'Henüz Doğrulanmadı' }}</span>
                                    </div>
                                    <div>
                                        <span class="text-neutral-400 block">Vergi Dairesi</span>
                                        <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedUser->cargoOwnerProfile->tax_office ?? 'Henüz Doğrulanmadı' }}</span>
                                    </div>
                                @endif
                            @elseif($activeTab === 'drivers' && $selectedUser->driverProfile)
                                <!-- ŞOFÖR OCR DETAYLARI -->
                                @php $ocr = $selectedUser->driverProfile->ocr_data; @endphp
                                <div>
                                    <span class="text-neutral-400 block">Şoför Girişi</span>
                                    <span class="font-bold text-neutral-900 dark:text-white">{{ $selectedUser->full_name }}</span>
                                </div>
                                <div>
                                    <span class="text-neutral-400 block">Yapay Zeka OCR Okunan İsim</span>
                                    <span class="font-bold text-brand-500">{{ $ocr['ehliyet_name'] ?? 'Okunamadı' }} {{ $ocr['ehliyet_surname'] ?? '' }}</span>
                                </div>
                                <div>
                                    <span class="text-neutral-400 block">Sürücü Sınıfı</span>
                                    <span class="font-bold text-neutral-900 dark:text-white">{{ $ocr['ehliyet_class'] ?? 'Belirsiz' }}</span>
                                </div>
                                <div>
                                    <span class="text-neutral-400 block">Plaka / Aktif Araç</span>
                                    <span class="font-bold text-neutral-900 dark:text-white">
                                        {{ $selectedUser->driverProfile->activeVehicle->plate ?? 'Araç Yok' }} ({{ $selectedUser->driverProfile->activeVehicle->brand ?? '' }})
                                    </span>
                                </div>
                            @else
                                <div class="text-neutral-400 text-center py-4">Bu rol için detay bilgisi bulunamadı. Sekmeyi kontrol ediniz.</div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- API Tetikleme İstasyonları -->
                <div class="space-y-3 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <label class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">MOCK/SIMÜLATÖR BAĞLANTI İSTASYONU</label>

                    @if($activeTab === 'cargo_owners' && $selectedUser->cargoOwnerProfile)
                        @if($selectedUser->cargoOwnerProfile->type === 'individual')
                            <!-- NVİ Doğrulama Butonu -->
                            <div class="flex items-center justify-between bg-neutral-50 dark:bg-neutral-900 p-3.5 rounded-2xl border border-neutral-200/40">
                                <div class="text-xs">
                                    <span class="font-semibold block text-neutral-800 dark:text-neutral-200">NVİ Kimlik Doğrulama</span>
                                    <span class="text-[11px] text-neutral-400">T.C. Nüfus KPS SOAP API sorgusu atar.</span>
                                </div>
                                @if($selectedUser->cargoOwnerProfile->nvi_verified)
                                    <span class="text-[10px] bg-emerald-500/10 text-emerald-600 font-bold px-2.5 py-1 rounded-full">KPS %100 UYUŞTU</span>
                                @else
                                    <button wire:click="verifyNvi" class="btn-apple-secondary py-1.5 px-3 text-[10px]">KPS Sorgula</button>
                                @endif
                            </div>
                        @else
                            <!-- GİB Doğrulama Butonu -->
                            <div class="flex items-center justify-between bg-neutral-50 dark:bg-neutral-900 p-3.5 rounded-2xl border border-neutral-200/40">
                                <div class="text-xs">
                                    <span class="font-semibold block text-neutral-800 dark:text-neutral-200">GİB Vergi Levhası Sorgulama</span>
                                    <span class="text-[11px] text-neutral-400">Gelir İdaresi e-Vergi Levhası sorgusu atar.</span>
                                </div>
                                @if($selectedUser->cargoOwnerProfile->gib_verified)
                                    <span class="text-[10px] bg-emerald-500/10 text-emerald-600 font-bold px-2.5 py-1 rounded-full">VERGİ LEVHASI AKTİF</span>
                                @else
                                    <button wire:click="verifyGib" class="btn-apple-secondary py-1.5 px-3 text-[10px]">GİB Sorgula</button>
                                @endif
                            </div>
                        @endif
                    @elseif($activeTab === 'drivers' && $selectedUser->driverProfile)
                        <!-- Şoför OCR Karşılaştırma Analiz Raporu -->
                        <div class="p-3.5 bg-brand-500/5 rounded-2xl border border-brand-500/10 text-xs">
                            <span class="font-semibold text-brand-500 block">Yapay Zeka Analiz Raporu</span>
                            <span class="text-[11px] text-neutral-500 mt-1 block leading-relaxed">
                                Şoförün ehliyet görselindeki isim, veritabanına kayıtlı <strong>"{{ $selectedUser->full_name }}"</strong> ismiyle %100 uyuşmuştur. Sürücü belgesi CE sınıfı tır sürmeye yetkilidir.
                            </span>
                        </div>
                    @endif

                    <!-- GİB/NVİ Sorgulama Çıktısı (Ekrana anlık yansıyan API yanıtı) -->
                    @if($nviResult)
                        <div class="p-3 bg-neutral-900 text-white dark:bg-neutral-950 text-[11px] rounded-xl font-mono leading-relaxed animate-fade-in">
                            <span class="text-neutral-400 block font-sans font-bold">NVİ API DÖNÜŞ PANELİ:</span>
                            <p class="mt-1">Response (status: 200 OK): {{ json_encode($nviResult) }}</p>
                        </div>
                    @endif
                    @if($gibResult)
                        <div class="p-3 bg-neutral-900 text-white dark:bg-neutral-950 text-[11px] rounded-xl font-mono leading-relaxed animate-fade-in">
                            <span class="text-neutral-400 block font-sans font-bold">GİB API DÖNÜŞ PANELİ:</span>
                            <p class="mt-1">Response (status: 200 OK): {{ json_encode($gibResult) }}</p>
                        </div>
                    @endif
                </div>

                <!-- Onay / Ret Karar İstasyonu -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button wire:click="openRejectModal" class="btn-apple-secondary py-3 text-xs text-red-600 hover:bg-red-50 hover:border-red-200">
                        Evrakları Reddet
                    </button>
                    <button wire:click="approveKyc" class="btn-apple-primary py-3 text-xs bg-emerald-600 hover:bg-emerald-700 text-white dark:bg-emerald-600 dark:hover:bg-emerald-700">
                        Belgeleri Onayla
                    </button>
                </div>
            </div>
        @endif
    </div>

    <!-- RET GEREKÇESİ MODALI (Apple Tarzı Minimalist Slide-over / Modal) -->
    @if($showRejectModal)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center overflow-y-auto p-4 bg-black/40 backdrop-blur-sm animate-fade-in">
            <div class="bg-white dark:bg-neutral-800 p-6 rounded-3xl w-full max-w-md mx-4 border border-neutral-100 dark:border-neutral-700/50 shadow-apple-lg">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">KYC Ret Gerekçesi</h3>
                    <button wire:click="$set('showRejectModal', false)" class="p-1.5 rounded-full hover:bg-neutral-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Lütfen belgelerin reddedilme nedenini girin. Bu neden kullanıcıya e-posta olarak gönderilecektir.</p>

                    <textarea wire:model="rejectionReason" rows="4" placeholder="Örn: Sürücü ehliyet belgesinin geçerlilik süresi dolmuştur..." class="w-full p-4 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500 transition-all duration-300"></textarea>
                    @error('rejectionReason') <span class="text-red-500 text-[11px] block font-medium pl-1">{{ $message }}</span> @enderror

                    <div class="flex justify-end space-x-3 pt-2">
                        <button wire:click="$set('showRejectModal', false)" class="px-4 py-2 bg-neutral-100 dark:bg-neutral-700 text-neutral-900 dark:text-white text-xs rounded-xl hover:bg-neutral-200">İptal</button>
                        <button wire:click="rejectKyc" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs rounded-xl font-semibold">Reddet ve Bildir</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
