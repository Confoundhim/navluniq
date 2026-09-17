<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f97316">
    <meta name="robots" content="noindex, nofollow">
    <title>Yönetici Girişi | NavlunIQ</title>
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <link rel="shortcut icon" href="/favicon.ico">
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

    @livewireStyles
</head>

<body
    class="min-h-screen bg-neutral-100 dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100 transition-colors duration-300">

    <!-- Volt Giriş Bileşenimizi Buraya Gömiyoruz -->
    <livewire:admin.login />

    @livewireScripts
</body>

</html>
