<?php

use Livewire\Volt\Component;
use App\Models\CmsContent;
use App\Models\Faq;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;

new class extends Component {
    public string $activeSlider = 'owner';

    // Dinamik Metin Verileri
    public string $ownerTitle = '';
    public string $ownerDesc = '';
    public string $driverTitle = '';
    public string $driverDesc = '';
    public string $hakkimizda = '';

    // Canlı İstatistik Sayaçları
    public int $vehicleCount = 0;
    public int $systemLoadsCount = 0;
    public int $webLoadsCount = 0;
    public int $completedCount = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->ownerTitle = CmsContent::getVal('slider_owner_title', 'Ödemeleriniz NavlunIQ ile Güvende!');
        $this->ownerDesc = CmsContent::getVal('slider_owner_desc', 'Gerçek Zamanlı Eşleşme ve Kontrollü Ödeme Süreci. İlanlarınıza gelen şoför tekliflerini anlık olarak değerlendirip onaylayabilirsiniz. Ödemeleriniz havuz sistemi ile yükleriniz KYC doğrulamalı güvenilir şoförlerle korunmaktadır.');
        $this->driverTitle = CmsContent::getVal('slider_driver_title', 'Yüzlerce Grubu Artık Takip Etmeyin!');
        $this->driverDesc = CmsContent::getVal('slider_driver_desc', 'Tek panelden ilanlara ulaş. WhatsApp gruplarında paylaşılan karmaşık ilanlar anında panelinizde listelenir. Teslimat için yola çıktığınızda akıllı dönüş radarları dönüş yükünüzü sizin için araştırır.');
        $this->hakkimizda = CmsContent::getVal('hakkimizda_ozet', 'Biz sadece bir lojistik yazılımı kodlamadık. Biz, gece gündüz direksiyon başında ömür tüketen şoförlerimiz ile, alın terini ve tüm sermayesini o yüke emanet eden iş insanlarımızın arasına sarsılmaz bir güven köprüsü kurduk.');

        // Veritabanı Sayaçları
        $this->vehicleCount = DriverVehicle::whereHas('driverProfile', fn ($q) => $q->where('kyc_status', 'approved'))->count();
        $this->systemLoadsCount = Load::whereIn('status', ['active_seeking', 'driver_assigned', 'on_the_way'])->count();
        $this->webLoadsCount = ScrapedLoad::where('visibility', 'public')->count();
        $this->completedCount = Load::whereIn('status', [Load::STATUS_DELIVERED, Load::STATUS_COMPLETED])->count();
    }

    public function getAllFaqs()
    {
        return Faq::where('is_active', true)->orderBy('order_num')->get();
    }
}; ?>

