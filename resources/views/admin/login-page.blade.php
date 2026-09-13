<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NavlunIQ Admin - Giriş</title>

    <!-- 🚀 GÜNCELLEME: Giriş sayfasına da resmi Inter yazı tipi bağlantısını ekliyoruz -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Projemizin Tailwind, Apple Stilleri ve JS Dosyaları -->
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
