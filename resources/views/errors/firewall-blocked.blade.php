<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NavlunIQ - Erişim Güvenlik Duvarı Tarafından Engellendi</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-neutral-950 text-white flex items-center justify-center p-6 font-sans">
    <div class="max-w-md w-full p-8 bg-neutral-900 border border-red-800/40 rounded-3xl space-y-6 text-center shadow-apple-dark">
        <div class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <div class="space-y-2">
            <h1 class="text-xl font-bold tracking-tight text-red-500">Erişiminiz Engellendi (403)</h1>
            <p class="text-xs text-neutral-400 leading-relaxed">
                IP adresiniz şüpheli aktivite veya güvenlik kuralları ihlali nedeniyle NavlunIQ Güvenlik Duvarı (Firewall) tarafından engellenmiştir
            </p>
        </div>
        <div class="p-4 bg-neutral-950 rounded-2xl border border-neutral-800 text-xs text-left space-y-1.5 font-mono">
            <div><span class="text-neutral-500">IP Adresi:</span> <span class="text-white">{{ $ip }}</span></div>
            <div><span class="text-neutral-500">Engel Sebebi:</span> <span class="text-red-400">{{ $reason }}</span></div>
            <div><span class="text-neutral-500">Geçerlilik:</span> <span class="text-white">{{ $until }}</span></div>
        </div>
        <p class="text-[10px] text-neutral-500">Bir yanlışlık olduğunu düşünüyorsanız lütfen info@navluniq.com ile iletişime geçiniz.</p>
    </div>
</body>
</html>
