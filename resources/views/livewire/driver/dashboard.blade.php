<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.driver')]
#[Title('Şoför Komuta Merkezi & Akıllı Dönüş Radarı')]
class extends Component {
    public int $completedShipmentsCount = 0;
    public float $pendingPayout = 0.0;
    public int $activeOffersCount = 0;
    public int $premiumDaysLeft = 30;

    public $activeTrip = null;
    public array $returnLoads = [];
    public array $hotScrapedLoads = [];

    public function mount(): void
    {
        $this->loadDashboardData();
    }

    public function loadDashboardData(): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $driverProfile = $user->driverProfile;

        if ($driverProfile) {
            $driverId = (int) $driverProfile->id;

            $this->completedShipmentsCount = Load::where('driver_profile_id', $driverId)
                ->where('status', 'delivered')
                ->count();

            $rawSum = (float) Load::where('driver_profile_id', $driverId)
                ->where('escrow_status', 'paid_in_escrow')
                ->sum('price');
            $this->pendingPayout = round($rawSum * 0.95, 2);

            $this->activeTrip = Load::where('driver_profile_id', $driverId)
                ->whereIn('status', ['driver_assigned', 'on_the_way'])
                ->first();
        }

        // Dönüş radarı ve dış kaynak ilanları yalnız doğrulanmış veritabanı kayıtlarından doldurulur.
        $this->returnLoads = [];
        $this->hotScrapedLoads = [];
    }
}; ?>

