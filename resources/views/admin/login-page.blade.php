<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NavlunIQ Admin - Giriş</title>

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
