<x-layouts.frontend title="E-posta aboneliği - NavlunIQ" :noindex="true">
    <div class="max-w-md mx-auto py-16 px-6 text-center space-y-4 animate-fade-in">
        <div class="mx-auto w-12 h-12 rounded-full bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h1 class="text-xl font-bold text-neutral-900 dark:text-white">Kampanya e-postaları kapatıldı</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">Bundan sonra kampanya ve duyuru e-postası almayacaksınız. Teklif, ödeme ve sevkiyat gibi işlem bildirimleri hizmetin gereği olarak gönderilmeye devam eder. Dilerseniz profil sayfanızdan yeniden açabilirsiniz.</p>
        <a href="{{ route('home') }}" class="btn-secondary inline-flex py-2 text-xs">Ana sayfa</a>
    </div>
</x-layouts.frontend>
