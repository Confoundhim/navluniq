<?php

use Livewire\Volt\Component;
use App\Models\CmsContent;
use App\Models\Faq;
use App\Services\LoadStatsService;

new class extends Component {
    /** Karşılama şeridi: bugün derlenen dış kaynak ilanı (LoadStatsService önbelleği, 1 dk). */
    public int $todayLoads = 0;

    // Dinamik Metin Verileri
    public string $ownerTitle = '';
    public string $ownerDesc = '';
    public string $driverTitle = '';
    public string $driverDesc = '';
    public string $hakkimizda = '';

    public function mount(): void
    {
        $this->loadData();
        $this->todayLoads = (int) (app(LoadStatsService::class)->summary()['external_today'] ?? 0);
    }

    public function loadData(): void
    {
        $this->ownerTitle = CmsContent::getVal('slider_owner_title', 'Ödemeleriniz NavlunIQ ile Güvende!');
        $this->ownerDesc = CmsContent::getVal('slider_owner_desc', 'Gerçek Zamanlı Eşleşme ve Kontrollü Ödeme Süreci. İlanlarınıza gelen şoför tekliflerini anlık olarak değerlendirip onaylayabilirsiniz. Ödemeleriniz teslimat onaylı güvenli ödeme akışıyla, yükleriniz belgeleri doğrulanmış güvenilir şoförlerle korunur.');
        $this->driverTitle = CmsContent::getVal('slider_driver_title', 'Yüzlerce Grubu Artık Takip Etmeyin!');
        $this->driverDesc = CmsContent::getVal('slider_driver_desc', 'Tek panelden ilanlara ulaş. Gruplarda ve webde paylaşılan karmaşık ilanlar anında panelinizde listelenir. Teslimat için yola çıktığınızda akıllı dönüş radarları dönüş yükünüzü sizin için araştırır.');
        $this->hakkimizda = CmsContent::getVal('hakkimizda_ozet', 'NavlunIQ, yük sahipleri ile belgeleri doğrulanmış şoförleri tek panelde buluşturan dijital lojistik platformudur. Platform ilanlarına teklif verilir, navlun ödemesi lisanslı ödeme kuruluşu üzerinden teslimat onayına bağlı olarak yapılır ve sevkiyat canlı konumla izlenir. İzinli gruplardan ve web mecralarından derlenen ilanlar yapay zeka ile ayrıştırılıp standart ilan kartına dönüştürülür; şoförler araç tipi, il ve mesafeye göre kaydettikleri filtrelerle kendilerine uygun yükü anında görür.');
    }

    public function getAllFaqs()
    {
        return Faq::where('is_active', true)->orderBy('order_num')->get();
    }
}; ?>

