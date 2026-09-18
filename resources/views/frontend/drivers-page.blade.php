<x-layouts.frontend title="Şoförler İçin - NavlunIQ Akıllı Lojistik">
    <div class="max-w-5xl mx-auto px-6 md:px-12 space-y-16 animate-fade-in">

        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest">SÜRÜCÜLERE ÖZEL ÇÖZÜMLER</span>
            <h1 class="text-3xl sm:text-5xl font-black text-neutral-950 dark:text-white tracking-tight">
                Boş Dönüşe Son, Alın Teriniz Güvende
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 leading-relaxed">
                Tercih ettiğiniz rotalardaki ilanları tek panelden görün, teklif verin. Teslimat onaylandığında hak edişiniz banka hesabınıza aktarılır.
            </p>
        </div>

        <!-- Avantajlar 3'lü Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs">
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Kalıcı Filtreler</h4>
                <p class="text-neutral-500 leading-relaxed">Araç tipi, il ve mesafeye göre kaydettiğiniz filtreler her girişte hazırdır; "yakınımdaki ilanlar" ile konumunuza en yakın yükleri önce görürsünüz.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Garantili Hak Ediş</h4>
                <p class="text-neutral-500 leading-relaxed">Navlun bedeli siz yola çıkmadan güvence altına alınır; teslimat onayından sonra hak edişiniz kayıtlı IBAN'ınıza aktarılır. Hesaba geçiş süresi banka iş günlerine göre değişir.</p>
            </div>
            <div class="apple-glass rounded-3xl p-6 space-y-3">
                <div class="text-2xl"></div>
                <h4 class="font-bold text-sm text-neutral-900 dark:text-white">Tek Ekranda Tüm İlanlar</h4>
                <p class="text-neutral-500 leading-relaxed">Onlarca WhatsApp grubundaki dağınık mesajlar yapay zeka ile ayrıştırılır; rota, yük türü, tonaj ve uygun araç tipi standart bir ilan kartı olarak önünüze gelir.</p>
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
