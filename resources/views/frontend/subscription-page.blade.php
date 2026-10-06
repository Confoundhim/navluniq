@php
    $monthlyPrice = \App\Support\Settings::float('premium_monthly_price');
    $standardRate = \App\Support\Settings::float('commission_standard_driver');
    $paymentReady = app(\App\Services\PaymentService::class)->isConfigured();
    $pct = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    $priceText = number_format($monthlyPrice, 0, ',', '.');
    $lead = app(\App\Services\LoadReleaseService::class)->delayMinutes(); // panel ayarı: ücretsiz üyelere açılma gecikmesi
    $leadText = $lead > 0 ? "{$lead} dakika" : 'aynı anda';
    $trialDays = app(\App\Services\SubscriptionService::class)->trialDays(); // panel ayarı: ücretsiz deneme (0 kapalı)
    $stats = app(\App\Services\LoadStatsService::class)->summary();
    $weekLoads = (int) ($stats['external_7d'] ?? 0);
    $isDriver = auth()->user()?->driverProfile !== null;
    $premiumHref = $isDriver ? route('driver.premium.index') : route('register.driver');

    $check = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
    $dash = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 12h12"/></svg>';

    $comparison = array_values(array_filter([
        ['Gruplardan derlenen ilanlar (numarasıyla)', 'Görünmez', 'Tamamı'],
        ['Yük sahiplerinin NavlunIQ ilanları', $lead > 0 ? "{$lead} dakika sonra" : 'Yayınlandığı anda', 'Yayınlandığı anda'],
        ['Yeni ilan bildirimi', 'Panele düşer, bildirim gelmez', 'Anında, uygulama içi + e-posta'],
        ['Dönüş yükü radarı', 'İşlerim sayfasında görünür, bildirim gelmez', 'Bildirimle'],
        ['Teklif verme hakkı', 'Sınırsız', 'Sınırsız'],
        ['Teslimat onaylı güvenli ödeme', 'Dahil', 'Dahil'],
        ['Ödeme geçmişi, fatura ve destek talepleri', 'Dahil', 'Dahil'],
        $trialDays > 0 ? ['Ücretsiz deneme', '—', "{$trialDays} gün, bir kez, kart gerekmez"] : null,
        ['Aylık ücret', '0 ₺', "{$priceText} ₺ (KDV dahil), otomatik yenilenmez"],
    ]));
@endphp