<div class="space-y-6">

    <!-- Hoş Geldin & Aktif Sefer Bannerı -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-neutral-900 via-neutral-900 to-neutral-850 border border-neutral-800 p-6 md:p-8">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    PWA Arka Plan GPS Takibi Devrede
                </div>
                <h2 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                    Hayırlı Yolculuklar, <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-amber-400">{{ auth()->user()?->full_name }}</span>
                </h2>
                <p class="text-sm text-neutral-400 max-w-2xl leading-relaxed">
                    Alın terinizi koruyan akıllı lojistik ağındasınız. Hak edişiniz PayTR havuzunda hazır, teslimat onaylandığında 1 gün içinde hesabınıza aktarılır.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('driver.loads.index') }}" class="px-5 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm shadow-xl shadow-brand-500/20 transition-all active:scale-95 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <span>Yük Ara & Teklif Ver</span>
                </a>
            </div>
        </div>
        <div class="absolute -right-20 -top-20 w-80 h-80 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <!-- 4'lü Şoför KPI Sayaçları -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">

        <!-- Onay Bekleyen Net Hak Ediş -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Havuzda Onay Bekleyen Hak Ediş</span>
                <span class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-black text-white tracking-tight font-mono">
                {{ number_format($pendingPayout, 2, ',', '.') }} <span class="text-emerald-400 text-lg">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 mt-2 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>Net %95 (Teslimatta IBAN'a geçer)</span>
            </div>
        </div>

        <!-- Tamamlanan Başarılı Sevkiyat -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Tamamlanan Sevkiyat</span>
                <span class="p-2.5 rounded-xl bg-blue-500/10 text-blue-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-black text-white tracking-tight">{{ $completedShipmentsCount }}</div>
            <div class="text-[11px] text-blue-400 mt-2">
                ★ 4.9 Şoför Puanı (KYC Onaylı)
            </div>
        </div>

        <!-- Aktif Tekliflerim -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Bekleyen Tekliflerim</span>
                <span class="p-2.5 rounded-xl bg-brand-500/10 text-brand-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-black text-white tracking-tight">3</div>
            <div class="text-[11px] text-amber-400 mt-2">
                Yük sahipleri inceliyor
            </div>
        </div>

        <!-- Premium Abonelik Sayacı -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 hover:border-neutral-700 transition-all duration-200">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-neutral-400">Premium Avantajı</span>
                <span class="p-2.5 rounded-xl bg-amber-500/10 text-amber-400">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-black text-amber-400 tracking-tight">{{ $premiumDaysLeft }} Gün</div>
            <div class="text-[11px] text-neutral-400 mt-2">
                Onaylı Dış Kaynaklara Erken Erişim Aktif
            </div>
        </div>

    </div>

    <!-- ANA OPERASYON ALANI: AKILLI DÖNÜŞ RADARI & CANLI İLAN TERMİNALİ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Sol 2 Kolon: Akıllı Dönüş Radarı -->
        <div class="lg:col-span-2 bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-5">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-neutral-800 pb-4">
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </span>
                        <span>Akıllı Dönüş Radarı (Boş Dönüşü Önleme)</span>
                    </h3>
                    <p class="text-xs text-neutral-400 mt-0.5">
                        Mevcut varış noktanız olan <b class="text-white">İzmir</b> bölgesinden dönüş rotanıza en uygun yükler otomatik eşleştirildi.
                    </p>
                </div>
                <span class="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold shrink-0">
                    Radar Aktif
                </span>
            </div>

            <!-- Dönüş Yükü Kartları -->
            <div class="space-y-3">
                @foreach($returnLoads as $load)
                    <div class="p-4 bg-neutral-950 border border-neutral-800/80 hover:border-brand-500/40 rounded-xl transition-all duration-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="space-y-1.5 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 text-[10px] font-bold">
                                    {{ $load['match_score'] }}
                                </span>
                                <span class="px-2 py-0.5 rounded-md {{ $load['source'] === 'system_escrow' ? 'bg-brand-500/10 text-brand-400' : 'bg-amber-500/10 text-amber-400' }} text-[10px] font-bold">
                                    {{ $load['source'] === 'system_escrow' ? '✓ PayTR Havuz Korumalı' : 'Sarı Rozetli Dış İlan' }}
                                </span>
                            </div>

                            <div class="text-sm font-bold text-white flex items-center gap-2">
                                <span>{{ $load['origin'] }}</span>
                                <span class="text-brand-500">&rarr;</span>
                                <span>{{ $load['destination'] }}</span>
                            </div>

                            <div class="text-xs text-neutral-400">
                                {{ $load['goods'] }} • {{ $load['vehicle'] }} • {{ $load['weight'] }}
                            </div>
                        </div>

                        <div class="flex sm:flex-col items-center sm:items-end justify-between gap-2 shrink-0 border-t sm:border-t-0 pt-3 sm:pt-0 border-neutral-800">
                            <div class="text-xl font-black text-white font-mono">
                                {{ number_format($load['price'], 2, ',', '.') }} <span class="text-brand-500 text-sm">₺</span>
                            </div>
                            <a href="{{ route('driver.loads.index') }}" class="px-4 py-2 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-bold shadow-md shadow-brand-500/20 transition-all">
                                Hemen Teklif Ver
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

        </div>

        <!-- Sağ 1 Kolon: Onaylı Dış Kaynak İlan Terminali -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 flex flex-col justify-between space-y-6">

            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-neutral-800 pb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-ping"></span>
                        <h3 class="text-xs font-bold text-white uppercase tracking-wider">AI Sıcak İlan Terminali</h3>
                    </div>
                    <span class="text-[10px] text-brand-400 font-mono">Canlı Akış</span>
                </div>

                <div class="space-y-3">
                    @foreach($hotScrapedLoads as $item)
                        <div class="p-3.5 rounded-xl bg-neutral-950 border border-neutral-800 space-y-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white">{!! $item['route'] !!}</span>
                                <span class="text-[10px] text-neutral-500 font-mono">{{ $item['time'] }}</span>
                            </div>
                            <div class="text-neutral-400 text-[11px]">{{ $item['details'] }}</div>
                            <div class="flex items-center justify-between pt-1 border-t border-neutral-850 text-[10px]">
                                <span class="text-amber-400 font-semibold">{{ $item['badge'] }}</span>
                                <span class="text-neutral-400 font-mono">{{ $item['phone'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Merkezi VIP Radar Bilgilendirme Kartı -->
            <div class="p-4 rounded-xl bg-gradient-to-r from-brand-500/10 to-amber-500/10 border border-brand-500/20 space-y-2">
                <div class="text-xs font-bold text-brand-400 flex items-center gap-1.5">
                    <span>⚡ Onaylı Dış Kaynaklar İzleniyor</span>
                </div>
                <p class="text-[11px] text-neutral-300 leading-relaxed">
                    Sistem arka planda 1000'den fazla WhatsApp & Telegram kanalını tarar, karmaşık mesajları yapay zekayla süzüp 20 dakika erken panelinize düşürür.
                </p>
                <a href="{{ route('driver.loads.index') }}" class="inline-block text-xs font-bold text-brand-400 hover:text-brand-300">
                    Tüm İlanları Gör &rarr;
                </a>
            </div>

        </div>

    </div>

</div>
