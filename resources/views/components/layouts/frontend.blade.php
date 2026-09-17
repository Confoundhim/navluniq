@props(['title' => 'NavlunIQ - Akıllı Lojistik Ağı'])
@php
    $whatsappNumber = preg_replace('/\D/', '', (string) \App\Models\CmsContent::getVal('contact_whatsapp', config('company.phone')));
    $whatsappNumber = $whatsappNumber !== '' ? (str_starts_with($whatsappNumber, '90') ? $whatsappNumber : '90'.ltrim($whatsappNumber, '0')) : null;
@endphp
<!DOCTYPE html>
<html lang="tr" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f97316">
    <title>{{ $title }}</title>
    <script>
        // Tema tercihini Alpine yüklenmeden uygular; açılışta beyaz yanıp sönmeyi önler.
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
                if (localStorage.getItem('textSize') === 'large') {
                    document.documentElement.classList.add('text-large');
                }
            } catch (e) {}
        })();
    </script>

    <!-- Tarayıcı Sekme İkonu (Favicon) -->
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="shortcut icon" href="/images/fav-ico.png">

        @vite(['resources/css/app.css', 'resources/js/app.js'])


    <style>
        [x-cloak] { display: none !important; }
    </style>
    @livewireStyles
</head>

<body
    class="min-h-screen bg-neutral-50 dark:bg-neutral-950 text-neutral-900 dark:text-neutral-100 transition-colors duration-300 font-sans antialiased selection:bg-brand-500 selection:text-white flex flex-col justify-between"
    x-data="{ mobileNav: false }">

    <!-- ÜST MENÜ NAVBAR -->
    <header
        class="apple-glass sticky top-0 z-50 px-6 md:px-12 py-3.5 flex justify-between items-center transition-all duration-300 border-b border-neutral-200/60 dark:border-neutral-800/60 shadow-apple-sm">

        <!-- Sol: Logo ve Logo Genişliğine Tam Simetrik İnce Alt Slogan -->
        <a href="{{ route('home') }}" class="flex flex-col items-center justify-center group select-none">
            <!-- Açık Tema Logosu (Koyu Yazılı: logo-dark.png) -->
            <img src="/images/logo-dark.png"
                 alt="NavlunIQ Logo"
                 x-show="!$store.darkMode.on"
                 class="h-7 sm:h-8 w-auto transition-transform duration-300 group-hover:scale-105">

            <!-- Koyu Tema Logosu (Beyaz Yazılı: logo-white.png) -->
            <img src="/images/logo-white.png"
                 alt="NavlunIQ Logo"
                 x-show="$store.darkMode.on"
                 x-cloak
                 style="display: none;"
                 class="h-7 sm:h-8 w-auto transition-transform duration-300 group-hover:scale-105">

            <!-- Logo ile Aynı Genişlikte Milimetrik Simetrik İnce Alt Slogan -->
            <span class="text-[8px] sm:text-[9px] font-light tracking-[0.26em] text-neutral-500 dark:text-neutral-400 mt-1 w-full text-center block leading-none">
                Akıllı Lojistik Ağı
            </span>
        </a>

        <!-- Orta: Menü Elemanları -->
        <nav class="hidden lg:flex items-center space-x-1 text-xs font-semibold text-neutral-600 dark:text-neutral-300">
            <a href="{{ route('home') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('home') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">Anasayfa</a>
            <a href="{{ route('about') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('about') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">Hakkımızda</a>
            <a href="{{ route('subscription') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('subscription') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">Abonelik
                Sistemi</a>
            <a href="{{ route('services') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('services') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">Hizmetlerimiz</a>
            <a href="{{ route('how-it-works') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('how-it-works') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">Nasıl
                Çalışır?</a>
            <a href="{{ route('contact') }}"
                class="px-3.5 py-2 rounded-xl hover:text-brand-500 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 transition-all {{ request()->routeIs('contact') ? 'text-brand-500 font-bold bg-brand-500/5' : '' }}">İletişim</a>
        </nav>

        <!-- Sağ: Giriş / Panelim Butonu + Tema Değiştirici -->
        <div class="hidden sm:flex items-center space-x-3 text-xs">
            <button @click="$store.textSize.toggle()" :class="$store.textSize.large ? 'text-brand-500' : 'text-neutral-600 dark:text-neutral-300'"
                class="p-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:scale-105 transition-all focus:outline-none"
                title="Yazı boyutu" aria-label="Yazı boyutunu değiştir">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 18.5l5-13 5 13M4.8 13.5h5.4M13.5 18.5l3-8 3 8M14.8 15.8h3.4"/></svg>
            </button>
            <button @click="$store.darkMode.toggle()"
                class="p-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300 hover:scale-105 transition-all focus:outline-none"
                title="Temayı Değiştir">
                <svg x-show="!$store.darkMode.on" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                </svg>
                <svg x-show="$store.darkMode.on" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
            </button>

            @auth
                <a href="{{ route('panel') }}" class="btn-apple-brand py-2 px-4 font-bold shadow-apple-sm flex items-center gap-2">
                    <span>Panelime Git</span>
                    <span>&rarr;</span>
                </a>
            @else
                <a href="{{ route('login') }}"
                    class="px-3.5 py-2 font-bold text-neutral-700 dark:text-neutral-200 hover:text-brand-500 transition-colors">
                    Giriş Yap
                </a>

                <a href="{{ route('register.driver') }}" class="btn-apple-secondary py-2 px-3.5 font-bold shadow-apple-sm">
                    Şoför Olarak Katıl
                </a>

                <a href="{{ route('register.cargo-owner') }}" class="btn-apple-brand py-2 px-4 font-bold shadow-apple-sm">
                    Yük Sahibi Olarak Katıl
                </a>
            @endauth
        </div>

        <!-- Mobil Menü Butonu -->
        <button @click="mobileNav = !mobileNav"
            class="lg:hidden p-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </header>

    <!-- MOBİL MENÜ DRAWER -->
    <div x-show="mobileNav" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm lg:hidden" @click="mobileNav = false"
        style="display: none;">
        <div class="w-4/5 max-w-sm bg-white dark:bg-neutral-900 h-full p-6 space-y-6 flex flex-col justify-between shadow-apple-dark"
            @click.stop>
            <div class="space-y-6">
                <div class="flex justify-between items-center pb-4 border-b border-neutral-100 dark:border-neutral-800">
                    <div class="flex flex-col items-start">
                        <img src="/images/logo-dark.png" alt="NavlunIQ" x-show="!$store.darkMode.on" class="h-6 w-auto">
                        <img src="/images/logo-white.png" alt="NavlunIQ" x-show="$store.darkMode.on" x-cloak style="display: none;" class="h-6 w-auto">
                        <span class="text-[7.5px] font-light tracking-[0.24em] uppercase text-neutral-500 dark:text-neutral-400 mt-0.5">
                            Akıllı Lojistik Ağı
                        </span>
                    </div>
                    <button @click="mobileNav = false" class="p-1.5 text-neutral-400 hover:text-neutral-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <nav class="flex flex-col space-y-3 text-sm font-semibold">
                    <a href="{{ route('home') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">Anasayfa</a>
                    <a href="{{ route('about') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">Hakkımızda</a>
                    <a href="{{ route('subscription') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">Abonelik Sistemi</a>
                    <a href="{{ route('services') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">Hizmetlerimiz</a>
                    <a href="{{ route('how-it-works') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">Nasıl Çalışır?</a>
                    <a href="{{ route('contact') }}" @click="mobileNav = false"
                        class="p-2.5 rounded-xl hover:bg-neutral-100 dark:hover:bg-neutral-800">İletişim</a>
                </nav>
            </div>
            <div class="space-y-3 pt-4 border-t border-neutral-100 dark:border-neutral-800">
                @auth
                    <a href="{{ route('panel') }}" @click="mobileNav = false"
                        class="w-full btn-apple-brand py-3 text-center text-xs font-bold block">Panelime Git &rarr;</a>
                @else
                    <a href="{{ route('login') }}" @click="mobileNav = false"
                        class="w-full btn-apple-secondary py-3 text-center text-xs font-bold block">Giriş Yap</a>
                    <a href="{{ route('register.driver') }}" @click="mobileNav = false"
                        class="w-full btn-apple-secondary py-3 text-center text-xs font-bold block">Şoför Olarak Katıl</a>
                    <a href="{{ route('register.cargo-owner') }}" @click="mobileNav = false"
                        class="w-full btn-apple-brand py-3 text-center text-xs font-bold block">Yük Sahibi Olarak Katıl</a>
                @endauth
            </div>
        </div>
    </div>

    <!-- SAYFA ANA İÇERİĞİ -->
    <main class="flex-1 pt-8 pb-20 md:pt-12 md:pb-28">
        {{ $slot }}
    </main>

    @if($whatsappNumber)
    <div class="fixed bottom-6 right-6 z-50 flex items-center group" style="bottom: calc(1.5rem + env(safe-area-inset-bottom));">
        <span
            class="hidden sm:inline-block mr-3 px-3 py-1.5 bg-neutral-900 text-white text-xs font-bold rounded-xl shadow-apple-lg opacity-0 group-hover:opacity-100 transition-all duration-300 translate-x-2 group-hover:translate-x-0">
            WhatsApp Destek Hattı
        </span>
        <a href="https://wa.me/{{ $whatsappNumber }}?text={{ urlencode('Merhaba, NavlunIQ hakkında bilgi almak istiyorum.') }}"
            target="_blank"
            class="w-14 h-14 bg-emerald-500 hover:bg-emerald-600 text-white rounded-full flex items-center justify-center shadow-apple-dark hover:scale-110 active:scale-95 transition-all duration-300 relative">
            <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                <path
                    d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
            </svg>
            <span class="absolute -top-1 -right-1 flex h-4 w-4">
                <span
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span
                    class="relative inline-flex rounded-full h-4 w-4 bg-emerald-500 border-2 border-white dark:border-neutral-900"></span>
            </span>
        </a>
    </div>
    @endif

    <!-- Footer -->
    <footer
        class="bg-white dark:bg-neutral-900 border-t border-neutral-200/80 dark:border-neutral-800/80 pt-16 pb-12 px-6 md:px-12 text-xs transition-colors duration-300">
        <div
            class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 pb-12 border-b border-neutral-100 dark:border-neutral-800/60">

            <!-- 1. Sütun: YALNIZCA NİQ SEMBOL LOGOSU VE ETBİS ALANI -->
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center">
                    <img src="/images/dark-symbol-logo.png"
                         onerror="this.src='/images/logo-symbol.png'"
                         alt="NavlunIQ NIQ Logo"
                         x-show="!$store.darkMode.on"
                         class="h-11 sm:h-12 w-auto">

                    <img src="/images/white-symbol-logo.png"
                         onerror="this.src='/images/logo-symbol.png'"
                         alt="NavlunIQ NIQ Logo"
                         x-show="$store.darkMode.on"
                         x-cloak
                         style="display: none;"
                         class="h-11 sm:h-12 w-auto">
                </div>

                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed max-w-sm text-xs">
                    {{ \App\Models\CmsContent::getVal('footer_slogan', 'Lojistikte güvenli, akıllı taşımacılık ekosistemi.') }}
                </p>

                @if($etbis = \App\Models\CmsContent::getVal('etbis_code'))
                <div class="pt-1">
                    <div
                        class="inline-flex items-center space-x-2 p-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800/60 border border-neutral-200/50 dark:border-neutral-700/50 font-mono text-[10px] text-neutral-600 dark:text-neutral-300">
                        <svg class="w-4 h-4 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span>ETBİS Kayıtlı İşletme: {{ $etbis }}</span>
                    </div>
                </div>
                @endif
            </div>

            <!-- 2. Sütun: Keşfet -->
            <div class="space-y-3">
                <h4 class="font-bold text-neutral-900 dark:text-white uppercase tracking-wider text-[11px]">Keşfet</h4>
                <ul class="space-y-2 text-neutral-500 dark:text-neutral-400 font-medium">
                    <li><a href="{{ route('about') }}" class="hover:text-brand-500 transition-colors">Hakkımızda</a></li>
                    <li><a href="{{ route('for-cargo-owners') }}" class="hover:text-brand-500 transition-colors">Yük Sahibi Misiniz?</a></li>
                    <li><a href="{{ route('for-drivers') }}" class="hover:text-brand-500 transition-colors">Şoför Müsünüz?</a></li>
                    <li><a href="{{ route('subscription') }}" class="hover:text-brand-500 transition-colors">Abonelik Sistemi</a></li>
                    <li><a href="{{ route('services') }}" class="hover:text-brand-500 transition-colors">Hizmetlerimiz</a></li>
                    <li><a href="{{ route('how-it-works') }}" class="hover:text-brand-500 transition-colors">Nasıl Çalışır?</a></li>
                </ul>
            </div>

            <!-- 3. Sütun: Destek -->
            <div class="space-y-3">
                <h4 class="font-bold text-neutral-900 dark:text-white uppercase tracking-wider text-[11px]">Destek</h4>
                <ul class="space-y-2 text-neutral-500 dark:text-neutral-400 font-medium">
                    <li><a href="/#sss" class="hover:text-brand-500 transition-colors">Sıkça Sorulan Sorular</a></li>
                    <li><a href="{{ route('contact') }}" class="hover:text-brand-500 transition-colors">Müşteri Hizmetleri</a></li>
                    @if($whatsappNumber)<li><a href="https://wa.me/{{ $whatsappNumber }}" target="_blank" rel="noopener" class="hover:text-brand-500 transition-colors">WhatsApp Destek Hattı</a></li>@endif
                    @if(config('company.email'))<li><a href="mailto:{{ config('company.email') }}" class="hover:text-brand-500 transition-colors">{{ config('company.email') }}</a></li>@endif
                    <li><a href="{{ route('contact') }}" class="hover:text-brand-500 transition-colors">İletişim Formu</a></li>
                </ul>
            </div>

            <!-- 4. Sütun: Yasal Sözleşmeler -->
            <div class="space-y-3">
                <h4 class="font-bold text-neutral-900 dark:text-white uppercase tracking-wider text-[11px]">Yasal Sözleşmeler</h4>
                <ul class="space-y-2 text-neutral-500 dark:text-neutral-400 font-medium">
                    <li><a href="{{ route('contracts', 'kvkk') }}" class="hover:text-brand-500 transition-colors">KVKK Aydınlatma Metni</a></li>
                    <li><a href="{{ route('contracts', 'kullanici-sozlesmesi') }}" class="hover:text-brand-500 transition-colors">Kullanıcı Sözleşmesi</a></li>
                    <li><a href="{{ route('contracts', 'gizlilik-politikasi') }}" class="hover:text-brand-500 transition-colors">Gizlilik Politikası</a></li>
                    <li><a href="{{ route('contracts', 'mesafeli-satis') }}" class="hover:text-brand-500 transition-colors">Mesafeli Satış Sözleşmesi</a></li>
                    <li><a href="{{ route('contracts', 'iade-politikasi') }}" class="hover:text-brand-500 transition-colors">İade Politikası</a></li>
                </ul>
            </div>

        </div>

        <!-- Alt Telif & Sosyal Medya -->
        <div class="max-w-7xl mx-auto pt-8 flex flex-col md:flex-row justify-between items-center gap-4 text-neutral-400 text-[11px]">
            <div>© {{ date('Y') }} NavlunIQ. Tüm Hakları Saklıdır.</div>
            <div class="flex items-center space-x-4">
                @if($ig = \App\Models\CmsContent::getVal('social_instagram'))<a href="{{ $ig }}" target="_blank" rel="noopener" class="hover:text-brand-500 transition-colors">Instagram</a>@endif
                <span>•</span>
                @if($w = \App\Models\CmsContent::getVal('social_whatsapp'))<a href="{{ $w }}" target="_blank" rel="noopener" class="hover:text-emerald-500 transition-colors">WhatsApp</a>@endif
                <span>•</span>
                @if($tg = \App\Models\CmsContent::getVal('social_telegram'))<a href="{{ $tg }}" target="_blank" rel="noopener" class="hover:text-blue-500 transition-colors">Telegram</a>@endif
            </div>
        </div>
    </footer>

    @livewireScripts
</body>

</html>