<x-layouts.frontend title="Sürücü Üyelik Planları - NavlunIQ" description="NavlunIQ Premium: gruplardan derlenen ilanlar numarasıyla, NavlunIQ ilanlarına erken erişim, anında bildirim ve dönüş yükü radarı. Ücretsiz deneme ile başlayın.">
    <div class="max-w-6xl mx-auto px-6 md:px-12 space-y-20 animate-fade-in">

        <!-- Giriş -->
        <section class="text-center space-y-5 max-w-3xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">SÜRÜCÜ ÜYELİK PLANLARI</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight leading-tight">
                Yükleri herkesten önce görün.
            </h1>
            <p class="text-sm sm:text-base text-neutral-500 dark:text-neutral-400 leading-relaxed">
                Gruplarda saatlerce kaydırmak yerine temiz ilan kartları, ilan sahibinin numarası ve size uyan yük çıkınca bildirim.
                Teklif vermek her zaman ücretsizdir; Premium, yükü ilk gören olmanızı sağlar.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                @if($trialDays > 0)
                    <span class="badge bg-brand-500/10 text-brand-600 dark:text-brand-400 border border-brand-500/20">{{ $trialDays }} gün ücretsiz</span>
                    <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">Kart gerekmez</span>
                @endif
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">Taahhüt yok</span>
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">Otomatik yenilenmez</span>
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">KDV dahil fatura</span>
            </div>
        </section>

        <!-- Plan kartları (ana sayfa ile ortak bileşen) -->
        <section>
            <x-plan-cards />
        </section>

        <!-- Erken erişim ne demek -->
        <section class="max-w-4xl mx-auto">
            <div class="apple-glass rounded-3xl p-6 md:p-8 shadow-apple-sm grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                <div class="md:col-span-2 space-y-2">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">{{ $lead > 0 ? $leadText.' neden fark yaratır?' : 'Bildirim neden fark yaratır?' }}</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        Bir yük ilanı çoğu zaman ilk teklif veren şoförde kalır. Yük sahibi ilanı açtığı anda premium üyelere bildirim gider ve ilan onların havuzunda görünür; standart üyeler aynı ilanı {{ $lead > 0 ? $lead.' dakika sonra' : 'aynı anda' }} panellerinde görür, bildirim almaz. Gruplardan derlenen ilanlar ise yalnız premium üyelere açıktır.@if($weekLoads > 0) Son 7 günde {{ number_format($weekLoads, 0, ',', '.') }} grup ilanı derlendi; bunların hiçbiri standart üyeye görünmez.@endif Platform hizmet bedeli iki planda da aynıdır.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-2xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-800 p-4">
                        <div class="text-[10px] font-bold text-neutral-400 uppercase tracking-wider">Standart</div>
                        <div class="text-xl font-black text-neutral-900 dark:text-white tabular-nums mt-1">+{{ $lead }} dk</div>
                        <div class="text-[10px] text-neutral-400 mt-0.5">gecikmeli</div>
                    </div>
                    <div class="rounded-2xl bg-brand-500/10 border border-brand-500/20 p-4">
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-wider">Premium</div>
                        <div class="text-xl font-black text-brand-500 tabular-nums mt-1">Anında</div>
                        <div class="text-[10px] text-brand-500/80 mt-0.5">bildirimle</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Karşılaştırma tablosu -->
        <section class="max-w-4xl mx-auto space-y-6">
            <div class="text-center space-y-2">
                <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">PLAN KARŞILAŞTIRMASI</span>
                <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-neutral-950 dark:text-white">Neyi, ne zaman görürsünüz?</h2>
            </div>
            <div class="apple-glass rounded-3xl shadow-apple-sm overflow-hidden">
                {{-- Telefonda satır satır kart (yatay kaydırma yok); geniş ekranda tablo --}}
                <div class="sm:hidden divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach($comparison as [$feature, $standard, $premium])
                        <div class="px-5 py-3.5 space-y-2 text-xs">
                            <div class="text-neutral-800 dark:text-neutral-100 font-bold">{{ $feature }}</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="rounded-xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-800 p-2.5">
                                    <div class="text-3xs font-bold uppercase tracking-wider text-neutral-400">Standart</div>
                                    <div class="text-neutral-600 dark:text-neutral-300 mt-0.5 leading-snug">{{ $standard }}</div>
                                </div>
                                <div class="rounded-xl bg-brand-500/5 border border-brand-500/20 p-2.5">
                                    <div class="text-3xs font-bold uppercase tracking-wider text-brand-500">Premium</div>
                                    <div class="text-neutral-900 dark:text-white font-semibold mt-0.5 leading-snug">{{ $premium }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-xs min-w-[560px]">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-neutral-800">
                                <th class="text-left font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider text-[10px] px-6 py-4">Özellik</th>
                                <th class="text-center font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider text-[10px] px-4 py-4 w-40">Standart</th>
                                <th class="text-center font-bold text-brand-500 uppercase tracking-wider text-[10px] px-4 py-4 w-40 bg-brand-500/5">Premium</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach($comparison as [$feature, $standard, $premium])
                                <tr>
                                    <td class="px-6 py-3.5 text-neutral-700 dark:text-neutral-200 font-medium">{{ $feature }}</td>
                                    <td class="px-4 py-3.5 text-center text-neutral-500 dark:text-neutral-400 tabular-nums">{{ $standard }}</td>
                                    <td class="px-4 py-3.5 text-center font-semibold text-neutral-900 dark:text-white tabular-nums bg-brand-500/5">{{ $premium }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="px-6 py-3 text-[11px] text-neutral-500 border-t border-neutral-100 dark:border-neutral-800">Tamamlanan sevkiyatlarda uygulanan %{{ $pct($standardRate) }} platform hizmet bedeli her iki planda aynıdır.</p>
            </div>
        </section>

        <!-- Nasıl işler -->
        <section class="max-w-5xl mx-auto space-y-8">
            <div class="text-center space-y-2">
                <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">ÜÇ ADIMDA PREMIUM</span>
                <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-neutral-950 dark:text-white">Başlamak için gereken her şey</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="apple-glass rounded-3xl p-7 space-y-3 shadow-apple-sm">
                    <div class="w-11 h-11 rounded-2xl bg-brand-500/10 text-brand-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 4h7l5 5v11a1 1 0 01-1 1H7a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">1. Kaydolun, evraklarınız onaylansın</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Sürücü belgesi, SRC, psikoteknik ve araç ruhsatınızı yükleyin. Evrak ekibimiz inceleyip onayladığında teklif vermeye başlarsınız.</p>
                </div>
                <div class="apple-glass rounded-3xl p-7 space-y-3 shadow-apple-sm border-t-2 border-brand-500">
                    <div class="w-11 h-11 rounded-2xl bg-brand-500 text-white shadow-lg shadow-brand-500/30 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">2. {{ $trialDays > 0 ? $trialDays.' gün ücretsiz deneyin' : "Premium'u etkinleştirin" }}</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        @if($trialDays > 0)
                            Belgeleriniz onaylandığı anda {{ $trialDays }} günlük premium deneme kendiliğinden başlar; kart bilgisi istenmez. Süre bitince ücret alınmaz, hesabınız standart üyeliğe döner.
                        @else
                            Şoför panelinizdeki Premium sayfasından aylık üyeliğinizi başlatın. Kart bilgileriniz NavlunIQ'da saklanmaz, faturanız panelinizde görünür.
                        @endif
                    </p>
                </div>
                <div class="apple-glass rounded-3xl p-7 space-y-3 shadow-apple-sm">
                    <div class="w-11 h-11 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/><path stroke-linecap="round" d="M7 14h4"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">3. {{ $trialDays > 0 ? 'Beğenirseniz aylık devam edin' : 'İlanları ilk siz görün' }}</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">{{ $trialDays > 0 ? 'Deneme bitince Premium sayfasından aylık üyeliği başlatırsınız: '.$priceText.' ₺, KDV dahil, otomatik yenilenmez. Kart bilgileriniz NavlunIQ\'da saklanmaz, faturanız panelinizde görünür.' : 'Üyeliğiniz süresince yeni NavlunIQ ilanları panelinize anında, bildirimle düşer; gruplardan derlenen ilanlar da yalnız size açılır. Dönem bittiğinde hesabınız kendiliğinden standarda döner.' }}</p>
                </div>
            </div>
        </section>

        <!-- Sık sorulanlar -->
        <section class="max-w-4xl mx-auto space-y-6">
            <div class="text-center space-y-2">
                <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">MERAK EDİLENLER</span>
                <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-neutral-950 dark:text-white">Üyelik hakkında kısa cevaplar</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if($trialDays > 0)
                    <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                        <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Ücretsiz deneme nasıl işler?</h3>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Belgeleri onaylanan her şoföre bir kez {{ $trialDays }} günlük premium tanımlanır; kart bilgisi istenmez. Süre bitince hiçbir ücret alınmaz, hesabınız standart üyeliğe döner. Devam etmek isterseniz Premium sayfasından aylık üyelik başlatırsınız.</p>
                    </div>
                @endif
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Üyelik otomatik yenilenir mi?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Hayır. Premium aylık dönemler halinde satın alınır. Dönem sonunda uzatmazsanız hesabınız standart plana döner; hiçbir şey kaybetmezsiniz.</p>
                </div>
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Gruplardan derlenen ilanlar nedir?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">İzinli taşımacılık gruplarındaki dağınık mesajlar temiz ilan kartına çevrilir, tekrarlar ayıklanır, ilan sahibinin numarası kartta durur. Yalnız premium üyelere gösterilir. Pazarlık ve ödeme ilan sahibiyle doğrudan yapılır; teslimat onaylı güvenli ödeme yalnız NavlunIQ ilanlarında geçerlidir.</p>
                </div>
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Uygulamayı sürekli açmak istemiyorum, ne yapabilirim?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Sistem ilanları herkese açıldığı anda Telegram kanalımızda da yayınlanır; kanalı takip edip yalnız ilgilendiğiniz ilan için uygulamaya girebilirsiniz.@if($telegramUrl = \App\Services\TelegramPublisher::channelUrl()) <a href="{{ $telegramUrl }}" target="_blank" rel="noopener" class="text-brand-500 font-bold hover:underline">Kanala katıl →</a>@endif</p>
                </div>
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Fatura alabilir miyim?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Evet. Her premium ödemesi için KDV dahil fatura düzenlenir ve şoför panelinizdeki Premium sayfasında listelenir.</p>
                </div>
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Ücret iadesi var mı?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Dijital hizmet satın alındığı anda kullanıma açıldığından dönem içinde iade yapılmaz; dönem sonuna kadar tüm haklarınız devam eder.</p>
                </div>
            </div>
            <div class="text-center">
                <a href="{{ route('home') }}#sss" class="text-xs font-bold text-brand-500 hover:underline">Tüm sıkça sorulan sorular →</a>
            </div>
        </section>

        <!-- Kapanış -->
        <section class="max-w-4xl mx-auto">
            <div class="rounded-3xl bg-neutral-950 text-white p-8 md:p-12 text-center space-y-5 shadow-apple-lg relative overflow-hidden">
                <div class="absolute -top-24 -right-24 w-72 h-72 rounded-full bg-brand-500/20 blur-3xl"></div>
                <div class="absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-brand-500/10 blur-3xl"></div>
                <div class="relative space-y-4">
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight">{{ $trialDays > 0 ? "Premium'u {$trialDays} gün ücretsiz deneyin." : "Ücretsiz başlayın, hazır olduğunuzda Premium'a geçin." }}</h2>
                    <p class="text-sm text-neutral-300 max-w-xl mx-auto leading-relaxed">{{ $trialDays > 0 ? 'Kayıt ve belge onayı ücretsizdir; onaylanınca deneme kendiliğinden başlar, kart gerekmez. Devam edip etmemek size kalır.' : "Kayıt ve evrak onayı ücretsizdir. Premium'a ne zaman geçeceğinize siz karar verirsiniz." }}</p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
                        <a href="{{ $premiumHref }}" class="btn-apple-brand w-full sm:w-auto px-8 py-3.5 text-xs font-bold">{{ $trialDays > 0 ? $trialDays.' gün ücretsiz dene' : 'Şoför Olarak Kaydol' }}</a>
                        <a href="{{ route('contact') }}" class="btn-apple-secondary w-full sm:w-auto px-8 py-3.5 text-xs font-bold">Bize Ulaşın</a>
                    </div>
                </div>
            </div>
        </section>

    </div>
</x-layouts.frontend>
