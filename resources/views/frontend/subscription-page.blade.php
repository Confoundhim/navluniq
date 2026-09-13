<x-layouts.frontend title="Abonelik Sistemi - NavlunIQ Sürücü Paketleri">
    <div class="max-w-5xl mx-auto px-6 md:px-12 space-y-16 animate-fade-in text-xs">

        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="text-xs font-black text-brand-500 uppercase tracking-widest">SÜRÜCÜ ABONELİK SİSTEMİ</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
                Yük Bulma Hızınızı Zirveye Taşıyın
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                NavlunIQ'nun sürücüler için geliştirdiği yapay zeka abonelik hizmetidir. WhatsApp ve web mecralarından derlenen tüm ilanlara anında erişin.
            </p>
        </div>

        <!-- 2'li Karşılaştırma Tablosu -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            <!-- Ücretsiz Paket -->
            <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-sm flex flex-col justify-between">
                <div class="space-y-4">
                    <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">ÜCRETSİZ KANAL</span>
                    <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Telegram Haberleşme</h3>
                    <div class="text-3xl font-black text-neutral-900 dark:text-white pt-2">0 &#8378; <span class="text-xs text-neutral-400 font-normal">/ Süresiz</span></div>
                    <ul class="space-y-3 text-neutral-500 pt-4 border-t border-neutral-100 dark:border-neutral-800">
                        <li class="flex items-center space-x-2"><span>✓</span><span>Web siteleri ve WhatsApp gruplarından derlenen ilanlar</span></li>
                        <li class="flex items-center space-x-2"><span>✓</span><span>Telegram kanalında yaklaşık 20 dakika rötarlı akış</span></li>
                        <li class="flex items-center space-x-2"><span>✓</span><span>Sınırsız süreyle katılım garantisi</span></li>
                    </ul>
                </div>
                <a href="https://t.me/navluniq" target="_blank" class="w-full btn-apple-secondary py-3.5 text-center font-bold block">
                    Telegram Kanalımıza Katıl
                </a>
            </div>

            <!-- Premium Paket -->
            <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-lg border-2 border-brand-500 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-4 right-4 bg-brand-500 text-white text-[9px] font-black px-2.5 py-1 rounded-full uppercase tracking-wider">
                    ÖNERİLEN
                </div>
                <div class="space-y-4">
                    <span class="text-xs font-bold text-brand-500 uppercase tracking-wider">PROFESYONEL</span>
                    <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Premium Sürücü Üyeliği</h3>
                    <div class="text-3xl font-black text-brand-500 pt-2">900 &#8378; <span class="text-xs text-neutral-400 font-normal">/ Ay (KDV Dahil)</span></div>
                    <ul class="space-y-3 text-neutral-600 dark:text-neutral-300 pt-4 border-t border-neutral-100 dark:border-neutral-800 font-semibold">
                        <li class="flex items-center space-x-2"><span class="text-emerald-500">✓</span><span>Tüm platform ve web ilanlarını ANINDA görün</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500">✓</span><span>WhatsApp, Telegram ve Web Push üzerinden anlık anons bildirimleri</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500">✓</span><span>WhatsApp Grup Entegrasyonu: Gruplarınızdaki ilanları panelinizde otomatik listeletin</span></li>
                        <li class="flex items-center space-x-2"><span class="text-emerald-500">✓</span><span>Sürücü kontrol paneline tam erişim sağlayın</span></li>
                    </ul>
                </div>
                <a href="{{ route('register.driver') }}" class="w-full btn-apple-brand py-3.5 text-center font-bold block shadow-apple-sm">
                    Premium Sürücü Ol
                </a>
            </div>
        </div>

    </div>
</x-layouts.frontend>
