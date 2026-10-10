{{-- Şoför üyelik planı kartları: ana sayfa (#abonelik) ve /uyelik sayfası aynı bileşeni kullanır; metinler §4 bildirim/erişim
     kurallarıyla birebirdir (standart üye grup ilanlarını görmez, bildirim almaz, sistem ilanlarını gecikmeli görür).
     Telefonda premium kart üstte (order), masaüstünde sağda. Deneme süresi panel ayarı `premium_trial_days` (0 ise satırlar gizlenir). --}}
@php
    $subscriptions = app(\App\Services\SubscriptionService::class);
    $monthlyPrice = $subscriptions->monthlyPrice();
    $priceText = number_format($monthlyPrice, 0, ',', '.');
    $maxDiscount = max(array_map(fn ($m) => $subscriptions->discountFor($m), \App\Services\SubscriptionService::PLAN_MONTHS));
    $perDay = $monthlyPrice > 0 ? (int) ceil($monthlyPrice / 30) : 0;
    $trialDays = $subscriptions->trialDays();
    $autoRenew = $subscriptions->autoRenewAvailable(); // kuruluşta kart saklama açıksa "isterseniz otomatik yenilenir"
    $lead = app(\App\Services\LoadReleaseService::class)->delayMinutes();
    $leadText = $lead > 0 ? "{$lead} dakika" : 'aynı anda';
    $leadBefore = $lead > 0 ? "herkesten {$lead} dakika önce" : 'yayınlandığı anda';
    $stats = app(\App\Services\LoadStatsService::class)->summary();
    $weekLoads = (int) ($stats['external_7d'] ?? 0);
    $dailyAvg = (float) ($stats['external_daily_avg'] ?? 0);
    $user = auth()->user();
    $isDriver = $user && $user->driverProfile;
    $premiumHref = $isDriver ? route('driver.premium.index') : route('register.driver');
    $premiumCta = $trialDays > 0 ? "{$trialDays} gün ücretsiz dene" : ($isDriver ? 'Premium sayfama git' : 'Premium ile başla');
    $freeHref = $isDriver ? route('driver.dashboard') : route('register.driver');
    $directPay = \App\Support\FreightPayment::direct(); // doğrudan kip: tanıtımda navlun ödemesi/komisyon anlatılmaz

    $check = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
    $dash = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 12h12"/></svg>';
@endphp

<div {{ $attributes->merge(['class' => 'grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 max-w-4xl mx-auto items-stretch']) }}>

    {{-- Premium: telefonda önce --}}
    <div class="order-1 md:order-2 relative rounded-3xl p-[2px] bg-gradient-to-b from-brand-500 via-brand-500/60 to-brand-500/20 shadow-apple-lg">
        <div class="h-full rounded-[22px] bg-white dark:bg-neutral-900 p-7 sm:p-8 flex flex-col gap-5">
            <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-3xs font-black px-3 py-1 rounded-full uppercase tracking-wider shadow-md shadow-brand-500/30 whitespace-nowrap">
                {{ $trialDays > 0 ? "Önerilen · {$trialDays} gün ücretsiz" : 'Önerilen' }}
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-brand-500 uppercase tracking-wider">PREMIUM</span>
                    <span class="w-9 h-9 rounded-xl bg-brand-500/10 text-brand-500 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                </div>
                <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Premium Şoför Üyeliği</h3>
                @if($weekLoads > 0)
                    <div class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 text-2xs font-bold text-emerald-700 dark:text-emerald-400">
                        <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>
                        Son 7 günde {{ number_format($weekLoads, 0, ',', '.') }} grup ilanı derlendi{{ $dailyAvg >= 10 ? ' · günde ≈ '.number_format($dailyAvg, 0, ',', '.') : '' }}
                    </div>
                @endif
                <div class="pt-1">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-4xl font-black text-brand-500 tabular-nums">{{ $priceText }} ₺</span>
                        <span class="text-xs text-neutral-400">/ ay, KDV dahil</span>
                    </div>
                    @if($perDay > 0)
                        <div class="text-2xs text-neutral-500 dark:text-neutral-400 mt-1">Günde ≈ {{ $perDay }} ₺ · tek bir yük aylık ücreti fazlasıyla karşılar</div>
                    @endif
                </div>
            </div>
            <ul class="space-y-3 text-xs text-neutral-700 dark:text-neutral-200 font-medium pt-5 border-t border-neutral-100 dark:border-neutral-800 flex-1">
                <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span><strong class="text-neutral-900 dark:text-white">Gruplardan derlenen tüm ilanlar</strong>, ilan bilgileriyle; yalnız Premium'da</span></li>
                <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Yeni NavlunIQ ilanlarını <strong class="text-neutral-900 dark:text-white">{{ $leadBefore }}</strong> görün, ilk teklifi siz verin</span></li>
                <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Aracınıza uygun ilan çıkınca <strong class="text-neutral-900 dark:text-white">anında bildirim</strong>, e-postanıza da gelir; siteyi açık tutmanız gerekmez</span></li>
                <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span><strong class="text-neutral-900 dark:text-white">Dönüş yükü radarı</strong>: teslimden sonra boş dönmeyin, uygun yük size haber verilir</span></li>
                <li class="flex items-start gap-2.5"><span class="text-brand-500 mt-px">{!! $check !!}</span><span>Sabit ücret; sevkiyat başına ek ödeme yok, {{ $autoRenew ? 'otomatik yenileme sizin seçiminiz, tek dokunuşla kapanır' : 'otomatik yenilenmez' }}{{ $maxDiscount > 0 ? '. 3, 6 ve 12 aylık seçeneklerde %'.rtrim(rtrim(number_format($maxDiscount, 1, ',', '.'), '0'), ',')."'e varan indirim" : '' }}</span></li>
            </ul>
            <div class="space-y-2">
                <a href="{{ $premiumHref }}" class="btn-apple-brand w-full py-3.5 text-xs font-bold">{{ $premiumCta }}</a>
                <p class="text-2xs text-center text-neutral-400 leading-relaxed">
                    @if($trialDays > 0)
                        Kart gerekmez · belgeleriniz onaylanınca kendiliğinden başlar · süre sonunda ücret alınmaz
                    @else
                        Premium, şoför panelinizdeki Premium sayfasından etkinleştirilir.
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- Standart: telefonda sonra, daha sade --}}
    <div class="order-2 md:order-1 apple-glass rounded-3xl p-7 sm:p-8 shadow-apple-sm flex flex-col gap-5">
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">STANDART</span>
                <span class="w-9 h-9 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-300 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </span>
            </div>
            <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Ücretsiz Şoför Hesabı</h3>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">Yükünü kendi hızında bulmak isteyenler için; istediğiniz zaman Premium'a geçersiniz.</p>
            <div class="pt-1 flex items-baseline gap-1.5">
                <span class="text-2xl font-black text-neutral-900 dark:text-white tabular-nums">0 ₺</span>
                <span class="text-xs text-neutral-400">/ süresiz</span>
            </div>
        </div>
        <ul class="space-y-3 text-xs text-neutral-600 dark:text-neutral-300 pt-5 border-t border-neutral-100 dark:border-neutral-800 flex-1">
            <li class="flex items-start gap-2.5"><span class="text-emerald-500 mt-px">{!! $check !!}</span><span>NavlunIQ ilanlarına sınırsız teklif verin</span></li>
            <li class="flex items-start gap-2.5"><span class="text-emerald-500 mt-px">{!! $check !!}</span><span>{{ $directPay ? 'Yük sahibiyle doğrudan iletişim, canlı konum ve teslim kanıtı' : 'Paranız güvende: teslim onaylanınca navlun hesabınıza geçer' }}</span></li>
            <li class="flex items-start gap-2.5 text-neutral-500 dark:text-neutral-400"><span class="text-neutral-400 mt-px">{!! $dash !!}</span><span>Gruplardan derlenen ilanlar yalnız Premium'da</span></li>
            <li class="flex items-start gap-2.5 text-neutral-500 dark:text-neutral-400"><span class="text-neutral-400 mt-px">{!! $dash !!}</span><span>NavlunIQ ilanları ve dönüş yükü radarı ilanları panelinize düşer, ancak bildirim gelmez</span></li>
            <li class="flex items-start gap-2.5 text-neutral-500 dark:text-neutral-400"><span class="text-neutral-400 mt-px">{!! $dash !!}</span><span>NavlunIQ ilanlarını {{ $lead > 0 ? $lead.' dakika sonra' : 'aynı anda' }} görür</span></li>
        </ul>
        <div class="space-y-2">
            <a href="{{ $freeHref }}" class="btn-apple-secondary w-full py-3.5 text-xs font-bold">{{ $isDriver ? 'Panelime git' : 'Ücretsiz başla' }}</a>
            <p class="text-2xs text-center text-neutral-400">Kayıt ve belge onayı ücretsizdir.</p>
        </div>
    </div>
</div>
