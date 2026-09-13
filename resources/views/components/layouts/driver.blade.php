<!DOCTYPE html>
<html lang="tr" class="h-full bg-neutral-950 text-neutral-100 dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Şoför Paneli' }} | NavlunIQ Akıllı Lojistik Ağı</title>

    <!-- Tarayıcı Sekme İkonu (Favicon) -->
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="shortcut icon" href="/images/fav-ico.png">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important;
        }

        body {
            font-family: 'Inter Variable', 'Inter', system-ui, sans-serif;
        }
    </style>
    @livewireStyles
</head>

<body class="h-full bg-neutral-950 text-neutral-100 flex flex-col antialiased" x-data="{ mobileSidebarOpen: false }">

    <!-- Mobil Menü Karartması -->
    <div x-show="mobileSidebarOpen" x-cloak @click="mobileSidebarOpen = false"
        class="fixed inset-0 z-40 bg-neutral-950/80 backdrop-blur-sm md:hidden"></div>

    <!-- Kenar Çubuğu (Sidebar) -->
    <aside :class="{ 'translate-x-0': mobileSidebarOpen, '-translate-x-full': !mobileSidebarOpen }"
        class="-translate-x-full fixed inset-y-0 left-0 z-50 w-64 bg-neutral-900 border-r border-neutral-800 flex flex-col transition-transform duration-300 ease-in-out md:translate-x-0">

        <!-- Üst Logo & Şoför Paneli Başlığı (Keskin & Prestijli Tasarım) -->
        <div class="h-20 flex items-center justify-between px-5 border-b border-neutral-800 bg-neutral-900/60">
            <a href="{{ route('driver.dashboard') }}" class="flex items-center space-x-3 group select-none">
                <!-- Sol: Turuncu Tır İkon Kutusu -->
                <div
                    class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-brand-600 to-amber-500 flex items-center justify-center shadow-lg shadow-brand-500/25 group-hover:scale-105 transition-transform duration-300 flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                </div>

                <!-- Sağ: Keskin NavlunIQ Yazısı ve Canlı Yeşil Sinyalli ŞOFÖR PANELİ -->
                <div class="flex flex-col">
                    <div class="text-base font-black tracking-tight text-white leading-none">
                        <span>Navlun</span><span class="text-brand-500">IQ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span
                            class="text-[9px] font-extrabold tracking-wider uppercase text-emerald-400 leading-none">ŞOFÖR
                            PANELİ</span>
                    </div>
                </div>
            </a>
            <button @click="mobileSidebarOpen = false" class="text-neutral-400 hover:text-white md:hidden">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="p-4">
            <a href="{{ route('driver.loads.index') }}"
                class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm transition-all duration-200 shadow-lg shadow-brand-500/20 active:scale-[0.98]">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <span>İlan Havuzu</span>
            </a>
        </div>

        <!-- Menü Linkleri -->
        <nav class="flex-1 px-3 space-y-1.5 overflow-y-auto">
            <a href="{{ route('driver.dashboard') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.dashboard') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <span>Genel Bakış</span>
            </a>

            <a href="{{ route('driver.loads.index') }}"
                class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.loads.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <span>İlan Havuzu & Tekliflerim</span>
                </div>
            </a>

            <a href="{{ route('driver.shipments.index') }}"
                class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.shipments.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                    <span>Canlı Teslimat & Navigasyon</span>
                </div>
            </a>

            <a href="{{ route('driver.wallet.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.wallet.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span>Cüzdan & Hak Edişlerim</span>
            </a>

            <a href="{{ route('driver.premium.index') }}"
                class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.premium.*') ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                    </svg>
                    <span>Premium Abonelik</span>
                </div>
                <span class="px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-extrabold">900
                    ₺/ay</span>
            </a>

            <a href="{{ route('driver.vehicles.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.vehicles.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
                <span>Filom & Araçlarım</span>
            </a>

            <a href="{{ route('driver.disputes.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.disputes.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>Uyuşmazlık & Savunma</span>
            </a>

            <a href="{{ route('driver.profile.index') }}"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('driver.profile.*') ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20 font-bold' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/60' }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span>Profil & Akıllı Filtreler</span>
            </a>
        </nav>

        <!-- Alt Bölüm: Çift Rol Geçişi (ŞARTLI) & Çıkış -->
        <div class="p-4 border-t border-neutral-800 bg-neutral-900/80 space-y-3">
            <livewire:role-switcher />

            <div class="flex items-center justify-between pt-1">
                <div class="flex items-center space-x-3 overflow-hidden">
                    <div
                        class="w-8 h-8 rounded-full bg-brand-500/10 border border-brand-500/20 flex items-center justify-center font-bold text-brand-400 text-xs shrink-0">
                        {{ strtoupper(substr(auth()->user()?->first_name ?? 'S', 0, 1)) }}
                    </div>
                    <div class="truncate text-left">
                        <div class="text-xs font-semibold text-neutral-200 truncate">{{ auth()->user()?->full_name }}
                        </div>
                        <div class="text-[10px] text-neutral-500 truncate">
                            {{ \App\Support\Phone::format(auth()->user()?->phone) ?: auth()->user()?->email }}</div>
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

        <!-- Üst Başlık & Dinamik Araç Rozeti -->
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
                <h1 class="text-lg font-bold text-white tracking-tight">{{ $title ?? 'Şoför Paneli' }}</h1>
            </div>

            <div class="flex items-center space-x-4">
                @php
                    $activeVehicle = auth()->user()?->driverProfile?->activeVehicle ?? auth()->user()?->driverProfile?->vehicles()?->where('is_active', true)->first();
                @endphp
                <div
                    class="hidden sm:flex items-center space-x-2 px-3 py-1.5 bg-neutral-800 border border-neutral-700 rounded-full text-xs font-mono font-bold text-white">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ $activeVehicle ? $activeVehicle->plate.' ('.$activeVehicle->brand.' '.$activeVehicle->model.')' : 'Aktif araç tanımlı değil' }}</span>
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
            &copy; 2026 NavlunIQ Akıllı Lojistik Ağı. Şoför Mobil Komuta Merkezi.
        </footer>
    </div>

    @livewireScripts
</body>

</html>
