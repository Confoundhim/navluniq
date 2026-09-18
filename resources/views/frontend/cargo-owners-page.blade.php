<x-layouts.frontend title="Yük Sahipleri İçin - NavlunIQ Akıllı Taşımacılık">
    <div class="max-w-5xl mx-auto px-6 md:px-12 space-y-16 animate-fade-in">

        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="text-xs font-black text-brand-500 uppercase tracking-widest">YÜK SAHİPLERİNE ÖZEL ÇÖZÜMLER</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
                Yükünüz Güvende, Maliyetiniz Kontrol Altında
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                İlan sihirbazımız ile araç türü, rota ve bütçe stratejinizi girerek ilanınızı anında açın. Güvenceli ödeme sistemiyle teslimata kadar riskinizi sıfırlayın.
            </p>
        </div>

        <!-- Avantajlar 3'lü Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs">
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Teslimat Onaylı Ödeme</h4>
                <p class="text-neutral-500 leading-relaxed">Navlun bedelini lisanslı ödeme kuruluşu üzerinden ödersiniz; ödeme, yükün sağlam teslim edildiğini onayladığınızda şoföre tamamlanır.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Belgeleri Doğrulanmış Şoförler</h4>
                <p class="text-neutral-500 leading-relaxed">Ehliyet, SRC ve psikoteknik belgeleri ekibimizce kontrol edilmiş profesyonel taşıyıcılar.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Canlı Harita Takibi</h4>
                <p class="text-neutral-500 leading-relaxed">Yola çıkan aracınızın şoför tarafından paylaşılan anlık konumunu haritadan izleyin.</p>
            </div>
        </div>

        <!-- Altta Büyük Kayıt Butonu -->
        <div class="apple-glass rounded-3xl p-10 text-center space-y-6 shadow-apple-lg border-2 border-brand-500">
            <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Hemen Yük İlanınızı Oluşturun</h3>
            <p class="text-xs text-neutral-400 max-w-md mx-auto">En uygun navlun fiyatlarıyla dakikalar içinde sürücülerden teklif toplamaya başlayın.</p>
            <div>
                <a href="{{ route('register.cargo-owner') }}" class="btn-apple-brand py-4 px-10 text-sm font-bold inline-block shadow-apple-md">
                    Yük Sahibi Olarak Kayıt Ol →
                </a>
            </div>
        </div>

    </div>
</x-layouts.frontend>