<div class="space-y-20 md:space-y-28 pb-12 animate-fade-in">

    <style>
        /* İpeksi ve Kesintisiz Kayan Araç Şeridi */
        @keyframes marqueeTrack {
            0% { transform: translateX(0%); }
            100% { transform: translateX(-50%); }
        }
        .animate-marquee-smooth {
            display: flex;
            width: max-content;
            animation: marqueeTrack 42s linear infinite;
        }
        .marquee-container:hover .animate-marquee-smooth {
            animation-play-state: paused;
        }
        /* Modern Kenar Erimesi Maskesi */
        .edge-fade-mask {
            mask-image: linear-gradient(to right, transparent 0%, black 7%, black 93%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, black 7%, black 93%, transparent 100%);
        }
    </style>

    <!-- ========================================================= -->
    <!-- 1. BÖLÜM: DİNAMİK HERO SLIDER (SAF CAM & FERAHLATILMIŞ ALAN) -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12 pt-0">
        <div class="apple-glass rounded-3xl md:rounded-[36px] p-6 sm:p-8 md:p-10 lg:p-12 relative overflow-hidden border border-neutral-200/60 dark:border-neutral-800/60 shadow-apple-lg">

            <!-- Rol Değiştirici Segment Kontrolü -->
            <div class="flex justify-center mb-6 md:mb-8">
                <div class="p-1 bg-neutral-100 dark:bg-neutral-900 rounded-2xl inline-flex border border-neutral-200/40 shadow-apple-sm">
                    <button wire:click="$set('activeSlider', 'owner')" class="px-5 sm:px-6 py-2.5 rounded-xl text-xs font-black transition-all duration-300 {{ $activeSlider === 'owner' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm scale-100' : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white scale-95' }}">
                         Yük Sahibi
                    </button>
                    <button wire:click="$set('activeSlider', 'driver')" class="px-5 sm:px-6 py-2.5 rounded-xl text-xs font-black transition-all duration-300 {{ $activeSlider === 'driver' ? 'bg-brand-500 text-white shadow-apple-sm scale-100' : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white scale-95' }}">
                         Şoför
                    </button>
                </div>
            </div>

            <!-- Slider İçeriği -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-10 items-center">

                <!-- Sol Metin Alanı -->
                <div class="lg:col-span-7 space-y-4 md:space-y-5 text-center lg:text-left">
                    <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400 text-xs font-extrabold uppercase tracking-wider">
                        <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                        <span>{{ $activeSlider === 'owner' ? 'Kontrollü Ödeme Süreci' : 'Tek Panelden Tüm İlanlar' }}</span>
                    </div>

                    <h1 class="text-2xl sm:text-4xl lg:text-5xl font-black tracking-tight text-neutral-950 dark:text-white leading-[1.15]">
                        {{ $activeSlider === 'owner' ? $ownerTitle : $driverTitle }}
                    </h1>

                    <p class="text-xs sm:text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed max-w-xl mx-auto lg:mx-0">
                        {{ $activeSlider === 'owner' ? $ownerDesc : $driverDesc }}
                    </p>

                    <!-- Butonlar -->
                    <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 pt-2">
                        @if($activeSlider === 'owner')
                            <a href="{{ route('register.cargo-owner') }}" class="w-full sm:w-auto btn-apple-brand py-3.5 px-7 font-bold text-xs shadow-apple-md">
                                Hemen İlan Ver
                            </a>
                            <a href="{{ route('how-it-works') }}" class="w-full sm:w-auto btn-apple-secondary py-3.5 px-6 font-bold text-xs">
                                Süreç Nasıl İşler?
                            </a>
                        @else
                            <a href="{{ route('register.driver') }}" class="w-full sm:w-auto btn-apple-brand py-3.5 px-7 font-bold text-xs shadow-apple-md">
                                Belgelerini Yükle
                            </a>
                            <a href="{{ route('subscription') }}" class="w-full sm:w-auto btn-apple-secondary py-3.5 px-6 font-bold text-xs">
                                Premium Avantajları
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Sağ Görsel Kartı -->
                <div class="lg:col-span-5 flex justify-center w-full">
                    <div class="w-full max-w-sm bg-gradient-to-tr from-brand-500/20 via-brand-500/5 to-transparent rounded-3xl p-5 sm:p-6 flex flex-col justify-between border border-brand-500/20 shadow-apple-md space-y-4">
                        <div class="flex justify-between items-center border-b border-neutral-200/50 dark:border-neutral-800/60 pb-3">
                            <span class="text-xs font-black tracking-wider uppercase text-brand-600 dark:text-brand-400">Örnek sevkiyat akışı</span>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 font-bold text-[10px]">Örnek</span>
                        </div>

                        <div class="space-y-2.5">
                            <div class="p-3.5 bg-white/90 dark:bg-neutral-900/90 backdrop-blur-apple rounded-2xl border border-neutral-200/50 dark:border-neutral-800 space-y-1 shadow-apple-sm">
                                <span class="text-[10px] text-neutral-400 font-semibold block">Güzergah & Escrow Durumu</span>
                                <div class="text-xs sm:text-sm font-black text-neutral-900 dark:text-white">Ankara Ostim → İzmir Aliağa</div>
                                <div class="text-xs text-brand-500 font-bold tabular-nums">18.500,00 ₺ • Güvenli havuzda bekliyor</div>
                            </div>

                            <div class="p-3 bg-white/60 dark:bg-neutral-950/60 rounded-xl border border-neutral-200/40 dark:border-neutral-800/40 flex items-center justify-between text-[11px]">
                                <span class="text-neutral-500">Sürücü Durumu:</span>
                                <span class="text-emerald-500 font-bold">Belgeleri doğrulanmış</span>
                            </div>
                        </div>

                        <div class="text-[10px] text-neutral-400 font-medium text-center pt-1 border-t border-neutral-200/40 dark:border-neutral-800/40">
                            Ödeme, teslimat onayına kadar havuzda tutulur
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 2. BÖLÜM: ANLIK CANLI VERİ AKIŞI (STAT METRICS) -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
            <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
                <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Aktif Kayıtlı Araç</span>
                <div class="text-3xl sm:text-4xl font-black text-neutral-950 dark:text-white">{{ number_format($vehicleCount) }}</div>
                <span class="text-[10px] text-emerald-500 font-bold">Doğrulanmış Filo</span>
            </div>
            <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
                <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Sistem İlanları</span>
                <div class="text-3xl sm:text-4xl font-black text-brand-500">{{ number_format($systemLoadsCount) }}</div>
                <span class="text-[10px] text-neutral-400 font-medium">Platform ilanları</span>
            </div>
            <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
                <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Anlık Web İlanları</span>
                <div class="text-3xl sm:text-4xl font-black text-neutral-950 dark:text-white">{{ number_format($webLoadsCount) }}</div>
                <span class="text-[10px] text-brand-500 font-bold">Dış kaynak ilanları</span>
            </div>
            <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
                <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Başarılı Sevkiyat</span>
                <div class="text-3xl sm:text-4xl font-black text-emerald-500">{{ number_format($completedCount) }}</div>
                <span class="text-[10px] text-emerald-600 font-bold">Onaylı teslimat</span>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 3. BÖLÜM: NAVLUN NEDİR? VE HAKKIMIZDA ÖZET -->
    <!-- ========================================================= -->
    <section id="hakkimizda" class="max-w-7xl mx-auto px-6 md:px-12 scroll-mt-24">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="apple-glass rounded-3xl p-8 space-y-4 shadow-apple-sm border-l-4 border-l-brand-500">
                <span class="text-xs font-black text-brand-500 uppercase tracking-wider">BİLGİ REHBERİ</span>
                <h3 class="text-xl font-black text-neutral-950 dark:text-white">Navlun Nedir?</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Deniz, kara, hava veya demir yoluyla taşınan yükler için taşıyıcı firmaya ödenen taşıma ücretidir.
                </p>
            </div>

            <div class="lg:col-span-2 apple-glass rounded-3xl p-8 space-y-4 shadow-apple-sm">
                <span class="text-xs font-black text-neutral-400 tracking-wider">Peki biz kimiz?</span>
                <h3 class="text-xl font-black text-neutral-950 dark:text-white">NavlunIQ Nedir?</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    {{ $hakkimizda }}
                </p>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 4. BÖLÜM: 3 ADIMDA NAVLUNIQ -->
    <!-- ========================================================= -->
    <section id="nasil-calisir" class="max-w-7xl mx-auto px-6 md:px-12 scroll-mt-24 space-y-12">
        <div class="text-center space-y-3 max-w-2xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">KOLAY VE ŞEFFAF SÜREÇ</span>
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-neutral-950 dark:text-white">3 Adımda NavlunIQ</h2>
            <p class="text-xs sm:text-sm text-neutral-400">Yük bulma ve sevkiyat yönetimi süreçleri tamamen dijitalleşti.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="apple-glass rounded-3xl p-8 space-y-4 relative overflow-hidden shadow-apple-sm">
                <div class="w-12 h-12 rounded-2xl bg-brand-500/10 text-brand-500 font-black text-lg flex items-center justify-center">1</div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Akıllı Eşleşme & Teklif</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Akıllı lojistik ağımız yük sahiplerinin ilanlarını tarar, uygun onaylı araçlarla eşleştirir. Şoförler teklif verir ve fiyatta anlaşırlar.
                </p>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 relative overflow-hidden shadow-apple-sm border-t-2 border-brand-500">
                <div class="w-12 h-12 rounded-2xl bg-brand-500 text-white font-black text-lg flex items-center justify-center">2</div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Güvenli Havuz & Canlı Takip</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Yük sahibi navlun bedelini PayTR ile güvenli havuza yatırır, şoför yola çıkar. Yük sahibi sevkiyatı canlı konumla takip eder.
                </p>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 relative overflow-hidden shadow-apple-sm">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-500 font-black text-lg flex items-center justify-center">3</div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">POD Onay & Hak Ediş</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Şoför teslimat kanıtını yükler, yük sahibi onaylar. Onayın ardından şoförün hak edişi finans ekibimizce banka hesabına aktarılır.
                </p>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 5. BÖLÜM: YÜK SAHİBİ VE ŞOFÖR OLARAK KATILIN -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div id="yuk-kayit" class="apple-glass rounded-[32px] p-8 md:p-10 space-y-6 border-l-4 border-l-brand-500 flex flex-col justify-between shadow-apple-md">
                <div class="space-y-4">
                    <span class="text-xs font-black text-brand-500 uppercase tracking-wider">YÜK SAHİPLERİ İÇİN</span>
                    <h3 class="text-2xl font-black text-neutral-950 dark:text-white">Yük Sahibi Olarak Katılın</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        En uygun maliyetlerle, güvenli nakliye hizmeti. Hemen ilanınızı verin, belgeleri doğrulanmış profesyonel şoförlerden teklif toplayın.
                    </p>
                </div>
                <div class="pt-4">
                    <a href="{{ route('register.cargo-owner') }}" class="w-full btn-apple-brand py-3.5 text-center text-xs font-bold block shadow-apple-sm">
                        Yük Sahibi Olarak Kayıt Ol →
                    </a>
                </div>
            </div>

            <div id="sofor-kayit" class="apple-glass rounded-[32px] p-8 md:p-10 space-y-6 border-l-4 border-l-emerald-500 flex flex-col justify-between shadow-apple-md">
                <div class="space-y-4">
                    <span class="text-xs font-black text-emerald-600 uppercase tracking-wider">ŞOFÖRLER İÇİN</span>
                    <h3 class="text-2xl font-black text-neutral-950 dark:text-white">Şoför Olarak Katılın</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        Boş kilometre yapmaya son verin. KYC belgelerinizi yükleyip onaylatın. Size en uygun ilanları panelinizden görüntüleyip teklifler verin.
                    </p>
                </div>
                <div class="pt-4">
                    <a href="{{ route('register.driver') }}" class="w-full btn-apple-secondary py-3.5 text-center text-xs font-bold block shadow-apple-sm transition-colors duration-200 bg-neutral-900 text-white hover:bg-neutral-200 hover:text-neutral-900 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-white">
                        Şoför Olarak Kayıt Ol →
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 6. BÖLÜM: 3 TEMEL HİZMETİMİZ -->
    <!-- ========================================================= -->
    <section id="hizmetlerimiz" class="max-w-7xl mx-auto px-6 md:px-12 space-y-12 scroll-mt-24">
        <div class="text-center space-y-3 max-w-2xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">ÇÖZÜMLERİMİZ</span>
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-neutral-950 dark:text-white">Hizmetlerimiz</h2>
            <p class="text-xs sm:text-sm text-neutral-400">Şehir içi, şehirler arası taşımacılık ve yapay zeka ilan aboneliği.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="apple-glass rounded-3xl p-8 space-y-4 shadow-apple-sm flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-2xl bg-brand-500/10 text-brand-500 font-bold flex items-center justify-center text-xl"></div>
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white">NavlunIQ İlan Aboneliği</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        WhatsApp grupları ve web mecralarından derlenip ayrıştırılan yük ilanlarına gerçek zamanlı erişim.
                    </p>
                </div>
                <a href="{{ route('subscription') }}" class="text-xs font-bold text-brand-500 hover:underline pt-2 block">Abonelik Detayları →</a>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 shadow-apple-sm flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-500 font-bold flex items-center justify-center text-xl"></div>
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white">Şehir İçi Yük Taşımacılığı</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        Şehir içi kısa mesafeli ve acil sevkiyatlarınız için optimize edilmiş taşımacılık ağı. Hafif ticari araçlardan kamyonlara onaylı şoför atayın.
                    </p>
                </div>
                <a href="{{ route('register.cargo-owner') }}" class="text-xs font-bold text-blue-500 hover:underline pt-2 block">Hemen İlan Ver →</a>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 shadow-apple-sm flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-500 font-bold flex items-center justify-center text-xl"></div>
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white">Şehirler Arası Yük Taşımacılığı</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        Türkiye geneli tüm şehirler arasında güvenli taşımacılık. Şoförler tercih ettikleri rotalardaki ilanlara tek panelden ulaşır.
                    </p>
                </div>
                <a href="{{ route('register.cargo-owner') }}" class="text-xs font-bold text-emerald-500 hover:underline pt-2 block">Hemen İlan Ver →</a>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 7. BÖLÜM: ABONELİK SİSTEMİ -->
    <!-- ========================================================= -->
    <section id="abonelik" class="max-w-7xl mx-auto px-6 md:px-12 space-y-12 scroll-mt-24">
        <div class="text-center space-y-3 max-w-2xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">SÜRÜCÜ ABONELİK PAKETLERİ</span>
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-neutral-950 dark:text-white">Yük Bulma Hızınızı Zirveye Taşıyın</h2>
            <p class="text-xs sm:text-sm text-neutral-400">Dış kaynaklardan derlenen tüm ilanlara tek panelden erişin.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-sm flex flex-col justify-between">
                <div class="space-y-4">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">BAŞLANGIÇ</span>
                        <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Ücretsiz Şoför Hesabı</h3>
                        <div class="text-3xl font-black text-neutral-900 dark:text-white pt-2">0 &#8378; <span class="text-xs text-neutral-400 font-normal">/ Süresiz</span></div>
                    </div>
                    <ul class="space-y-2.5 text-xs text-neutral-500 dark:text-neutral-400 pt-4 border-t border-neutral-100 dark:border-neutral-800">
                        <li class="flex items-center space-x-2"><span></span><span>Web siteleri ve gruplardan derlenen ilanlar</span></li>
                        <li class="flex items-center space-x-2"><span></span><span>Onaylı dış kaynak ilanlarına 20 dakika gecikmeli erişim</span></li>
                        <li class="flex items-center space-x-2"><span></span><span>Platform ilanlarına ücretsiz teklif hakkı</span></li>
                    </ul>
                </div>
                <a href="{{ route('register.driver') }}" class="w-full btn-apple-secondary py-3 text-center text-xs font-bold block">
                    Ücretsiz Kaydol
                </a>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-lg border-2 border-brand-500 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-4 right-4 bg-brand-500 text-white text-[9px] font-black px-2.5 py-1 rounded-full uppercase tracking-wider">
                    ÖNERİLEN
                </div>
                <div class="space-y-4">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-brand-500 uppercase tracking-wider">PROFESYONEL</span>
                        <h3 class="text-2xl font-black text-neutral-900 dark:text-white">NavlunIQ Premium Sürücü</h3>
                        <div class="text-3xl font-black text-brand-500 pt-2">{{ number_format(\App\Support\Settings::float('premium_monthly_price'), 0, ',', '.') }} &#8378; <span class="text-xs text-neutral-400 font-normal">/ Ay (KDV Dahil)</span></div>
                    </div>
                    <ul class="space-y-2.5 text-xs text-neutral-600 dark:text-neutral-300 pt-4 border-t border-neutral-100 dark:border-neutral-800 font-semibold">
                        <li class="flex items-center space-x-2"><span class="text-emerald-500"></span><span>Tüm web ve platform ilanlarını ANINDA görün</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500"></span><span>Daha düşük komisyon oranı</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500"></span><span>Onaylı dış kaynak ilanlarına 20 dakika erken erişim</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500"></span><span>Sürücü kontrol paneline tam erişim</span></li>
                    </ul>
                </div>
                <a href="{{ route('subscription') }}" class="w-full btn-apple-brand py-3.5 text-center text-xs font-bold block shadow-apple-sm">
                    Premium Detayları
                </a>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 8. BÖLÜM: DESTEKLENEN ARAÇ TÜRLERİ (FADE MASKELİ & SÜREKLİ AKAN) -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12 space-y-8" x-data="{
        isDragging: false,
        startX: 0,
        scrollLeft: 0,
        startDrag(e) {
            this.isDragging = true;
            this.startX = (e.pageX || e.touches[0].pageX) - $refs.sliderContainer.offsetLeft;
            this.scrollLeft = $refs.sliderContainer.scrollLeft;
        },
        stopDrag() {
            this.isDragging = false;
        },
        onDrag(e) {
            if (!this.isDragging) return;
            e.preventDefault();
            const x = (e.pageX || e.touches[0].pageX) - $refs.sliderContainer.offsetLeft;
            const walk = (x - this.startX) * 1.5;
            $refs.sliderContainer.scrollLeft = this.scrollLeft - walk;
        }
    }">
        <div class="text-center space-y-2 max-w-2xl mx-auto">
            <span class="text-xs font-black text-brand-500 uppercase tracking-widest">HER TONAJDA NAKLİYE</span>
            <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-neutral-950 dark:text-white">
                Desteklenen Araç Türleri
            </h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                Motosiklet dışındaki hafif ticariden ağır tonaja kadar tüm ticari araç sınıfları desteklenmektedir.
            </p>
        </div>

        <div class="relative w-full overflow-hidden marquee-container py-3 edge-fade-mask"
             x-ref="sliderContainer"
             @mousedown="startDrag($event)"
             @touchstart="startDrag($event)"
             @mouseup="stopDrag()"
             @mouseleave="stopDrag()"
             @touchend="stopDrag()"
             @mousemove="onDrag($event)"
             @touchmove="onDrag($event)">

            <div class="animate-marquee-smooth flex items-center gap-5 cursor-grab active:cursor-grabbing select-none">

                @php
                    $aracListesi = [
                        ['name' => 'Tır (Çekici + Dorse)', 'cat' => 'Ağır Vasıta', 'cap' => '28 Ton', 'vol' => '90 m³', 'tag' => 'Mega / Standart'],
                        ['name' => 'Kırkayak (4 Dingil)', 'cat' => 'Ağır Vasıta', 'cap' => '24 Ton', 'vol' => '65 m³', 'tag' => 'Ağır Sanayi'],
                        ['name' => '10 Teker Kamyon', 'cat' => 'Ağır Ticari', 'cap' => '16 Ton', 'vol' => '50 m³', 'tag' => 'Fabrika & Palet'],
                        ['name' => '8 Teker Kamyon', 'cat' => 'Ağır Ticari', 'cap' => '12 Ton', 'vol' => '40 m³', 'tag' => 'Şehirler Arası'],
                        ['name' => '6 Teker Kamyon', 'cat' => 'Orta Ticari', 'cap' => '8 Ton', 'vol' => '30 m³', 'tag' => 'Bölgesel Dağıtım'],
                        ['name' => 'Kamyonet', 'cat' => 'Hafif Ticari', 'cap' => '3.5 Ton', 'vol' => '20 m³', 'tag' => 'Açık / Kapalı'],
                        ['name' => 'Uzun Panelvan (Maxi)', 'cat' => 'Hafif Ticari', 'cap' => '2.5 Ton', 'vol' => '14 m³', 'tag' => 'Hacimli Koli'],
                        ['name' => 'Orta Panelvan', 'cat' => 'Hafif Ticari', 'cap' => '1.5 Ton', 'vol' => '8 m³', 'tag' => 'Şehir İçi Dağıtım'],
                        ['name' => 'Minivan', 'cat' => 'Hızlı Kurye', 'cap' => '800 Kg', 'vol' => '3.5 m³', 'tag' => 'Acil Parsiyel'],
                        ['name' => 'Otomobil / Ticari', 'cat' => 'Hızlı Kurye', 'cap' => '400 Kg', 'vol' => '1.5 m³', 'tag' => 'Hafif Paket'],
                    ];
                    $ciftListe = array_merge($aracListesi, $aracListesi);
                @endphp

                @foreach($ciftListe as $index => $arac)
                    <div class="w-60 sm:w-64 flex-shrink-0 bg-white dark:bg-neutral-900 rounded-3xl p-5 border border-neutral-200/80 dark:border-neutral-800 shadow-apple-sm hover:shadow-apple-md hover:border-brand-500/40 transition-all duration-300 flex flex-col justify-between space-y-4 group">
                        <div class="flex items-center justify-between">
                            <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-500 text-[10px] font-black uppercase tracking-wider">
                                {{ $arac['cat'] }}
                            </span>
                            <span class="text-[10px] font-mono text-neutral-400 dark:text-neutral-500">
                                {{ $arac['tag'] }}
                            </span>
                        </div>

                        <div class="h-20 rounded-2xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-100 dark:border-neutral-800/70 flex items-center justify-center p-3 group-hover:scale-105 transition-transform duration-300">
                            <svg class="w-10 h-10 text-neutral-700 dark:text-neutral-300 group-hover:text-brand-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                            </svg>
                        </div>

                        <div class="space-y-1.5">
                            <h4 class="text-xs font-bold text-neutral-900 dark:text-white truncate group-hover:text-brand-500 transition-colors">
                                {{ $arac['name'] }}
                            </h4>
                            <div class="flex items-center justify-between text-[10px] text-neutral-500 dark:text-neutral-400 pt-1.5 border-t border-neutral-100 dark:border-neutral-800/60">
                                <span>Kapasite:</span>
                                <span class="font-bold text-neutral-900 dark:text-white font-mono">{{ $arac['cap'] }} • {{ $arac['vol'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 9. BÖLÜM: GÜVENLİ ÖDEME PARTNERİMİZ -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12">
        <div class="apple-glass rounded-3xl p-8 flex flex-col md:flex-row items-center justify-between gap-6 border border-neutral-200/50 shadow-apple-sm">
            <div class="space-y-1 text-center md:text-left">
                <span class="text-xs font-black text-brand-500 uppercase tracking-wider">FİNANSAL GÜVENCE</span>
                <h3 class="text-lg font-black text-neutral-950 dark:text-white">Güvenli Ödeme Partnerimiz PayTR</h3>
                <p class="text-xs text-neutral-400">Ödemeler PayTR altyapısı üzerinden alınır ve teslimat onayına kadar havuzda tutulur.</p>
            </div>
            <div class="flex items-center space-x-3 text-xs font-mono font-bold text-emerald-600 bg-emerald-500/10 px-4 py-2 rounded-xl">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span>SSL ile şifreli bağlantı</span>
            </div>
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- 10. BÖLÜM: SIKÇA SORULAN SORULAR -->
    <!-- ========================================================= -->
    <section id="sss" class="max-w-4xl mx-auto px-6 scroll-mt-24 space-y-8" x-data="{
        activeAccordion: null,
        showAll: false
    }">
        <div class="text-center space-y-2">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">SSS</span>
            <h2 class="text-3xl font-black text-neutral-950 dark:text-white">Sıkça Sorulan Sorular</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">NavlunIQ işleyişi, ödeme havuzu ve güvenlik protokolleri hakkında merak edilenler.</p>
        </div>

        <div class="space-y-3.5">
            @php
                $allFaqs = $this->getAllFaqs();
            @endphp

            @foreach($allFaqs as $index => $faq)
                <div x-show="{{ $index }} < 5 || showAll"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="apple-glass rounded-2xl overflow-hidden border border-neutral-200/50 dark:border-neutral-800 shadow-apple-sm transition-all">

                    <button @click="activeAccordion = (activeAccordion === {{ $faq->id }} ? null : {{ $faq->id }})"
                            class="w-full p-5 text-left flex justify-between items-center text-xs sm:text-sm font-bold text-neutral-900 dark:text-white focus:outline-none">
                        <span class="flex items-center gap-3">
                            <span class="text-brand-500 font-mono text-xs font-black">#{{ $faq->order_num }}</span>
                            <span>{{ $faq->question }}</span>
                        </span>
                        <svg class="w-4 h-4 text-neutral-400 transition-transform duration-300 flex-shrink-0 ml-3"
                             :class="{ 'rotate-180 text-brand-500': activeAccordion === {{ $faq->id }} }"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="activeAccordion === {{ $faq->id }}"
                         x-collapse
                         class="px-5 pb-5 text-xs text-neutral-600 dark:text-neutral-400 leading-relaxed border-t border-neutral-100 dark:border-neutral-800/40 pt-3"
                         style="display: none;">
                        {{ $faq->answer }}
                    </div>
                </div>
            @endforeach
        </div>

        @if(count($allFaqs) > 5)
            <div class="text-center pt-2">
                <button type="button"
                        @click="showAll = !showAll"
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-neutral-100 dark:bg-neutral-900 hover:bg-neutral-200 dark:hover:bg-neutral-800 border border-neutral-200 dark:border-neutral-800 text-xs font-bold text-neutral-800 dark:text-neutral-200 transition-all duration-300 shadow-apple-sm group">
                    <span x-text="showAll ? 'Daha Az Soru Göster ↑' : 'Tüm Sıkça Sorulan Soruları Gör ↓'"></span>
                </button>
            </div>
        @endif
    </section>

</div>
