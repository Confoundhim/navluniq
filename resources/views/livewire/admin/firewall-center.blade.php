<?php

use Livewire\Volt\Component;
use App\Models\BannedIp;
use App\Models\CmsContent;

new class extends Component {
    // Sekme Kontrolü
    public string $activeTab = 'ips'; // 'ips' (yasaklı IP'ler) veya 'ratelimit' (istek limitleri)

    // Yeni IP Yasaklama Formu
    public string $newIpAddress = '';
    public string $banReason = 'DDoS / Şüpheli Bot Trafiği';
    public string $banType = 'permanent'; // 'permanent' veya 'temporary'
    public int $banDays = 7;

    // Rate Limiting Ayarları
    public int $rateLimitPerMinute = 60;
    public int $maxLoginAttempts = 5;
    public int $autoBanDuration = 60; // dakika cinsinden

    public function mount()
    {
        if (!auth()->user()->can('manage settings')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        $this->loadRateLimits();
    }

    

    /**
     * Veritabanındaki hız sınırlama ayarlarını yükler.
     */
    public function loadRateLimits(): void
    {
        $this->rateLimitPerMinute = (int) CmsContent::getVal('firewall_rate_limit_per_minute', 60);
        $this->maxLoginAttempts = (int) CmsContent::getVal('firewall_max_login_attempts', 5);
        $this->autoBanDuration = (int) CmsContent::getVal('firewall_auto_ban_duration', 60);
    }

    /**
     * MANUEL YENİ IP YASAKLAMA
     */
    public function banIp()
    {
        $this->validate([
            'newIpAddress' => 'required|ip|unique:banned_ips,ip_address',
            'banReason' => 'required|string|min:5'
        ], [
            'newIpAddress.required' => 'IP adresi girmek zorunludur.',
            'newIpAddress.ip' => 'Lütfen geçerli bir IPv4 veya IPv6 adresi giriniz.',
            'newIpAddress.unique' => 'Bu IP adresi zaten yasaklılar listesinde.'
        ]);

        $bannedUntil = $this->banType === 'temporary' ? now()->addDays($this->banDays) : null;

        BannedIp::create([
            'ip_address' => $this->newIpAddress,
            'reason' => $this->banReason,
            'banned_until' => $bannedUntil
        ]);

        $this->reset(['newIpAddress', 'banReason']);
        session()->flash('success', 'IP adresi başarıyla engellendi. Güvenlik duvarı bağlantısını anında kesti!');
    }

    /**
     * IP ENGELİNİ KALDIR (Unban)
     */
    public function unbanIp(int $id)
    {
        BannedIp::destroy($id);
        session()->flash('success', 'IP engel kaydı başarıyla kaldırıldı.');
    }

    /**
     * RATE LIMITING AYARLARINI KAYDET
     */
    public function saveRateLimits()
    {
        CmsContent::updateOrCreate(['key' => 'firewall_rate_limit_per_minute'], ['value' => $this->rateLimitPerMinute]);
        CmsContent::updateOrCreate(['key' => 'firewall_max_login_attempts'], ['value' => $this->maxLoginAttempts]);
        CmsContent::updateOrCreate(['key' => 'firewall_auto_ban_duration'], ['value' => $this->autoBanDuration]);

        session()->flash('success', 'DDoS ve Rate Limiting istek eşik ayarları güncellendi.');
    }

    /**
     * Yasaklı IP kayıtlarını listeler
     */
    private function getBannedIps()
    {
        return BannedIp::latest()->get();
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

    <!-- Üst Başlık ve Simüle Tehdit Üretme -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Güvenlik Duvarı ve IP Ban Yönetimi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Bot saldırılarını engelleyin, şüpheli IP adreslerini yasaklayın ve Rate Limiting kurallarını yönetin.</p>
        </div>

        @if(\App\Models\BannedIp::count() === 0)
@endif
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm">
        <button wire:click="$set('activeTab', 'ips')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'ips' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Engellenen IP Adresleri (Firewall)
        </button>
        <button wire:click="$set('activeTab', 'ratelimit')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'ratelimit' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            DDoS ve Rate Limiting Kalkanı
        </button>
    </div>

    @if($activeTab === 'ips')
        <!-- SEKME 1: ENGELLENEN IP LİSTESİ VE MANUEL BAN -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start text-xs">
            <!-- Sol: Yeni IP Yasaklama Kartı -->
            <div class="apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">MANUEL IP ENGELLEME</h3>

                <form wire:submit.prevent="banIp" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">IP Adresi (IPv4 veya IPv6)</label>
                        <input type="text" wire:model="newIpAddress" placeholder="Örn: 198.51.100.45" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white font-mono rounded-xl focus:outline-none">
                        @error('newIpAddress') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Engel Gerekçesi</label>
                        <select wire:model="banReason" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                            <option value="DDoS / Şüpheli Bot Trafiği">DDoS / Şüpheli Bot Trafiği</option>
                            <option value="Brute Force Hatalı Şifre Denemesi">Brute Force Hatalı Şifre Denemesi</option>
                            <option value="Kötü Niyetli Veri Kazıma (Scraping)">Kötü Niyetli Veri Kazıma (Scraping)</option>
                            <option value="Yönetici Manuel Kararı">Yönetici Manuel Kararı</option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Engel Türü</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-center space-x-2 p-2 rounded-lg bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 cursor-pointer">
                                <input type="radio" wire:model.live="banType" value="permanent" class="accent-brand-500">
                                <span>Kalıcı Ban</span>
                            </label>
                            <label class="flex items-center space-x-2 p-2 rounded-lg bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 cursor-pointer">
                                <input type="radio" wire:model.live="banType" value="temporary" class="accent-brand-500">
                                <span>Süreli Ban</span>
                            </label>
                        </div>
                    </div>

                    @if($banType === 'temporary')
                        <div class="space-y-1.5 animate-slide-up">
                            <label class="font-semibold text-neutral-500">Engel Süresi (Gün)</label>
                            <input type="number" wire:model="banDays" min="1" max="365" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                    @endif

                    <button type="submit" class="w-full btn-apple-primary py-3 text-xs bg-red-600 hover:bg-red-700 text-white font-bold">
                        IP Adresini Yasakla (Firewall Ban)
                    </button>
                </form>
            </div>

            <!-- Sağ: Engellenen IP Listesi -->
            <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">GÜVENLİK DUVARINDA YASAKLI IP ADRESLERİ</h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                            <th class="pb-3">IP Adresi</th>
                            <th class="pb-3">Engel Gerekçesi</th>
                            <th class="pb-3">Geçerlilik</th>
                            <th class="pb-3 text-right">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse($this->getBannedIps() as $b)
                            <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                                <td class="py-3.5 font-bold font-mono text-sm text-red-500">{{ $b->ip_address }}</td>
                                <td class="py-3.5 font-medium text-neutral-600 dark:text-neutral-300">{{ $b->reason }}</td>
                                <td class="py-3.5 text-neutral-400 font-mono text-[11px]">
                                    {{ $b->banned_until ? $b->banned_until->format('Y-m-d H:i') : 'Kalıcı Engel' }}
                                </td>
                                <td class="py-3.5 text-right">
                                    <button wire:click="unbanIp({{ $b->id }})" class="btn-apple-secondary py-1 px-3 text-[10px] text-emerald-600 hover:bg-emerald-50 hover:border-emerald-200 font-bold">
                                        Engeli Kaldır (Unban)
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-12 text-center text-neutral-400">Güvenlik duvarında şu anda engellenmiş bir IP adresi bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @elseif($activeTab === 'ratelimit')
        <!-- SEKME 2: DDOS VE RATE LIMITING KALKANI AYARLARI -->
        <div class="apple-glass rounded-3xl p-6 space-y-6 text-xs">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">DDOS KORUMASI VE RATE LIMITING EŞİK AYARLARI</h3>

            <form wire:submit.prevent="saveRateLimits" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <label class="font-bold text-neutral-900 dark:text-white block">Maksimum İstek Limiti (Dakikada)</label>
                        <input type="number" wire:model="rateLimitPerMinute" class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Bir IP'den dakikada gelebilecek maksimum ilan/sayfa isteği.</span>
                    </div>

                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <label class="font-bold text-neutral-900 dark:text-white block">Maksimum Hatalı Giriş Sınırı</label>
                        <input type="number" wire:model="maxLoginAttempts" class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Brute-force kalkanı: Üst üste hatalı şifre denemesi limiti.</span>
                    </div>

                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <label class="font-bold text-neutral-900 dark:text-white block">Oto-Ban Karantina Süresi (Dakika)</label>
                        <input type="number" wire:model="autoBanDuration" class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Sınırı aşan saldırgan IP'nin otomatik karantinaya alınma süresi.</span>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Güvenlik Duvarı Kurallarını Kaydet
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