<div class="space-y-20 md:space-y-28 pb-12 animate-fade-in">
    @php
        $lead = app(\App\Services\LoadReleaseService::class)->delayMinutes();
        $leadText = $lead > 0 ? $lead.' dakika' : 'aynı anda';
    @endphp

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
    <!-- 1. BÖLÜM: KARŞILAMA SAHNESİ (rol anahtarı + yaşayan sevkiyat sahnesi) -->
    <!-- Alpine tarafında çalışır: rol değişimi sunucuya gitmez, sahne yerinde değişir. İlk dokunuşa kadar 9 sn'de bir rol değişir, -->
    <!-- kullanıcı dokununca ya da ekran dışına çıkınca durur; "hareketi azalt" tercihinde kendiliğinden döner ve sahne sabit kalır.     -->
    <!-- ========================================================= -->
    <section class="max-w-7xl mx-auto px-6 md:px-12 pt-0">
        <div class="hero-shell apple-glass rounded-3xl md:rounded-[36px] relative overflow-hidden border border-neutral-200/60 dark:border-neutral-800/60 shadow-apple-lg"
             x-data="{
                role: {{ request()->query('rol') === 'sofor' ? "'driver'" : "'owner'" }}, auto: true, pause: false, visible: true, cycle: 0, timer: null, period: 9000,
                reduce: !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches),
                init() {
                    if (this.reduce) { this.auto = false; return; }
                    if ('IntersectionObserver' in window) {
                        new IntersectionObserver(es => { this.visible = es[0].isIntersecting; }, { threshold: 0.35 }).observe(this.$el);
                    }
                    this.timer = setInterval(() => { if (this.auto && this.visible && !this.pause && !document.hidden) this.flip(); }, this.period);
                },
                flip() { this.role = this.role === 'owner' ? 'driver' : 'owner'; this.cycle++; },
                select(r) { this.stopAuto(); if (this.role !== r) { this.role = r; this.cycle++; } },
                stopAuto() { this.auto = false; if (this.timer) { clearInterval(this.timer); this.timer = null; } },
                restart(el) { el.style.animation = 'none'; void el.offsetWidth; el.style.animation = ''; }
             }"
             @pointerdown="stopAuto()" @keydown="stopAuto()" @mouseenter="pause = true" @mouseleave="pause = false"
             :class="{ 'hero-static': reduce }">

            <!-- Ortam: yumuşak ışık lekeleri ve nokta ızgarası -->
            <div class="hero-aurora hero-aurora-a" aria-hidden="true"></div>
            <div class="hero-aurora hero-aurora-b" aria-hidden="true"></div>
            <div class="hero-dots" aria-hidden="true"></div>

            <div class="relative p-6 sm:p-8 md:p-10 lg:p-12">

                <!-- Rol anahtarı -->
                <div class="flex justify-center mb-6 md:mb-8">
                    <div class="p-1 bg-neutral-100/90 dark:bg-neutral-900/90 rounded-2xl inline-flex border border-neutral-200/40 dark:border-neutral-800/60 shadow-apple-sm" role="tablist" aria-label="Kimin için">
                        <button type="button" role="tab" :aria-selected="role === 'owner'" @click="select('owner')"
                                class="relative overflow-hidden px-5 sm:px-6 py-2.5 rounded-xl text-xs font-black transition-all duration-300"
                                :class="role === 'owner' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white'">
                            Yük Sahibi
                            <span class="hero-progress bg-brand-500/70" x-show="auto && role === 'owner'" x-effect="cycle; restart($el)" :style="'animation-duration:' + period + 'ms'" aria-hidden="true"></span>
                        </button>
                        <button type="button" role="tab" :aria-selected="role === 'driver'" @click="select('driver')"
                                class="relative overflow-hidden px-5 sm:px-6 py-2.5 rounded-xl text-xs font-black transition-all duration-300"
                                :class="role === 'driver' ? 'bg-brand-500 text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-900 dark:hover:text-white'">
                            Şoför
                            <span class="hero-progress bg-white/80" x-show="auto && role === 'driver'" x-effect="cycle; restart($el)" :style="'animation-duration:' + period + 'ms'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-center">

                    <!-- Sol: Metin -->
                    <div class="lg:col-span-6 text-center lg:text-left min-w-0">
                            <div class="space-y-4 md:space-y-5" x-show="role === 'owner'" x-transition:enter.opacity.duration.300ms>
                                <div class="hero-reveal inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400 text-xs font-extrabold uppercase tracking-wider" style="--d: 0ms">
                                    <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                                    <span>Teslimat onaylı güvenli ödeme</span>
                                </div>
                                <h1 class="hero-reveal text-3xl sm:text-4xl lg:text-[3.25rem] font-black tracking-tight text-neutral-950 dark:text-white leading-[1.15] sm:leading-[1.1] lg:leading-[1.06]" style="--d: 80ms">
                                    {{ $ownerTitle }}
                                </h1>
                                <p class="hero-reveal text-xs sm:text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed max-w-xl mx-auto lg:mx-0" style="--d: 160ms">
                                    {{ $ownerDesc }}
                                </p>
                                <div class="hero-reveal flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 pt-1" style="--d: 240ms">
                                    <a href="{{ route('register.cargo-owner') }}" class="w-full sm:w-auto btn-apple-brand py-3.5 px-7 font-bold text-xs shadow-apple-md hero-cta">
                                        <span>Hemen İlan Ver</span>
                                        <svg class="w-4 h-4 hero-cta-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                                    </a>
                                    <a href="{{ route('how-it-works') }}" class="w-full sm:w-auto btn-apple-secondary py-3.5 px-6 font-bold text-xs">Süreç Nasıl İşler?</a>
                                </div>
                                <div class="hero-reveal flex flex-wrap items-center justify-center lg:justify-start gap-x-4 gap-y-2 text-[11px] text-neutral-500 dark:text-neutral-400 pt-1" style="--d: 320ms">
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>İlan vermek ücretsiz</span>
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Teklifleri karşılaştır, sen seç</span>
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Para teslimata kadar güvende</span>
                                </div>
                            </div>

                        <template x-if="role === 'driver'">
                            <div class="space-y-4 md:space-y-5">
                                <div class="hero-reveal inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-xs font-extrabold uppercase tracking-wider" style="--d: 0ms">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    <span>Tüm ilanlar tek panelde</span>
                                </div>
                                <h1 class="hero-reveal text-3xl sm:text-4xl lg:text-[3.25rem] font-black tracking-tight text-neutral-950 dark:text-white leading-[1.15] sm:leading-[1.1] lg:leading-[1.06]" style="--d: 80ms">
                                    {{ $driverTitle }}
                                </h1>
                                <p class="hero-reveal text-xs sm:text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed max-w-xl mx-auto lg:mx-0" style="--d: 160ms">
                                    {{ $driverDesc }}
                                </p>
                                <div class="hero-reveal flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 pt-1" style="--d: 240ms">
                                    <a href="{{ route('register.driver') }}" class="w-full sm:w-auto btn-apple-brand py-3.5 px-7 font-bold text-xs shadow-apple-md hero-cta">
                                        <span>Ücretsiz Şoför Hesabı Aç</span>
                                        <svg class="w-4 h-4 hero-cta-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                                    </a>
                                    <a href="{{ route('subscription') }}" class="w-full sm:w-auto btn-apple-secondary py-3.5 px-6 font-bold text-xs">Premium Avantajları</a>
                                </div>
                                <div class="hero-reveal flex flex-wrap items-center justify-center lg:justify-start gap-x-4 gap-y-2 text-[11px] text-neutral-500 dark:text-neutral-400 pt-1" style="--d: 320ms">
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Teklif vermek her zaman ücretsiz</span>
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Aracına ve rotana göre filtre</span>
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Dönüş yükü kendiliğinden bulunur</span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Sağ: Yaşayan sahne -->
                    <div class="lg:col-span-6 w-full min-w-0">

                        <!-- Yük sahibi sahnesi: rota üzerinde ilerleyen araç, sırayla yanan dört adım, güvende duran ödeme -->
                            <div class="hero-scene hero-reveal relative w-full max-w-xl mx-auto rounded-3xl border border-neutral-200/70 dark:border-neutral-800 bg-white/70 dark:bg-neutral-900/70 backdrop-blur-apple shadow-apple-md overflow-hidden" style="--d: 120ms" x-show="role === 'owner'" x-transition:enter.opacity.duration.300ms>
                                <div class="flex items-center justify-between px-4 sm:px-5 pt-4">
                                    <span class="text-[10px] sm:text-[11px] font-black tracking-wider uppercase text-brand-600 dark:text-brand-400">Örnek sevkiyat akışı</span>
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold text-[10px]"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>Canlı konum</span>
                                </div>

                                <svg viewBox="0 0 560 300" class="w-full h-auto block text-neutral-900 dark:text-white" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true"
                                     x-init="if (reduce) { $el.querySelectorAll('animateMotion').forEach(a => a.remove()); $el.querySelector('.hero-truck').setAttribute('transform', 'translate(280 196)'); }">
                                    <defs>
                                        <path id="heroRoute" d="M 64 236 C 150 110, 290 300, 496 92" fill="none"/>
                                        <linearGradient id="heroRouteGrad" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0" stop-color="#f97316" stop-opacity="0.15"/>
                                            <stop offset="1" stop-color="#f97316" stop-opacity="0.9"/>
                                        </linearGradient>
                                    </defs>

                                    <!-- Rota: sabit zemin + akan kesikli çizgi -->
                                    <use href="#heroRoute" xlink:href="#heroRoute" class="hero-route-base" stroke-width="6" stroke-linecap="round"/>
                                    <use href="#heroRoute" xlink:href="#heroRoute" class="hero-route-flow" stroke="url(#heroRouteGrad)" stroke-width="3" stroke-linecap="round"/>

                                    <!-- Kalkış ve varış -->
                                    <g class="hero-pin">
                                        <circle cx="64" cy="236" r="16" class="fill-brand-500/15"/>
                                        <circle cx="64" cy="236" r="6" class="fill-brand-500"/>
                                        <text x="64" y="272" text-anchor="middle" class="hero-svg-label">Ankara</text>
                                    </g>
                                    <g class="hero-pin">
                                        <circle cx="496" cy="92" r="16" class="fill-emerald-500/15"/>
                                        <circle cx="496" cy="92" r="6" class="fill-emerald-500"/>
                                        <text x="496" y="66" text-anchor="middle" class="hero-svg-label">İzmir</text>
                                    </g>

                                    <!-- Adım çipleri: araç yaklaştıkça sırayla yanar (9 sn'lik döngü) -->
                                    <g transform="translate(112 250)"><g class="hero-chip" style="--i: 0">
                                            <rect width="172" height="32" rx="16" class="hero-chip-bg"/>
                                            <circle cx="17" cy="16" r="5" class="fill-brand-500"/>
                                            <text x="32" y="21" class="hero-svg-chip">Teklif kabul edildi</text>
                                    </g></g>
                                    <g transform="translate(196 214)"><g class="hero-chip" style="--i: 1">
                                            <rect width="138" height="32" rx="16" class="hero-chip-bg"/>
                                            <circle cx="17" cy="16" r="5" class="fill-brand-500"/>
                                            <text x="32" y="21" class="hero-svg-chip">Ödeme güvende</text>
                                    </g></g>
                                    <g transform="translate(262 110)"><g class="hero-chip" style="--i: 2">
                                            <rect width="176" height="32" rx="16" class="hero-chip-bg"/>
                                            <circle cx="17" cy="16" r="5" class="fill-brand-500"/>
                                            <text x="32" y="21" class="hero-svg-chip">Yolda · canlı konum</text>
                                    </g></g>
                                    <g transform="translate(378 200)"><g class="hero-chip" style="--i: 3">
                                            <rect width="172" height="32" rx="16" class="hero-chip-bg"/>
                                            <circle cx="17" cy="16" r="5" class="fill-emerald-500"/>
                                            <text x="32" y="21" class="hero-svg-chip">Ödeme şoföre geçti</text>
                                    </g></g>

                                    <!-- Araç: rota boyunca ilerler -->
                                    <g class="hero-truck">
                                        <g transform="translate(-22 -12)">
                                            <rect x="0" y="2" width="28" height="18" rx="3" class="fill-neutral-900 dark:fill-white"/>
                                            <rect x="28" y="7" width="14" height="13" rx="3" class="fill-brand-500"/>
                                            <rect x="31" y="9" width="7" height="5" rx="1" class="fill-white/80"/>
                                            <circle cx="8" cy="22" r="3.5" class="fill-neutral-700 dark:fill-neutral-300"/>
                                            <circle cx="20" cy="22" r="3.5" class="fill-neutral-700 dark:fill-neutral-300"/>
                                            <circle cx="36" cy="22" r="3.5" class="fill-neutral-700 dark:fill-neutral-300"/>
                                        </g>
                                        <animateMotion dur="9s" repeatCount="indefinite" rotate="auto" calcMode="spline" keyPoints="0;1" keyTimes="0;1" keySplines="0.4 0 0.6 1">
                                            <mpath href="#heroRoute" xlink:href="#heroRoute"/>
                                        </animateMotion>
                                    </g>
                                </svg>

                                <!-- Kayan ödeme kartı -->
                                <div class="hero-float absolute left-3 sm:left-5 top-11 sm:top-12 flex items-center gap-2 pl-2 pr-3 py-1.5 rounded-2xl bg-white/90 dark:bg-neutral-950/90 border border-neutral-200/70 dark:border-neutral-800 shadow-apple-md backdrop-blur-apple">
                                    <span class="w-7 h-7 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 11V8a5 5 0 0110 0v3M6 11h12v9H6z"/></svg>
                                    </span>
                                    <span class="leading-tight">
                                        <span class="block text-sm sm:text-base font-black text-neutral-900 dark:text-white tabular-nums">18.500 ₺</span>
                                        <span class="block text-[9px] sm:text-[10px] text-emerald-600 dark:text-emerald-400 font-bold">Teslimata kadar ödeme kuruluşunda</span>
                                    </span>
                                </div>

                                <div class="flex items-center justify-between gap-3 px-4 sm:px-5 pb-4 text-[10px] sm:text-[11px]">
                                    <span class="text-neutral-500 dark:text-neutral-400 truncate">Şoför: <span class="text-emerald-600 dark:text-emerald-400 font-bold">Belgeleri doğrulanmış ✓</span></span>
                                    <span class="text-neutral-400 whitespace-nowrap">TIR · tenteli 13.60</span>
                                </div>
                            </div>

                        <!-- Şoför sahnesi: dağınık grup mesajları NavlunIQ'da temiz ilan kartına dönüşür, dönüş yükü radarı tarar -->
                        <template x-if="role === 'driver'">
                            <div class="hero-scene hero-reveal relative w-full max-w-xl mx-auto rounded-3xl border border-neutral-200/70 dark:border-neutral-800 bg-white/70 dark:bg-neutral-900/70 backdrop-blur-apple shadow-apple-md overflow-hidden p-4 sm:p-5 space-y-4" style="--d: 120ms">
                                <div class="flex items-center justify-between">
                                    <span class="text-[10px] sm:text-[11px] font-black tracking-wider uppercase text-emerald-600 dark:text-emerald-400">Gruplardan panele</span>
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400 font-bold text-[10px]"><span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>Yapay zeka okuyor</span>
                                </div>

                                <div class="relative grid grid-cols-2 gap-x-11 gap-y-2 sm:grid-cols-[1fr_auto_1fr] sm:gap-3 items-center">
                                    <!-- Dağınık mesajlar -->
                                    <div class="space-y-2 min-w-0">
                                        @foreach([['t' => 'ANK ÇIKIŞLI İZMİR 13.60 TENTELİ ACİL ARAÇ', 'd' => 0], ['t' => 'adana - istanbul 8 tkr kamyon hazır fiyat görüşülür', 'd' => 1], ['t' => 'SAMSUN 2 YER KAPALI TIR YÜKLER …', 'd' => 2]] as $msg)
                                            <div class="hero-bubble rounded-2xl rounded-tl-md bg-neutral-100 dark:bg-neutral-800 border border-neutral-200/70 dark:border-neutral-700/60 px-2.5 py-2" style="--i: {{ $msg['d'] }}">
                                                <div class="text-[8px] text-neutral-400 font-semibold mb-0.5">Grup mesajı</div>
                                                <div class="text-[9px] sm:text-[10px] leading-snug text-neutral-500 dark:text-neutral-400 break-words line-clamp-2">{{ $msg['t'] }}</div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <!-- Dönüştürücü -->
                                    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 sm:static sm:translate-x-0 sm:translate-y-0 flex flex-col items-center justify-center">
                                        <span class="hero-ring absolute w-14 h-14 sm:w-16 sm:h-16 rounded-full border-2 border-brand-500/40"></span>
                                        <span class="hero-ring hero-ring-2 absolute w-14 h-14 sm:w-16 sm:h-16 rounded-full border-2 border-brand-500/40"></span>
                                        <span class="relative w-10 h-10 sm:w-12 sm:h-12 rounded-2xl bg-gradient-to-b from-brand-500 to-brand-600 shadow-lg shadow-brand-500/30 flex items-center justify-center">
                                            <img src="{{ asset_v('/images/white-symbol-logo.png') }}" alt="" class="w-6 h-6 sm:w-7 sm:h-7 object-contain">
                                        </span>
                                        <svg class="w-4 h-4 text-brand-500 mt-1 hero-arrow-pulse hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                                    </div>

                                    <!-- Temiz ilan kartları -->
                                    <div class="space-y-2 min-w-0">
                                        @foreach([['r' => 'Ankara → İzmir', 'v' => 'TIR · Tenteli 13.60', 'p' => '18.500 ₺', 'd' => 0], ['r' => 'Adana → İstanbul', 'v' => '8 teker kamyon', 'p' => 'Görüşülür', 'd' => 1], ['r' => 'Samsun → 2 nokta', 'v' => 'Kapalı TIR · 2 araç', 'p' => 'Seri ilan', 'd' => 2]] as $card)
                                            <div class="hero-card rounded-2xl bg-white dark:bg-neutral-950 border border-neutral-200/80 dark:border-neutral-800 px-2.5 py-2 shadow-apple-sm" style="--i: {{ $card['d'] }}">
                                                <div class="hero-row">
                                                    <span class="text-[9px] sm:text-[10px] font-black text-neutral-900 dark:text-white truncate">{{ $card['r'] }}</span>
                                                    <span class="hidden sm:inline-flex text-[8px] font-bold px-1.5 py-px rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 shrink-0">Yeni</span>
                                                </div>
                                                <div class="hero-row mt-0.5">
                                                    <span class="text-[8px] sm:text-[9px] text-neutral-500 dark:text-neutral-400 truncate">{{ $card['v'] }}</span>
                                                    <span class="text-[9px] sm:text-[10px] font-bold text-brand-500 tabular-nums shrink-0">{{ $card['p'] }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- Dönüş yükü radarı -->
                                <div class="flex items-center gap-3 rounded-2xl bg-emerald-500/5 dark:bg-emerald-500/10 border border-emerald-500/20 px-3 py-2">
                                    <span class="relative flex items-center justify-center w-8 h-8 shrink-0">
                                        <span class="hero-radar absolute inset-0 rounded-full bg-emerald-500/30"></span>
                                        <span class="hero-radar hero-radar-2 absolute inset-0 rounded-full bg-emerald-500/30"></span>
                                        <span class="relative w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-[10px] sm:text-[11px] font-black text-neutral-900 dark:text-white">Dönüş yükü radarı</div>
                                        <div class="text-[9px] sm:text-[10px] text-neutral-500 dark:text-neutral-400 truncate">Varışta dönüş yükü senin için taranır</div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Güven şeridi -->
                <div class="mt-8 md:mt-10 pt-6 border-t border-neutral-200/60 dark:border-neutral-800/60 grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
                    @foreach([
                        ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l2 2 3.5-4"/>', 'title' => 'Belgeleri doğrulanmış şoförler', 'text' => 'Ehliyet, SRC ve araç belgeleri onaylı'],
                        ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 11V8a5 5 0 0110 0v3M6 11h12v9H6z"/>', 'title' => 'Ödeme teslimat onayıyla', 'text' => 'Para teslimata kadar ödeme kuruluşunda bekler'],
                        ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/>', 'title' => 'iyzico güvenli ödeme', 'text' => 'Kartla, 3D Secure ile; kart bilgisi bizde kalmaz'],
                        ['icon' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/>', 'title' => 'Canlı ilan akışı', 'text' => $todayLoads > 0 ? 'Bugün '.number_format($todayLoads, 0, ',', '.').' yeni ilan derlendi' : 'Gruplardan derlenen ilanlar anında panelde'],
                    ] as $i => $chip)
                        <div class="hero-reveal flex items-start gap-2.5 min-w-0" style="--d: {{ 360 + $i * 60 }}ms">
                            <span class="w-8 h-8 shrink-0 rounded-xl bg-brand-500/10 text-brand-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">{!! $chip['icon'] !!}</svg></span>
                            <div class="min-w-0">
                                <div class="text-[11px] sm:text-xs font-bold text-neutral-900 dark:text-white leading-tight">{{ $chip['title'] }}</div>
                                <div class="text-[10px] sm:text-[11px] text-neutral-500 dark:text-neutral-400 leading-snug">{{ $chip['text'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <!-- 2. BÖLÜM: CANLI SAYAÇLAR (kendi kendine yenilenir; livewire/frontend/live-stats) -->
    <livewire:frontend.live-stats />

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
                <div class="w-12 h-12 rounded-2xl bg-brand-500/10 text-brand-500 flex items-center justify-center"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8m-8-4h8m-8 8h5M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Akıllı Eşleşme & Teklif</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Akıllı lojistik ağımız yük sahiplerinin ilanlarını tarar, uygun onaylı araçlarla eşleştirir. Şoförler teklif verir ve fiyatta anlaşırlar.
                </p>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 relative overflow-hidden shadow-apple-sm border-t-2 border-brand-500">
                <div class="w-12 h-12 rounded-2xl bg-brand-500 text-white shadow-lg shadow-brand-500/30 flex items-center justify-center"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l2 2 3.5-4"/></svg></div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Güvenli Ödeme & Canlı Takip</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Yük sahibi navlun bedelini lisanslı ödeme kuruluşu üzerinden öder, şoför yola çıkar. Yük sahibi sevkiyatı canlı konumla takip eder.
                </p>
            </div>

            <div class="apple-glass rounded-3xl p-8 space-y-4 relative overflow-hidden shadow-apple-sm">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center"><svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 14h4"/></svg></div>
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">Teslimat Onayı & Ödeme</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    Şoför teslimat kanıtını yükler, yük sahibi onaylar. Onayın ardından şoförün navlun ödemesi banka hesabına geçer.
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
                        Premium üyeler gruplardan derlenen ilanları ilan bilgileriyle görür, yeni NavlunIQ ilanlarına herkesten {{ $leadText }} önce ulaşır ve anında bildirim alır; standart üyeler grup ilanlarını görmez ve bildirim almaz, ilanlar panellerine {{ $lead > 0 ? $lead.' dakika sonra' : 'aynı anda' }} düşer.
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
        @php $trialDays = app(\App\Services\SubscriptionService::class)->trialDays(); @endphp
        <div class="text-center space-y-3 max-w-2xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">SÜRÜCÜ ÜYELİK PLANLARI</span>
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-neutral-950 dark:text-white">Yükleri herkesten önce görün.</h2>
            <p class="text-xs sm:text-sm text-neutral-400">Teklif vermek her zaman ücretsiz. Premium üyeler gruplardan derlenen ilanları ilan bilgileriyle görür, yeni ilanlara {{ $leadText }} önce ulaşır ve anında bildirim alır.{{ $trialDays > 0 ? " İlk {$trialDays} gün ücretsiz, kart gerekmez." : '' }}</p>
        </div>

        <x-plan-cards />
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
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">HER TONAJDA NAKLİYE</span>
            <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-neutral-950 dark:text-white">
                Desteklenen Araç Türleri
            </h2>
            <p class="text-xs sm:text-sm text-neutral-500 dark:text-neutral-400">
                Hafif ticariden ağır tonaja kadar tüm ticari araç sınıfları için ilan verebilir, teklif alabilirsiniz.
            </p>
        </div>

        @php
            $aracIkonlari = [
                'tir' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2 7h11v9H2zM13 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="10" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
                'kamyon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h10v9H3zM13 11h4l3 3v2h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="16.5" cy="18" r="1.6"/>',
                'kamyonet' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 9h9v7H3zM12 12h4l2 2v2h-6z"/><circle cx="6.5" cy="18" r="1.5"/><circle cx="15.5" cy="18" r="1.5"/>',
                'panelvan' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 9a1 1 0 011-1h11l4 4v4H3z"/><path stroke-linecap="round" d="M15 8v4h4"/><circle cx="7" cy="17" r="1.5"/><circle cx="16" cy="17" r="1.5"/>',
            ];
            $aracListesi = [
                ['name' => 'Tır (Çekici + Dorse)', 'cat' => 'Ağır Vasıta', 'cap' => '28 ton', 'vol' => '90 m³', 'tag' => 'Mega / Standart', 'icon' => 'tir', 'color' => 'brand'],
                ['name' => 'Kırkayak (4 Dingil)', 'cat' => 'Ağır Vasıta', 'cap' => '24 ton', 'vol' => '65 m³', 'tag' => 'Ağır Sanayi', 'icon' => 'tir', 'color' => 'brand'],
                ['name' => '10 Teker Kamyon', 'cat' => 'Ağır Ticari', 'cap' => '16 ton', 'vol' => '50 m³', 'tag' => 'Fabrika & Palet', 'icon' => 'kamyon', 'color' => 'amber'],
                ['name' => '8 Teker Kamyon', 'cat' => 'Ağır Ticari', 'cap' => '12 ton', 'vol' => '40 m³', 'tag' => 'Şehirler Arası', 'icon' => 'kamyon', 'color' => 'amber'],
                ['name' => '6 Teker Kamyon', 'cat' => 'Orta Ticari', 'cap' => '8 ton', 'vol' => '30 m³', 'tag' => 'Bölgesel Dağıtım', 'icon' => 'kamyon', 'color' => 'amber'],
                ['name' => 'Kamyonet', 'cat' => 'Hafif Ticari', 'cap' => '3,5 ton', 'vol' => '20 m³', 'tag' => 'Açık / Kapalı Kasa', 'icon' => 'kamyonet', 'color' => 'emerald'],
                ['name' => 'Panelvan', 'cat' => 'Hafif Ticari', 'cap' => '2 ton', 'vol' => '14 m³', 'tag' => 'Kapalı / Frigo', 'icon' => 'panelvan', 'color' => 'emerald'],
            ];
            $aracRenkleri = [
                'brand' => ['badge' => 'bg-brand-500/10 text-brand-600 dark:text-brand-400', 'icon' => 'bg-brand-500/10 text-brand-500 group-hover:bg-brand-500 group-hover:text-white'],
                'amber' => ['badge' => 'bg-amber-500/10 text-amber-700 dark:text-amber-400', 'icon' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-white'],
                'emerald' => ['badge' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', 'icon' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 group-hover:bg-emerald-500 group-hover:text-white'],
                'sky' => ['badge' => 'bg-sky-500/10 text-sky-700 dark:text-sky-400', 'icon' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 group-hover:bg-sky-500 group-hover:text-white'],
            ];
            $ciftListe = array_merge($aracListesi, $aracListesi);
        @endphp

        <div class="flex flex-wrap items-center justify-center gap-2">
            @foreach(['brand' => 'Ağır Vasıta', 'amber' => 'Ağır & Orta Ticari', 'emerald' => 'Hafif Ticari'] as $renk => $etiket)
                <span class="badge {{ $aracRenkleri[$renk]['badge'] }}">{{ $etiket }}</span>
            @endforeach
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

            <div class="animate-marquee-smooth flex items-stretch gap-4 cursor-grab active:cursor-grabbing select-none">
                @foreach($ciftListe as $index => $arac)
                    @php $renk = $aracRenkleri[$arac['color']]; @endphp
                    <div class="w-56 sm:w-60 flex-shrink-0 bg-white dark:bg-neutral-900 rounded-3xl p-5 border border-neutral-200/80 dark:border-neutral-800 shadow-apple-sm hover:shadow-apple-md hover:border-brand-500/40 hover:-translate-y-0.5 transition-all duration-300 flex flex-col gap-4 group">
                        <div class="flex items-center justify-between gap-2">
                            <span class="w-12 h-12 rounded-2xl flex items-center justify-center transition-colors duration-300 {{ $renk['icon'] }}">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">{!! $aracIkonlari[$arac['icon']] !!}</svg>
                            </span>
                            <span class="badge {{ $renk['badge'] }}">{{ $arac['cat'] }}</span>
                        </div>
                        <div class="space-y-1 flex-1">
                            <h4 class="text-sm font-bold text-neutral-900 dark:text-white truncate group-hover:text-brand-500 transition-colors">{{ $arac['name'] }}</h4>
                            <p class="text-[11px] text-neutral-500 dark:text-neutral-400">{{ $arac['tag'] }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 pt-3 border-t border-neutral-100 dark:border-neutral-800/60">
                            <div>
                                <div class="text-[10px] font-semibold text-neutral-400 uppercase tracking-wider">Kapasite</div>
                                <div class="text-xs font-bold text-neutral-900 dark:text-white tabular-nums">{{ $arac['cap'] }}</div>
                            </div>
                            <div>
                                <div class="text-[10px] font-semibold text-neutral-400 uppercase tracking-wider">Hacim</div>
                                <div class="text-xs font-bold text-neutral-900 dark:text-white tabular-nums">{{ $arac['vol'] }}</div>
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
                <h3 class="text-lg font-black text-neutral-950 dark:text-white">Güvenli Ödeme Altyapısı</h3>
                <p class="text-xs text-neutral-400 max-w-2xl">Ödemeler BDDK lisanslı ödeme kuruluşu iyzico üzerinden kredi kartı ya da banka kartıyla, 3D Secure doğrulamasıyla alınır. Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; navlun ödemesi teslimat onayıyla şoföre tamamlanır.</p>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 shrink-0">
                <a href="https://www.iyzico.com" target="_blank" rel="noopener" title="iyzico ile Öde" class="opacity-90 hover:opacity-100 transition-opacity">
                    <img src="/images/payment/iyzico-ile-ode.svg" alt="iyzico ile Öde" class="h-6 w-auto dark:hidden">
                    <img src="/images/payment/iyzico-ile-ode-white.svg" alt="iyzico ile Öde" class="h-6 w-auto hidden dark:block">
                </a>
                <div class="flex items-center space-x-3 text-xs font-mono font-bold text-emerald-600 bg-emerald-500/10 px-4 py-2 rounded-xl whitespace-nowrap">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>SSL ile şifreli bağlantı</span>
                </div>
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
            <p class="text-xs text-neutral-500 dark:text-neutral-400">NavlunIQ işleyişi, ödeme akışı ve güvenlik hakkında merak edilenler.</p>
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
