<x-layouts.frontend title="Yasal Sözleşmeler ve Politikalar - NavlunIQ">
    <div class="max-w-5xl mx-auto px-6 md:px-12 space-y-12 animate-fade-in text-xs"
        x-data="{ activeTab: '{{ $activeContract ?? 'kvkk' }}' }">

        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="text-xs font-black text-brand-500 uppercase tracking-widest">YASAL MEVZUAT VE UYUM</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
                Sözleşmeler ve Politikalar
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                NavlunIQ platformunun kullanım şartları, veri güvenliği protokolleri ve tüketici hakları taahhütleri.
            </p>
        </div>

        <!-- 5 Sözleşme Sekme Seçicisi -->
        <div
            class="flex flex-wrap p-1.5 bg-neutral-100 dark:bg-neutral-900 rounded-2xl border border-neutral-200/60 dark:border-neutral-800 gap-1.5 justify-center shadow-inner">
            <button @click="activeTab = 'kvkk'; window.history.pushState(null, '', '/sozlesmeler/kvkk')"
                class="px-4 py-2.5 rounded-xl font-bold transition-all text-xs"
                :class="activeTab === 'kvkk' ? 'bg-white dark:bg-neutral-800 text-neutral-950 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                KVKK Aydınlatma Metni
            </button>
            <button
                @click="activeTab = 'kullanici-sozlesmesi'; window.history.pushState(null, '', '/sozlesmeler/kullanici-sozlesmesi')"
                class="px-4 py-2.5 rounded-xl font-bold transition-all text-xs"
                :class="activeTab === 'kullanici-sozlesmesi' ? 'bg-white dark:bg-neutral-800 text-neutral-950 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                Kullanıcı Sözleşmesi
            </button>
            <button
                @click="activeTab = 'gizlilik-politikasi'; window.history.pushState(null, '', '/sozlesmeler/gizlilik-politikasi')"
                class="px-4 py-2.5 rounded-xl font-bold transition-all text-xs"
                :class="activeTab === 'gizlilik-politikasi' ? 'bg-white dark:bg-neutral-800 text-neutral-950 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                Gizlilik Politikası
            </button>
            <button
                @click="activeTab = 'mesafeli-satis'; window.history.pushState(null, '', '/sozlesmeler/mesafeli-satis')"
                class="px-4 py-2.5 rounded-xl font-bold transition-all text-xs"
                :class="activeTab === 'mesafeli-satis' ? 'bg-white dark:bg-neutral-800 text-neutral-950 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                Mesafeli Satış Sözleşmesi
            </button>
            <button
                @click="activeTab = 'iade-politikasi'; window.history.pushState(null, '', '/sozlesmeler/iade-politikasi')"
                class="px-4 py-2.5 rounded-xl font-bold transition-all text-xs"
                :class="activeTab === 'iade-politikasi' ? 'bg-white dark:bg-neutral-800 text-neutral-950 dark:text-white shadow-apple-sm' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                İade ve İptal Politikası
            </button>
        </div>

        <!-- SÖZLEŞME İÇERİĞİ (ADMİN PANELİNDEN GELEN ŞIK HTML) -->
        <!-- space-y-6 kaldırıldı, böylece tüm sekmeler eşit hizada başlar -->
        <div
            class="apple-glass rounded-3xl p-6 sm:p-12 shadow-apple-md leading-relaxed text-neutral-700 dark:text-neutral-200">

            <!-- 1. KVKK -->
            <div x-show="activeTab === 'kvkk'" x-cloak class="space-y-4 animate-fade-in">
                {!! \App\Models\CmsContent::getVal('contract_kvkk') !!}
            </div>

            <!-- 2. KULLANICI SÖZLEŞMESİ -->
            <div x-show="activeTab === 'kullanici-sozlesmesi'" x-cloak class="space-y-4 animate-fade-in">
                {!! \App\Models\CmsContent::getVal('contract_terms') !!}
            </div>

            <!-- 3. GİZLİLİK POLİTİKASI -->
            <div x-show="activeTab === 'gizlilik-politikasi'" x-cloak class="space-y-4 animate-fade-in">
                {!! \App\Models\CmsContent::getVal('contract_privacy') !!}
            </div>

            <!-- 4. MESAFELİ SATIŞ SÖZLEŞMESİ -->
            <div x-show="activeTab === 'mesafeli-satis'" x-cloak class="space-y-4 animate-fade-in">
                {!! \App\Models\CmsContent::getVal('contract_distance_sale') !!}
            </div>

            <!-- 5. İADE VE İPTAL POLİTİKASI -->
            <div x-show="activeTab === 'iade-politikasi'" x-cloak class="space-y-4 animate-fade-in">
                {!! \App\Models\CmsContent::getVal('contract_cancellation') !!}
            </div>

        </div>

    </div>
</x-layouts.frontend>
