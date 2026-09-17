@props([
    'title' => 'Panel',
    'roleLabel' => 'Panel',
    'roleColor' => 'text-brand-500',
    'roleIcon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    'dashboardRoute' => 'home',
    'primaryAction' => null,
    'nav' => [],
])
@php
    $user = auth()->user();
    $appName = config('app.name', 'NavlunIQ');
@endphp
<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f97316">
    <title>{{ $title }} | {{ $appName }}</title>
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
    @livewireStyles
</head>

<body class="h-full bg-neutral-100 dark:bg-neutral-950 text-neutral-800 dark:text-neutral-200 flex flex-col antialiased transition-colors duration-300"
    x-data="{ mobileSidebarOpen: false }" @keydown.escape.window="mobileSidebarOpen = false">

    <div x-show="mobileSidebarOpen" x-cloak @click="mobileSidebarOpen = false"
        x-transition.opacity class="fixed inset-0 z-40 bg-neutral-950/60 backdrop-blur-sm md:hidden"></div>

    <aside :class="{ 'translate-x-0': mobileSidebarOpen, '-translate-x-full': !mobileSidebarOpen }"
        class="-translate-x-full fixed inset-y-0 left-0 z-50 w-72 md:w-64 bg-white dark:bg-neutral-900 border-r border-neutral-200 dark:border-neutral-800 flex flex-col transition-transform duration-300 ease-in-out md:translate-x-0 safe-top">

        <div class="h-16 shrink-0 flex items-center justify-between px-5 border-b border-neutral-200 dark:border-neutral-800">
            <a href="{{ route($dashboardRoute) }}" wire:navigate class="group flex items-center gap-3 min-w-0 select-none">
                <div class="w-10 h-10 shrink-0 rounded-2xl bg-gradient-to-tr from-brand-600 to-amber-500 flex items-center justify-center shadow-lg shadow-brand-500/25 transition-transform duration-300 ease-apple-ease group-hover:scale-105">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $roleIcon }}"/></svg>
                </div>
                <div class="min-w-0 leading-tight transition-transform duration-300 ease-apple-ease group-hover:translate-x-0.5">
                    <div class="text-base font-black tracking-tight text-neutral-900 dark:text-white"><span>Navlun</span><span class="text-brand-500">IQ</span></div>
                    <div class="text-[10px] font-bold uppercase tracking-wider {{ $roleColor }}">{{ $roleLabel }}</div>
                </div>
            </a>
            <button type="button" @click="mobileSidebarOpen = false" class="md:hidden p-2 -mr-2 rounded-lg text-neutral-500 hover:text-neutral-900 dark:hover:text-white" aria-label="Menüyü kapat">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        @if($primaryAction)
            <div class="px-4 pt-4">
                <a href="{{ route($primaryAction['route']) }}" wire:navigate class="btn-primary w-full py-3">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>{{ $primaryAction['label'] }}</span>
                </a>
            </div>
        @endif

        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            @foreach($nav as $item)
                @php $active = request()->routeIs($item['match']); @endphp
                <a href="{{ route($item['route']) }}" wire:navigate @click="mobileSidebarOpen = false"
                    class="nav-item {{ $active ? 'nav-item-active' : '' }}" @if($active) aria-current="page" @endif>
                    <svg class="w-5 h-5 shrink-0 {{ $item['iconClass'] ?? '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                    <span class="flex-1 truncate">{{ $item['label'] }}</span>
                    @if(! empty($item['badge']))
                        <span class="badge bg-amber-500/15 text-amber-700 dark:text-amber-300">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="p-4 border-t border-neutral-200 dark:border-neutral-800 safe-bottom">
            <div class="rounded-2xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-800 p-3 space-y-3">
                <livewire:role-switcher />
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 shrink-0 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400 flex items-center justify-center font-bold text-sm">
                        {{ mb_strtoupper(mb_substr($user?->first_name ?? 'N', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-neutral-900 dark:text-white truncate">{{ $user?->full_name }}</div>
                        <div class="text-xs text-neutral-500 truncate">{{ \App\Support\Phone::format($user?->phone) ?: $user?->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                        @csrf
                        <button type="submit" title="Çıkış yap" class="p-2 rounded-lg text-neutral-500 hover:text-rose-500 hover:bg-rose-500/10 transition-colors" aria-label="Çıkış yap">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    <div class="flex-1 md:pl-64 flex flex-col min-h-screen">
        <header class="h-16 shrink-0 sticky top-0 z-30 flex items-center gap-3 px-4 md:px-8 bg-white/80 dark:bg-neutral-900/70 backdrop-blur-md border-b border-neutral-200 dark:border-neutral-800 safe-top">
            <button type="button" @click="mobileSidebarOpen = true" class="md:hidden p-2 -ml-2 rounded-lg text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800" aria-label="Menüyü aç">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="flex-1 min-w-0 truncate text-base md:text-lg font-semibold text-neutral-900 dark:text-white">{{ $title }}</h1>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" @click="$store.textSize.toggle()" :class="$store.textSize.large ? 'text-brand-500 bg-brand-500/10' : 'text-neutral-600 dark:text-neutral-300'" class="p-2 rounded-lg hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors" title="Yazı boyutu" aria-label="Yazı boyutunu değiştir">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 18.5l5-13 5 13M4.8 13.5h5.4M13.5 18.5l3-8 3 8M14.8 15.8h3.4"/></svg>
                </button>
                <button type="button" @click="$store.darkMode.toggle()" class="p-2 rounded-lg text-neutral-600 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors" title="Temayı değiştir" aria-label="Temayı değiştir">
                    <svg x-show="!$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
                    <svg x-show="$store.darkMode.on" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
                <a href="{{ route('home') }}" target="_blank" rel="noopener" class="hidden sm:inline-flex btn-secondary py-2 px-3 text-xs">
                    <span>Siteye git</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </div>
        </header>

        <main class="flex-1 w-full max-w-7xl mx-auto p-4 md:p-8">
            {{ $slot }}
        </main>

        <footer class="py-4 px-4 text-center text-xs text-neutral-500 border-t border-neutral-200 dark:border-neutral-800 safe-bottom">
            &copy; {{ date('Y') }} {{ config('company.name') ?: $appName }}
        </footer>
    </div>

    @livewireScripts
</body>
</html>
