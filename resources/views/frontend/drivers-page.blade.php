<x-layouts.frontend title="Şoförler İçin - NavlunIQ Akıllı Lojistik">
    <div class="max-w-5xl mx-auto px-6 md:px-12 space-y-16 animate-fade-in">

        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest">SÜRÜCÜLERE ÖZEL ÇÖZÜMLER</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
                Boş Dönüşe Son, Alın Teriniz Güvende
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                Yola çıktığınız an çalışan Akıllı Dönüş Radarı ile varış noktanızdaki dönüş yüklerini ayağınıza getirin. Teslimat onaylandığında hak edişiniz banka hesabınıza aktarılır.
            </p>
        </div>

        <!-- Avantajlar 3'lü Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs">
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl">🎯</div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Akıllı Dönüş Radarı</h4>
                <p class="text-neutral-500 leading-relaxed">Varış noktanıza yaklaşırken dönüş rotanıza en uygun yükleri otomatik filtreler.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl">💰</div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Garantili Hak Ediş</h4>
                <p class="text-neutral-500 leading-relaxed">Navlun bedeli yola çıkmadan havuzda bloke edilir, paranız teslimat onayı sonrası banka hesabınıza aktarılır; süre banka iş günlerine göre değişinde IBAN'ınıza yatar.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl">📱</div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Tek Ekranda Tüm İlanlar</h4>
                <p class="text-neutral-500 leading-relaxed">Onlarca WhatsApp grubundaki dağınık mesajları yapay zeka temiz bir tablo halinde önünüze serer.</p>
            </div>
        </div>

        <!-- Altta Büyük Kayıt Butonu -->
        <div class="apple-glass rounded-3xl p-10 text-center space-y-6 shadow-apple-lg border-2 border-emerald-500">
            <h3 class="text-2xl font-black text-neutral-900 dark:text-white">Hemen Sürücü Kadrosuna Katılın</h3>
            <p class="text-xs text-neutral-400 max-w-md mx-auto">Kişisel bilgilerinizi ve araç belgelerinizi yükleyin, belgeleriniz onaylandıktan sonra teklif vermeye başlayın.</p>
            <div>
                <a href="{{ route('register.driver') }}" class="btn-apple-secondary py-4 px-10 text-sm font-bold inline-block shadow-apple-md bg-neutral-900 text-white dark:bg-white dark:text-neutral-900">
                    Şoför Olarak Kayıt Ol →
                </a>
            </div>
        </div>

    </div>
</x-layouts.frontend>
