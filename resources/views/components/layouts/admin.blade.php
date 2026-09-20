@props(['title' => 'NavlunIQ SaaS Yönetim Paneli'])
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
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
    <title>{{ $title }}</title>

    <!-- Tarayıcı Sekme İkonu (Favicon) -->
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="shortcut icon" href="/images/fav-ico.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <!-- Sayfa özelinde kafa (head) kısmına eklenecek ekstra scriptler için slot -->
    {{ $head ?? '' }}
</head>

<body
    class="min-h-screen bg-neutral-100 dark:bg-neutral-950 text-neutral-900 dark:text-neutral-100 transition-colors duration-300 font-sans antialiased"
    x-data="{ sidebarOpen: false }">

    <div class="min-h-screen bg-neutral-100 dark:bg-neutral-950 relative">

        <!-- MOBİL ARKA PLAN KARARTMASI (Backdrop) -->
        <div x-show="sidebarOpen" @click="sidebarOpen = false" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm md:hidden"
            style="display: none;"></div>

        <!-- ========================================================= -->
        <!-- SOL DİKEY MENÜ (SOLID & HIGH-CONTRAST SIDEBAR - 256px / w-64) -->
        <!-- ========================================================= -->
        <aside
            class="w-64 bg-white dark:bg-neutral-900 border-r border-neutral-200/80 dark:border-neutral-800/80 flex flex-col justify-between fixed inset-y-0 left-0 z-50 transition-transform duration-300 ease-apple-ease no-print shadow-apple-md"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'">

            <!-- Üst Kısım: Logo ve Kategori Menüleri -->
            <div class="flex flex-col h-full min-h-0">

                <!-- Marka Logosu (Açık/Koyu Tema Uyumlu) -->
                <div
                    class="p-5 border-b border-neutral-100 dark:border-neutral-800/60 flex items-center justify-between">
                    <a href="{{ route('admin.dashboard') }}"
                        class="flex flex-col items-start group select-none transition-transform duration-300 ease-apple-ease hover:scale-[1.03]">
                        <div class="flex items-center space-x-2">
                            <!-- Açık Tema Logosu -->
                            <img src="/images/logo-dark.png"
                                 alt="NavlunIQ Admin"
                                 class="h-6 w-auto block dark:hidden transition-transform duration-300 group-hover:scale-105">

                            <!-- Koyu Tema Logosu -->
                            <img src="/images/logo-white.png"
                                 alt="NavlunIQ Admin"
                                 class="h-6 w-auto hidden dark:block transition-transform duration-300 group-hover:scale-105">

                            <span class="text-[9px] uppercase tracking-wider bg-brand-500/10 text-brand-500 px-2 py-0.5 rounded-full font-extrabold">Admin</span>
                        </div>
                        <span class="text-[9px] font-light tracking-[0.24em] text-neutral-400 dark:text-neutral-500 mt-1">
                            Yönetici Paneli
                        </span>
                    </a>

                    <!-- Mobilde Kapatma Butonu -->
                    <button @click="sidebarOpen = false" class="md:hidden text-neutral-400 hover:text-neutral-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @php
                    $panelUser = Auth::user();
                    $roleLabels = [
                        'super_admin' => 'Süper Yönetici',
                        'kyc_validator' => 'KYC Doğrulayıcı',
                        'financial_officer' => 'Finans Sorumlusu',
                        'support_agent' => 'Destek Temsilcisi',
                    ];
                    $roleText = $panelUser?->getRoleNames()->map(fn ($r) => $roleLabels[$r] ?? $r)->join(', ') ?: 'Rol atanmadı';
                    $navGroups = [
                        'GÖSTERGE PANELİ' => [
                            ['route' => 'admin.dashboard', 'label' => 'Özet', 'can' => [], 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
                            ['route' => 'admin.notifications', 'label' => 'Bildirimler', 'can' => [], 'icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
                        ],
                        'SAHA VE OPERASYON' => [
                            ['route' => 'admin.users', 'label' => 'Kullanıcılar', 'can' => ['view users'], 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
                            ['route' => 'admin.kyc', 'label' => 'KYC Evrak Merkezi', 'can' => ['view users'], 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                            ['route' => 'admin.operations', 'label' => 'Operasyonlar', 'can' => ['view operations'], 'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7'],
                            ['route' => 'admin.disputes', 'label' => 'Uyuşmazlık ve Destek', 'can' => ['manage disputes', 'manage support tickets'], 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
                        ],
                        'KAYNAKLAR VE PAZARLAMA' => [
                            ['route' => 'admin.scrapers', 'label' => 'Dış Kaynak İlanları', 'can' => ['manage scrapers'], 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
                            ['route' => 'admin.crm', 'label' => 'Pazarlama ve CRM', 'can' => ['manage marketing'], 'icon' => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z'],
                        ],
                        'YÖNETİM VE SİSTEM' => [
                            ['route' => 'admin.finance', 'label' => 'Finans ve Muhasebe', 'can' => ['view financials'], 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                            ['route' => 'admin.cms', 'label' => 'İçerik ve CMS', 'can' => ['manage cms'], 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                            ['route' => 'admin.languages', 'label' => 'Çoklu Dil', 'can' => ['manage cms'], 'icon' => 'M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129'],
                            ['route' => 'admin.staff', 'label' => 'Personel ve İzinler', 'can' => ['manage staff'], 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                            ['route' => 'admin.rollback', 'label' => 'Geri Yükleme', 'can' => ['manage settings'], 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                            ['route' => 'admin.health', 'label' => 'Sistem Sağlığı', 'can' => ['manage settings'], 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                            ['route' => 'admin.firewall', 'label' => 'Güvenlik Duvarı', 'can' => ['manage settings'], 'icon' => 'M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016zM12 9v2m0 4h.01'],
                            ['route' => 'admin.settings', 'label' => 'Sistem Ayarları', 'can' => ['manage settings'], 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                        ],
                    ];
                @endphp

                <!-- Menü: her bağlantı ilgili bileşenin mount() kontrolüyle aynı izne bağlıdır -->
                <div class="p-4 space-y-6 flex-1 min-h-0 overflow-y-auto scrollbar-thin text-xs">
                    @foreach($navGroups as $groupTitle => $items)
                        @php
                            $visibleItems = array_values(array_filter($items, fn ($item) => $item['can'] === [] || $panelUser?->canAny($item['can'])));
                        @endphp
                        @if($visibleItems !== [])
                            <div class="space-y-1">
                                <span class="px-3 text-[10px] font-extrabold text-neutral-400 dark:text-neutral-500 uppercase tracking-widest block mb-2">{{ $groupTitle }}</span>
                                @foreach($visibleItems as $item)
                                    <a href="{{ route($item['route']) }}"
                                        class="flex items-center space-x-3 px-3 py-2.5 rounded-xl transition-all duration-200 {{ request()->routeIs($item['route']) ? 'bg-brand-500/10 text-brand-600 dark:text-brand-400 font-bold border-l-4 border-brand-500 shadow-apple-sm' : 'text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800/60 font-medium' }}">
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}" />
                                        </svg>
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>

                <!-- Alt Kısım: Giriş Yapan Personel Bilgisi -->
                <div class="p-3 shrink-0 border-t border-neutral-100 dark:border-neutral-800/60">
                    <div class="rounded-2xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-800 p-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 shrink-0 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400 flex items-center justify-center font-bold text-sm">
                                {{ mb_strtoupper(mb_substr($panelUser?->first_name ?? 'P', 0, 1)) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-neutral-900 dark:text-white truncate">{{ $panelUser?->full_name ?? 'Personel' }}</div>
                                <div class="text-xs text-neutral-500 truncate" title="{{ $roleText }}">{{ $roleText }}</div>
                            </div>
                            <form action="{{ route('admin.logout') }}" method="POST" class="shrink-0">
                                @csrf
                                <button type="submit" title="Çıkış yap" class="p-2 rounded-lg text-neutral-500 hover:text-rose-500 hover:bg-rose-500/10 transition-colors" aria-label="Çıkış yap">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </aside>

        <!-- ========================================================= -->
        <!-- SAĞ TARAF: ÜST HEADER BAR VE SAYFA İÇERİĞİ AREA -->
        <!-- ========================================================= -->
        <div class="pl-0 md:pl-64 min-h-screen flex flex-col flex-1 min-w-0 max-w-full overflow-x-hidden">

            <!-- Üst Header Bar -->
            <header
                class="h-16 shrink-0 bg-white/80 dark:bg-neutral-900/70 backdrop-blur-md border-b border-neutral-200 dark:border-neutral-800 px-4 md:px-8 flex justify-between items-center gap-3 sticky top-0 z-40 no-print">

                <!-- Mobil Cihazlar İçin Sidebar Açma Butonu ve Ferah Ekmek Kırıntısı (Breadcrumb) Başlığı -->
                <div class="flex items-center space-x-4">
                    <button @click="sidebarOpen = !sidebarOpen"
                        class="md:hidden p-2 -ml-2 rounded-lg text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800" aria-label="Menüyü aç">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div class="flex items-center gap-2 min-w-0">
                        <span class="hidden sm:inline text-xs text-neutral-400 font-medium">Yönetim</span>
                        <span class="hidden sm:inline text-neutral-300 dark:text-neutral-700">/</span>
                        <h2 class="text-base md:text-lg font-semibold text-neutral-900 dark:text-white truncate">{{ $title }}</h2>
                    </div>
                </div>

                <!-- Sağ Taraf Kontrolleri (Tema Değiştirici ve Siteye Git) -->
                <div class="flex items-center space-x-3">
                    <livewire:notifications.bell index-route="admin.notifications" />
                    <button @click="$store.textSize.toggle()" :class="$store.textSize.large ? 'text-brand-500 bg-brand-500/10' : 'text-neutral-600 dark:text-neutral-300'"
                        class="p-2 rounded-lg hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors" title="Yazı boyutu" aria-label="Yazı boyutunu değiştir">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 18.5l5-13 5 13M4.8 13.5h5.4M13.5 18.5l3-8 3 8M14.8 15.8h3.4"/></svg>
                    </button>
                    <button @click="$store.darkMode.toggle()"
                        class="p-2 rounded-lg text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors" title="Temayı değiştir" aria-label="Temayı değiştir">
                        <svg x-show="!$store.darkMode.on" class="w-4 h-4" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                        </svg>
                        <svg x-show="$store.darkMode.on" class="w-4 h-4" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24" style="display: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>

                    <a href="{{ route('home') }}" target="_blank" rel="noopener" class="hidden sm:inline-flex btn-secondary py-2 px-3 text-xs">
                        <span>Siteye git</span>
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </div>
            </header>

            <!-- KUSURSUZ İÇERİK ALANI -->
            <main class="flex-1 p-4 md:p-8 w-full max-w-7xl mx-auto space-y-8">
                {{ $slot }}
            </main>

        </div>

    </div>

    @livewireScripts
</body>

</html>
