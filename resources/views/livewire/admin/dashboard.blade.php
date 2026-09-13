<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NavlunIQ Admin - Test Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body
    class="min-h-screen bg-neutral-100 dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100 transition-colors duration-300"
    x-data>

    <!-- Üst Bar / Navbar -->
    <header class="apple-glass sticky top-0 z-50 px-6 py-4 flex justify-between items-center">
        <!-- Sol Taraf - Navigasyon Linkleri -->
        <div class="flex items-center space-x-6">
            <div class="flex items-center space-x-2 text-xl font-bold tracking-tight">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
                <span class="text-xs bg-brand-500/10 text-brand-500 px-2 py-0.5 rounded-full font-semibold">Admin
                    Panel</span>
            </div>
            <nav
                class="hidden md:flex items-center space-x-1 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                <a href="{{ route('admin.dashboard') }}"
                    class="px-3 py-1.5 rounded-lg bg-neutral-200/70 dark:bg-neutral-800/70 text-neutral-900 dark:text-white transition-colors">Özet</a>
                <a href="{{ route('admin.kyc') }}"
                    class="px-3 py-1.5 rounded-lg hover:bg-neutral-200/50 dark:hover:bg-neutral-800/50 transition-colors">KYC
                    Evrak Merkezi</a>
                <a href="{{ route('admin.operations') }}"
                    class="px-3 py-1.5 rounded-lg hover:bg-neutral-200/50 dark:hover:bg-neutral-800/50 transition-colors">Operasyonlar
                    & Radar</a>
            </nav>
        </div>

        <!-- Sağ Taraf Kontrolleri -->
        <div class="flex items-center space-x-4">
            <button @click="$store.darkMode.toggle()"
                class="p-2 rounded-full hover:bg-neutral-200/50 dark:hover:bg-neutral-800/50 transition-colors duration-300 text-neutral-500">
                <svg x-show="!$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                </svg>
                <svg x-show="$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
            </button>

            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn-apple-secondary text-xs py-1.5 px-3">Güvenli Çıkış</button>
            </form>
        </div>
    </header>

    <!-- Ana Panel İçeriği -->
    <main class="max-w-4xl mx-auto py-12 px-6 space-y-6 animate-fade-in">

        <!-- Karşılama Kartı -->
        <div class="apple-glass p-8 rounded-3xl space-y-4">
            <div class="flex items-center space-x-3 text-emerald-500">
                <div class="p-2 bg-emerald-500/10 rounded-full">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold">Admin Güvenlik Kapısı Başarıyla Aşıldı</h1>
            </div>

            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                NavlunIQ SaaS projesinin <strong>Admin Yönetici Girişi ve OTP Doğrulama Sistemi</strong> yerel ortamda
                kusursuz bir şekilde çalışmaktadır.
                Sistem, giren kullanıcının parolasını doğrulamış, ona özel 5 dakika süreli bir OTP üretmiş ve
                yetkilendirmesi doğrulanarak bu güvenli test paneline aktarmıştır.
            </p>

            <!-- Hızlı Geçiş Butonları -->
            <div
                class="pt-4 border-t border-neutral-100 dark:border-neutral-800/50 flex justify-between items-center gap-4">
                <a href="{{ route('admin.kyc') }}" class="btn-apple-secondary py-2 px-4 text-xs font-semibold">
                    ← KYC Merkezine Git
                </a>
                <a href="{{ route('admin.operations') }}" class="btn-apple-brand py-2 px-4 text-xs font-semibold">
                    Operasyonlar & Canlı Radara Git →
                </a>
                <a href="{{ route('admin.finance') }}"
                    class="px-3 py-1.5 rounded-lg transition-colors {{ request()->routeIs('admin.finance') ? 'bg-neutral-200/70 dark:bg-neutral-800/70 text-neutral-900 dark:text-white' : 'hover:bg-neutral-200/50 dark:hover:bg-neutral-800/50' }}">
                    Finans & Muhasebe
                </a>
                <a href="{{ route('admin.cms') }}"
                    class="px-3 py-1.5 rounded-lg transition-colors {{ request()->routeIs('admin.cms') ? 'bg-neutral-200/70 dark:bg-neutral-800/70 text-neutral-900 dark:text-white' : 'hover:bg-neutral-200/50 dark:hover:bg-neutral-800/50' }}">
                    İçerik & CMS
                </a>
            </div>
        </div>

    </main>

    @livewireScripts
</body>

</html>
