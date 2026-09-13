<!DOCTYPE html>
<html lang="tr" class="h-full bg-neutral-950 text-neutral-100 dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Yük Sahibi Paneli' }} | NavlunIQ Akıllı Lojistik Ağı</title>

    <!-- Tarayıcı Sekme İkonu (Favicon) -->
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="shortcut icon" href="/images/fav-ico.png">

    <!-- Google Fonts Inter Yazı Tipi -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    <!-- Projemizin Stil ve Script Dosyaları -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important;
        }

        body {
            font-family: 'Inter', sans-serif;
        }
    </style>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @livewireStyles
</head>

<body class="h-full bg-neutral-950 text-neutral-100 flex flex-col antialiased" x-data="{ mobileSidebarOpen: false }">

    <!-- Mobil Menü Karartması -->
    <div x-show="mobileSidebarOpen" x-cloak @click="mobileSidebarOpen = false"
        class="fixed inset-0 z-40 bg-neutral-950/80 backdrop-blur-sm md:hidden"></div>

    <!-- Kenar Çubuğu (Sidebar) -->
    <aside :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-64 bg-neutral-900 border-r border-neutral-800 flex flex-col transition-transform duration-300 ease-in-out md:translate-x-0">

        <!-- Üst Logo & Yük Sahibi Başlığı (Keskin & Prestijli Tasarım) -->
        <div class="h-20 flex items-center justify-between px-5 border-b border-neutral-800 bg-neutral-900/60">
            <a href="{{ route('cargo-owner.dashboard') }}" class="flex items-center space-x-3 group select-none">
                <!-- Sol: Turuncu Kargo Paketi İkon Kutusu -->
                <div
                    class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-brand-600 to-amber-500 flex items-center justify-center shadow-lg shadow-brand-500/25 group-hover:scale-105 transition-transform duration-300 flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>

                <!-- Sağ: Keskin NavlunIQ Yazısı ve Mavi Sinyalli YÜK SAHİBİ PANELİ -->
                <div class="flex flex-col">
                    <div class="text-base font-black tracking-tight text-white leading-none">
                        <span>Navlun</span><span class="text-brand-500">IQ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                        <span class="text-[9px] font-extrabold tracking-wider uppercase text-blue-400 leading-none">YÜK
                            SAHİBİ PANELİ</span>
                    </div>
                </div>
            </a>
            <button @click="mobileSidebarOpen = false" class="text-neutral-400 hover:text-white md:hidden">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Hızlı İlan Aç Butonu -->
        <div class="p-4">
            <a href="{{ route('cargo-owner.loads.create') }}"
                class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-sm transition-all duration-200 shadow-lg shadow-brand-500/20 active:scale-[0.98]">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>Yeni İlan Oluştur</span>
            </a>
        </div>

        <!-- Menü Linkleri -->
        <nav class="flex-1 px-3 space-y-1.5 overflow-y-auto">
            <a href="{{ route('cargo-owner.dashboard') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.dashboard') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                <span>Genel Bakış & Radar</span>
            </a>

            <a href="{{ route('cargo-owner.loads.index') }}"
                class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.loads.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <span>İlanlarım & Teklifler</span>
                </div>
            </a>

            <a href="{{ route('cargo-owner.shipments.index') }}"
                class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.shipments.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                    <span>Canlı Sevkiyatlarım</span>
                </div>
            </a>

            <a href="{{ route('cargo-owner.finance.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.finance.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span>Güvenli Havuz & Faturalar</span>
            </a>

            <a href="{{ route('cargo-owner.address-book.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.address-book.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <span>Kayıtlı Adres Defteri</span>
            </a>

            <a href="{{ route('cargo-owner.disputes.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.disputes.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>Uyuşmazlık & Krizler</span>
            </a>

            <a href="{{ route('cargo-owner.support.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.support.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span>Destek Biletleri</span>
            </a>

            <a href="{{ route('cargo-owner.profile.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('cargo-owner.profile.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span>Firma Profili & Güvenlik</span>
            </a>
        </nav>

        <!-- Alt Bölüm: Çift Rol Geçişi (ŞARTLI) & Çıkış -->
        <div class="p-4 border-t border-neutral-800 bg-neutral-900/80 space-y-3">
            <livewire:role-switcher />

            <div class="flex items-center justify-between pt-1">
                <div class="flex items-center space-x-3 overflow-hidden">
                    <div
                        class="w-8 h-8 rounded-full bg-neutral-800 border border-neutral-700 flex items-center justify-center font-bold text-neutral-300 text-xs shrink-0">
                        {{ strtoupper(substr(auth()->user()?->first_name ?? 'Y', 0, 1)) }}
                    </div>
                    <div class="truncate text-left">
                        <div class="text-xs font-semibold text-neutral-200 truncate">{{ auth()->user()?->full_name }}
                        </div>
                        <div class="text-[10px] text-neutral-500 truncate">
                            {{ auth()->user()?->phone ?? auth()->user()?->email }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Güvenli Çıkış"
                        class="p-1.5 text-neutral-500 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- Ana İçerik -->
    <div class="flex-1 md:pl-64 flex flex-col min-h-screen">

        <!-- Üst Başlık -->
        <header
            class="h-16 bg-neutral-900/60 backdrop-blur-md border-b border-neutral-800 sticky top-0 z-30 flex items-center justify-between px-4 md:px-8">
            <div class="flex items-center space-x-4">
                <button @click="mobileSidebarOpen = true"
                    class="text-neutral-400 hover:text-white md:hidden focus:outline-none">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <h1 class="text-lg font-bold text-white tracking-tight">{{ $title ?? 'Yük Sahibi Paneli' }}</h1>
            </div>

            <div class="flex items-center space-x-4">
                <div
                    class="hidden sm:flex items-center space-x-2 px-3 py-1.5 bg-emerald-500/10 border border-emerald-500/20 rounded-full text-emerald-400 text-xs font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>PayTR Escrow Korumalı</span>
                </div>

                <a href="{{ route('home') }}" target="_blank"
                    class="text-neutral-400 hover:text-white text-xs font-medium flex items-center space-x-1.5 px-3 py-1.5 rounded-lg border border-neutral-800 hover:bg-neutral-800/60 transition-colors">
                    <span>Siteye Git</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>
            </div>
        </header>

        <!-- Dinamik Sayfa İçeriği -->
        <main class="flex-1 p-4 md:p-8 max-w-7xl w-full mx-auto">
            {{ $slot }}
        </main>

        <!-- Footer -->
        <footer class="py-4 px-8 border-t border-neutral-900 text-center text-xs text-neutral-500 bg-neutral-950">
            &copy; 2026 NavlunIQ Akıllı Lojistik Ağı. Tüm Hakları Saklıdır.
        </footer>
    </div>

    @livewireScripts
</body>

</html>
