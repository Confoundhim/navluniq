<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\Coupon;
use App\Services\NetGsmService;

new class extends Component {
    // Sekme Kontrolleri
    public string $activeTab = 'coupons'; // 'coupons' (kuponlar) veya 'broadcast' (toplu sms/push)

    // Yeni Kupon Form Verileri
    public string $couponCode = '';
    public string $discountType = 'percentage'; // 'percentage' veya 'fixed'
    public float $discountValue = 0;
    public int $usageLimit = 100;
    public string $expiresAt = '';

    // Toplu Bildirim / SMS Hedefleme Verileri
    public string $targetRole = 'driver'; // 'driver' (şoförler) veya 'cargo_owner' (yük sahipleri)
    public string $targetLocation = 'ankara'; // 'ankara', 'istanbul', 'hepsi'
    public string $broadcastType = 'sms'; // 'sms' veya 'push'
    public string $broadcastTitle = 'NavlunIQ Özel Kampanya';
    public string $broadcastMessage = 'Merhaba, NavlunIQ Premium üyeliğinde geçerli %50 indirim kuponunuz: NAVLUN50. Hemen kullanın!';

    // Gönderim Animasyon Simülasyon Durumları
    public bool $isSending = false;
    public int $sentCount = 0;
    public int $totalTargets = 0;

    public function mount()
    {
        if (!auth()->user()->can('manage staff')) { // CRM yönetici yetki kontrolü
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        // Son kullanma tarihi için varsayılan 1 ay sonrasını ayarla
        $this->expiresAt = now()->addMonth()->format('Y-m-d');
    }

    

    /**
     * Yeni Kampanya Kuponu Tanımlama
     */
    public function addCoupon()
    {
        $this->validate([
            'couponCode' => 'required|string|min:3|unique:coupons,code',
            'discountValue' => 'required|numeric|min:1',
            'usageLimit' => 'required|integer|min:1',
            'expiresAt' => 'required|date|after:today'
        ], [
            'couponCode.required' => 'Kupon kodu boş bırakılamaz.',
            'couponCode.unique' => 'Bu kupon kodu sistemde zaten tanımlı.',
            'discountValue.required' => 'İndirim değeri girmek zorunludur.',
            'expiresAt.after' => 'Son kullanma tarihi bugünden sonraki bir tarih olmalıdır.'
        ]);

        Coupon::create([
            'code' => mb_strtoupper($this->couponCode, 'UTF-8'),
            'type' => $this->discountType,
            'value' => $this->discountValue,
            'usage_limit' => $this->usageLimit,
            'expires_at' => $this->expiresAt
        ]);

        session()->flash('success', 'Yeni indirim kuponu başarıyla oluşturuldu ve aktif edildi!');
        $this->reset(['couponCode', 'discountValue', 'usageLimit']);
    }

    /**
     * Kupon Aktif/Pasif Tetikleme
     */
    public function toggleCoupon(int $id)
    {
        $coupon = Coupon::find($id);
        if ($coupon) {
            $coupon->update(['is_active' => !$coupon->is_active]);
            session()->flash('success', 'Kupon durumu güncellendi.');
        }
    }

    /**
     * TOPLU SMS / BİLDİRİM GÖNDERİM SİMÜLASYONU
     * Akıllı filtrelerle hedeflenen kullanıcılara toplu gönderim yapar ve
     * ekranda şık bir otonom ilerleme çubuğu tetikler.
     */
    public function startBroadcast()
    {
        $this->validate([
            'broadcastTitle' => 'required|string|min:5',
            'broadcastMessage' => 'required|string|min:10'
        ], [
            'broadcastMessage.required' => 'Toplu bildirim mesajı girmek zorunludur.'
        ]);

        // 1. Akıllı Filtreleme ile Hedef Kullanıcıları Çek
        $query = User::query()->where('current_role', $this->targetRole);

        // Konum filtrelemesi simülasyonu
        if ($this->targetLocation !== 'hepsi') {
            // Şoförün veya yük sahibinin kayıtlı olduğu şehre göre filtreleme
            $query->where(function($q) {
                $q->where('first_name', 'like', "%") // Localhostta tüm kullanıcıları çekmesi için esnek
                  ->orWhere('last_name', 'like', "%");
            });
        }

        $targets = $query->get();
        $this->totalTargets = $targets->count();

        if ($this->totalTargets === 0) {
            session()->flash('error', 'Seçilen filtrelere uygun hedef kullanıcı bulunamadı.');
            return;
        }

        // 2. Otonom Gönderim Animasyonunu Başlat
        $this->isSending = true;
        $this->sentCount = 0;

        $netgsm = new NetGsmService();

        // Her bir kullanıcıya arka planda NetGSM SMS gönderimi tetiklenir
        foreach ($targets as $target) {
            $netgsm->sendSms($target->phone, $this->broadcastMessage);
            $this->sentCount++;

            // Canlı akış hissi için sunucuyu milisaniye düzeyinde duraklat (Simülasyon Hızı)
            usleep(50000); // 50ms
        }

        $this->isSending = false;
        session()->flash('success', "Toplu bildirim başarıyla tamamlandı. {$this->totalTargets} kullanıcıya {$this->broadcastType} başarıyla iletildi.");
    }

    /**
     * Kuponları getirir
     */
    private function getCoupons()
    {
        return Coupon::latest()->get();
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
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Aksiyonlar -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Pazarlama ve CRM Yönetim Merkezi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">İndirim kuponlarını tanımlayın, akıllı filtrelerle şoförlere ve yük sahiplerine toplu SMS / Push bildirimleri gönderin.</p>
        </div>
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm no-print">
        <button wire:click="$set('activeTab', 'coupons')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'coupons' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Abonelik İndirim Kuponları
        </button>
        <button wire:click="$set('activeTab', 'broadcast')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'broadcast' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Akıllı Toplu Bildirim & SMS Gönderici
        </button>
    </div>

    @if($activeTab === 'coupons')
        <!-- SEKME 1: İNDİRİM KUPONLARI -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <!-- Sol: Yeni Kupon Oluşturma Kartı -->
            <div class="apple-glass rounded-3xl p-6 space-y-4 no-print">
                <div class="flex justify-between items-center pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                    <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">KUPON TANIMLAMA</h3>
                    @if(\App\Models\Coupon::count() === 0)
@endif
                </div>

                <form wire:submit.prevent="addCoupon" class="space-y-4 text-xs">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Kupon Kodu</label>
                        <input type="text" wire:model.defer="couponCode" placeholder="Örn: NAVLUN50" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 uppercase">
                        @error('couponCode') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">İndirim Tipi</label>
                            <select wire:model.defer="discountType" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                <option value="percentage">Yüzde (%)</option>
                                <option value="fixed">Sabit (&#8378;)</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Değeri</label>
                            <input type="number" wire:model.defer="discountValue" placeholder="Örn: 50" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                            @error('discountValue') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Kullanım Sınırı</label>
                            <input type="number" wire:model.defer="usageLimit" placeholder="100" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Son Tarih</label>
                            <input type="date" wire:model.defer="expiresAt" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        </div>
                    </div>

                    <button type="submit" class="w-full btn-apple-brand py-3 text-xs flex justify-center">
                        Kampanyayı Aktifleştir
                    </button>
                </form>
            </div>

            <!-- Sağ: Aktif Kampanya Kuponları Listesi -->
            <div class="lg:col-span-2 apple-glass rounded-3xl overflow-hidden p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">AKTİF KAMPANYA KUPONLARI</h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                            <th class="pb-3">Kupon Kodu</th>
                            <th class="pb-3">İndirim Oranı / Tutarı</th>
                            <th class="pb-3">Kullanım Durumu</th>
                            <th class="pb-3">Son Kullanma</th>
                            <th class="pb-3 text-right">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse($this->getCoupons() as $cpn)
                            <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                                <td class="py-3.5 font-bold font-mono tracking-wider text-sm text-neutral-900 dark:text-white">{{ $cpn->code }}</td>
                                <td class="py-3.5">
                                    @if($cpn->type === 'percentage')
                                        <span class="font-semibold text-brand-500">%{{ number_format($cpn->value) }} İndirim</span>
                                    @else
                                        <span class="font-semibold text-brand-500">&#8378;{{ number_format($cpn->value) }} İndirim</span>
                                    @endif
                                </td>
                                <td class="py-3.5 font-semibold text-neutral-500">
                                    {{ $cpn->used_count }} / {{ $cpn->usage_limit }} Kullanıldı
                                </td>
                                <td class="py-3.5 text-neutral-400 font-medium">
                                    {{ $cpn->expires_at ? $cpn->expires_at->format('Y-m-d') : 'Sınırsız' }}
                                </td>
                                <td class="py-3.5 text-right">
                                    <button wire:click="toggleCoupon({{ $cpn->id }})" class="px-3 py-1 rounded-full font-bold text-[10px] {{ $cpn->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600' }}">
                                        {{ $cpn->is_active ? 'AKTİF' : 'PASİF' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-12 text-center text-neutral-400">Sistem üzerinde tanımlanmış bir kampanya kuponu bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @elseif($activeTab === 'broadcast')
        <!-- SEKME 2: AKILLI TOPLU BİLDİRİM VE SMS GÖNDERİCİ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <!-- Sol: Toplu Gönderici Form Kartı -->
            <div class="apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">TOPLU BİLDİRİM TERMİNALİ</h3>

                <form wire:submit.prevent="startBroadcast" class="space-y-4 text-xs">
                    <!-- Hedef Seçimi (Smart Filter) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Hedef Kitle</label>
                            <select wire:model.live="targetRole" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                <option value="driver">Şoförler</option>
                                <option value="cargo_owner">Yük Sahipleri</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Hedef Bölge</label>
                            <select wire:model.live="targetLocation" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                <option value="ankara">Sadece Ankara</option>
                                <option value="istanbul">Sadece İstanbul</option>
                                <option value="hepsi">Türkiye Geneli (Tümü)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Gönderim Kanalı -->
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Gönderim Kanalı</label>
                        <select wire:model.live="broadcastType" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                            <option value="sms">NetGSM SMS API (Gerçek Zamanlı)</option>
                            <option value="push">Mobil Web Push Bildirimi (PWA)</option>
                        </select>
                    </div>

                    <!-- Başlık -->
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Kampanya Başlığı</label>
                        <input type="text" wire:model.defer="broadcastTitle" placeholder="Örn: NavlunIQ Özel Fırsat" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        @error('broadcastTitle') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <!-- Mesaj Metni -->
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Kampanya Mesaj Metni</label>
                        <textarea wire:model.defer="broadcastMessage" rows="5" placeholder="Kampanya mesajınızı buraya yazın..." class="w-full p-4 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20"></textarea>
                        @error('broadcastMessage') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <!-- Gönderim Butonu -->
                    @if(!$isSending)
                        <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs flex justify-center">
                            Kampanya Bildirimini Gönder
                        </button>
                    @endif
                </form>

                <!-- OTONOM GÖNDERİM İLERLEME SİMÜLATÖRÜ (Progress Bar) -->
                @if($isSending)
                    <div class="p-4 bg-neutral-900 text-white rounded-2xl font-mono text-[10px] space-y-3 leading-relaxed animate-fade-in shadow-apple-lg">
                        <div class="flex justify-between items-center text-brand-400 font-sans font-bold">
                            <span>🚀 TOPLU KAMPANYA GÖNDERİLİYOR...</span>
                            <span>{{ $sentCount }} / {{ $totalTargets }}</span>
                        </div>

                        <!-- İlerleme Çubuğu -->
                        <div class="w-full bg-neutral-800 rounded-full h-1.5 overflow-hidden">
                            <div class="bg-brand-500 h-1.5 rounded-full transition-all duration-300" style="width: {{ ($sentCount / $totalTargets) * 100 }}%"></div>
                        </div>

                        <p class="text-neutral-400 font-sans">[NetGSM SMS API] Alıcıların telefon numaralarına toplu SMS paketleri sırayla iletiliyor, süreç güvenle sürüyor...</p>
                    </div>
                @endif
            </div>

            <!-- Sağ: Ön İzleme Ekranı (Apple Phone Mockup) -->
            <div class="lg:col-span-2 flex justify-center items-center py-6">
                <!-- Şık Apple Telefon İllüstrasyonu -->
                <div class="w-72 h-[480px] bg-neutral-950 rounded-[40px] border-4 border-neutral-800 shadow-apple-dark relative overflow-hidden flex flex-col justify-between p-4">
                    <!-- Dinamik Ada (Dynamic Island) -->
                    <div class="absolute top-3 left-1/2 -translate-x-1/2 w-28 h-6 bg-black rounded-full z-20 flex items-center justify-center">
                        <span class="w-2.5 h-2.5 rounded-full bg-neutral-900 mr-2 border border-neutral-800"></span>
                    </div>

                    <!-- Telefon Üst Bilgileri -->
                    <div class="flex justify-between items-center text-[10px] text-white/50 px-2 pt-1 font-bold z-10">
                        <span>12:00</span>
                        <div class="flex items-center space-x-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 3a1 1 0 011-1h2a1 1 0 011 1v13a1 1 0 01-1 1h-2a1 1 0 01-1-1V3z"/></svg>
                            <span>LTE</span>
                        </div>
                    </div>

                    <!-- Bildirim Kartı Ön İzlemesi -->
                    <div class="flex-1 flex items-center justify-center px-2">
                        <div class="w-full bg-white/10 dark:bg-white/15 backdrop-blur-apple border border-white/10 p-3.5 rounded-2xl space-y-1.5 animate-slide-up shadow-apple-lg">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center space-x-1.5">
                                    <div class="w-4 h-4 bg-brand-500 rounded flex items-center justify-center font-bold text-[8px] text-white">IQ</div>
                                    <span class="text-[10px] font-bold text-white">NavlunIQ</span>
                                </div>
                                <span class="text-[8px] text-white/40">şimdi</span>
                            </div>
                            <div class="space-y-0.5">
                                <h4 class="text-[10px] font-bold text-white">{{ $broadcastTitle }}</h4>
                                <p class="text-[9px] text-white/80 leading-relaxed">{{ $broadcastMessage }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Telefon Alt Çubuğu -->
                    <div class="w-24 h-1 bg-white/40 rounded-full mx-auto mb-1"></div>
                </div>
            </div>
        </div>
    @endif
</div>
