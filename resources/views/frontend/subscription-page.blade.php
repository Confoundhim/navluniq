@php
    $monthlyPrice = \App\Support\Settings::float('premium_monthly_price');
    $standardRate = \App\Support\Settings::float('commission_standard_driver');
    $paymentReady = app(\App\Services\PaymentService::class)->isConfigured();
    $pct = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    $priceText = number_format($monthlyPrice, 0, ',', '.');

    $check = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
    $dash = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 12h12"/></svg>';

    $comparison = [
        ['Platform içi yük ilanlarını görme', 'Anında', 'Anında'],
        ['Teklif verme hakkı', 'Sınırsız', 'Sınırsız'],
        ['Onaylı dış kaynak ilanları', '20 dakika gecikmeli', 'Yayınlandığı anda'],
        ['Dış kaynak ilanlarda iletişim bilgisi', 'Kısmen gizli', 'Tamamı görünür'],
        ['Teslimat onaylı güvenli ödeme', 'Dahil', 'Dahil'],
        ['Cüzdan, fatura ve destek talepleri', 'Dahil', 'Dahil'],
    ];
@endphp

<x-layouts.frontend title="Sürücü Üyelik Planları - NavlunIQ">
    <div class="max-w-6xl mx-auto px-6 md:px-12 space-y-20 animate-fade-in">

        <!-- Giriş -->
        <section class="text-center space-y-5 max-w-3xl mx-auto">
            <span class="text-xs font-extrabold text-brand-500 uppercase tracking-widest">SÜRÜCÜ ÜYELİK PLANLARI</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight leading-tight">
                Yükleri herkesten önce görün.
            </h1>
            <p class="text-sm sm:text-base text-neutral-500 dark:text-neutral-400 leading-relaxed">
                NavlunIQ'da platform ilanlarına teklif vermek her zaman ücretsizdir. Premium üyelik, dış kaynaklardan derlenen onaylı ilanları herkesten 20 dakika önce görmenizi ve ilan sahibine doğrudan ulaşmanızı sağlar.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">Taahhüt yok</span>
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">Aylık dönem</span>
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-700">KDV dahil fatura</span>
            </div>
        </section>

        <!-- Plan kartları -->
        <section class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 max-w-4xl mx-auto items-stretch">
            <div class="apple-glass rounded-3xl p-8 shadow-apple-sm flex flex-col gap-6">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">STANDART</span>
                        <span class="w-9 h-9 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-300 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </span>
                    </div>
                    <h2 class="text-2xl font-black text-neutral-900 dark:text-white">Ücretsiz Şoför Hesabı</h2>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Platforma yeni katılan ve yükünü kendi hızında bulmak isteyen şoförler için.</p>
                    <div class="pt-2 flex items-baseline gap-1.5">
                        <span class="text-4xl font-black text-neutral-900 dark:text-white tabular-nums">0 ₺</span>
                        <span class="text-xs text-neutral-400">/ süresiz</span>
                    </div>
                </div>
                <ul class="space-y-3 text-xs text-neutral-600 dark:text-neutral-300 pt-5 border-t border-neutral-100 dark:border-neutral-800 flex-1">
                    <li class="flex items-start gap-2.5"><span class="text-emerald-500 mt-px">{!! $check !!}</span><span>Platform içi tüm yük ilanlarını anında görün, sınırsız teklif verin</span></li>
                    <li class="flex items-start gap-2.5"><span class="text-emerald-500 mt-px">{!! $check !!}</span><span>Teslimat onaylı güvenli ödeme, cüzdan ve teslimat kayıtları</span></li>
                    <li class="flex items-start gap-2.5"><span class="text-emerald-500 mt-px">{!! $check !!}</span><span>Onaylı dış kaynak ilanlarına 20 dakika gecikmeli erişim</span></li>
                    <li class="flex items-start gap-2.5"><span class="text-neutral-400 mt-px">{!! $dash !!}</span><span>Onaylı dış kaynak ilanlarında iletişim bilgisi kısmen gizli</span></li>
                </ul>
                <a href="{{ route('register.driver') }}" class="btn-apple-secondary w-full py-3.5 text-xs font-bold">Ücretsiz Kaydol</a>
            </div>

            <div class="relative rounded-3xl p-[2px] bg-gradient-to-b from-brand-500 via-brand-500/60 to-brand-500/20 shadow-apple-lg">
                <div class="h-full rounded-[22px] bg-white dark:bg-neutral-900 p-8 flex flex-col gap-6">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-[10px] font-black px-3 py-1 rounded-full uppercase tracking-wider shadow-md shadow-brand-500/30">ÖNERİLEN</div>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-brand-500 uppercase tracking-wider">PREMIUM</span>
                            <span class="w-9 h-9 rounded-xl bg-brand-500/10 text-brand-500 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                        </div>
                        <h2 class="text-2xl font-black text-neutral-900 dark:text-white">Premium Şoför Üyeliği</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Düzenli sefer yapan ve her ay daha fazla yük taşımak isteyen profesyoneller için.</p>
                        <div class="pt-2 flex items-baseline gap-1.5">
                            <span class="text-4xl font-black text-brand-500 tabular-nums">{{ $priceText }} ₺</span>
                            <span class="text-xs text-neutral-400">/ ay, KDV dahil</span>
                        </div>
                    </div>
                    <ul class="space-y-3 text-xs text-neutral-700 dark:text-neutral-200 font-medium pt-5 border-t border-neutral-100 dark:border-neutral-800 flex-1">
                        <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Ücretsiz hesabın tüm özellikleri</span></li>
                        <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Onaylı dış kaynak ilanlarını yayınlandığı anda, herkesten 20 dakika önce görün</span></li>
                        <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Dış kaynak ilanlarda ilan sahibinin iletişim bilgisinin tamamına erişin</span></li>
                        <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Sabit aylık ücret, sevkiyat başına ek ödeme yok</span></li>
                    </ul>
                    <div class="space-y-2">
                        @auth
                            <a href="{{ route('driver.premium.index') }}" class="btn-apple-brand w-full py-3.5 text-xs font-bold">Premium Sayfasına Git</a>
                        @else
                            <a href="{{ route('register.driver') }}" class="btn-apple-brand w-full py-3.5 text-xs font-bold">Şoför Olarak Kaydol</a>
                        @endauth
                        <p class="text-[11px] text-center text-neutral-400">
                            @if($paymentReady)
                                Premium, şoför panelinizdeki Premium sayfasından etkinleştirilir.
                            @else
                                Ödeme altyapısı aktivasyon aşamasında; satın alma yakında panelinizden açılacak.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Erken erişim ne demek -->
        <section class="max-w-4xl mx-auto">
            <div class="apple-glass rounded-3xl p-6 md:p-8 shadow-apple-sm grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                <div class="md:col-span-2 space-y-2">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">20 dakika neden fark yaratır?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                        Dış kaynaklardan gelen bir yük ilanı çoğu zaman ilk arayan şoförde kalır. Premium üyeler onaylanan ilanı yayınlandığı anda, ilan sahibinin numarasıyla birlikte görür; standart üyelere aynı ilan 20 dakika sonra ve numarası kısmen gizli açılır. Platform hizmet bedeli iki planda da aynıdır, premium ücretin karşılığı yalnız bu öncelik ve doğrudan iletişimdir.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-2xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-800 p-4">
                        <div class="text-[10px] font-bold text-neutral-400 uppercase tracking-wider">Standart</div>
                        <div class="text-xl font-black text-neutral-900 dark:text-white tabular-nums mt-1">+20 dk</div>
                        <div class="text-[10px] text-neutral-400 mt-0.5">gecikmeli</div>
                    </div>
                    <div class="rounded-2xl bg-brand-500/10 border border-brand-500/20 p-4">
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-wider">Premium</div>
                        <div class="text-xl font-black text-brand-500 tabular-nums mt-1">Anında</div>
                        <div class="text-[10px] text-brand-500/80 mt-0.5">numarayla</div>
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
                <div class="overflow-x-auto">
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
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">2. Premium'u etkinleştirin</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Şoför panelinizdeki Premium sayfasından aylık üyeliğinizi başlatın. Kart bilgileriniz NavlunIQ'da saklanmaz, faturanız panelinizde görünür.</p>
                </div>
                <div class="apple-glass rounded-3xl p-7 space-y-3 shadow-apple-sm">
                    <div class="w-11 h-11 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/><path stroke-linecap="round" d="M7 14h4"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">3. İlanları ilk siz görün</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Üyeliğiniz süresince onaylanan dış kaynak ilanları panelinize anında, ilan sahibinin numarasıyla düşer. Dönem bittiğinde hesabınız kendiliğinden standarda döner.</p>
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
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Üyelik otomatik yenilenir mi?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Hayır. Premium aylık dönemler halinde satın alınır. Dönem sonunda uzatmazsanız hesabınız standart plana döner; hiçbir şey kaybetmezsiniz.</p>
                </div>
                <div class="apple-glass rounded-2xl p-6 space-y-2 shadow-apple-sm">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Dış kaynak ilanları nedir?</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">İzinli web siteleri ve gruplardan derlenip ekibimizce onaylanan ilanlardır. Pazarlık ve ödeme ilan sahibiyle doğrudan yapılır; teslimat onaylı güvenli ödeme yalnız platform içi ilanlarda geçerlidir.</p>
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
                    <h2 class="text-2xl sm:text-3xl font-black tracking-tight">Ücretsiz başlayın, hazır olduğunuzda Premium'a geçin.</h2>
                    <p class="text-sm text-neutral-300 max-w-xl mx-auto leading-relaxed">Kayıt ve evrak onayı ücretsizdir. Premium'a ne zaman geçeceğinize siz karar verirsiniz.</p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
                        <a href="{{ route('register.driver') }}" class="btn-apple-brand w-full sm:w-auto px-8 py-3.5 text-xs font-bold">Şoför Olarak Kaydol</a>
                        <a href="{{ route('contact') }}" class="btn-apple-secondary w-full sm:w-auto px-8 py-3.5 text-xs font-bold">Bize Ulaşın</a>
                    </div>
                </div>
            </div>
        </section>

    </div>
</x-layouts.frontend>
