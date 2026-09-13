<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'NavlunIQ - Akıllı Lojistik Ağı' }}</title>

    <!-- Projemizin Tailwind, Apple Stilleri ve JS Dosyaları -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-100 dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100 transition-colors duration-300">

    <!-- Livewire Tam Sayfa Bileşenlerinin Yerleşeceği Ana Alan -->
    {{ $slot }}

    @livewireScripts
</body>
</html>
