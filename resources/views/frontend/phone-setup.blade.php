<x-layouts.frontend title="Telefon kurulumu - NavlunIQ">
    <meta http-equiv="refresh" content="45">
    <div class="max-w-3xl mx-auto px-6 md:px-12 space-y-8 animate-fade-in text-sm">
        <div class="text-center space-y-3">
            <span class="text-xs font-black text-brand-500 uppercase tracking-widest">İlan bildirim iletici</span>
            <h1 class="text-3xl sm:text-4xl font-black text-neutral-950 dark:text-white tracking-tight">Telefon kurulumu</h1>
            <p class="text-neutral-500 dark:text-neutral-400">Bu telefon, üye olduğu WhatsApp gruplarındaki ilan mesajlarını NavlunIQ'ya iletecek. Kurulum yaklaşık 5 dakika sürer; sırayla ilerleyin.</p>
        </div>

        <div class="apple-glass rounded-3xl p-5 md:p-6 space-y-3 text-xs">
            <div class="flex flex-wrap items-center gap-3">
            <span class="font-bold text-neutral-900 dark:text-white">Sunucuya son ulaşan istek:</span>
            @if($lastEventAt)
                <span class="badge bg-emerald-500/10 text-emerald-600">{{ $lastEventAt->diffForHumans() }}</span>
                <span class="text-neutral-400">{{ $lastEventSource }} · {{ $lastEventStatus }}</span>
            @else
                <span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">henüz yok</span>
            @endif
            <span class="text-neutral-400 ml-auto">Sayfa 45 saniyede bir yenilenir.</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-neutral-200/60 dark:border-neutral-800">
                <a href="{{ $pingUrl }}" target="_blank" rel="noopener" class="btn-apple-secondary py-2 px-4 text-xs">Bu telefondan sunucuya ulaşabiliyor muyum? Sına</a>
                <span class="text-neutral-400">Yeni sekmede "ok: true" görür ve yukarıdaki satır "Bağlantı sınaması" olursa ağ ve anahtar tamamdır; kalan tek şüpheli MacroDroid tetikleyicisidir.</span>
            </div>
        </div>

        <ol class="space-y-4">
            <li class="apple-glass rounded-3xl p-5 md:p-6 space-y-2">
                <h2 class="font-bold text-neutral-900 dark:text-white">1. MacroDroid'i yükleyin</h2>
                <p class="text-neutral-500 dark:text-neutral-400">Play Store'dan <a href="https://play.google.com/store/apps/details?id=com.arlosoft.macrodroid" target="_blank" rel="noopener" class="text-brand-600 font-semibold hover:underline">MacroDroid</a> uygulamasını kurup bir kez açın. Ücretsiz sürüm yeterlidir.</p>
            </li>

            <li class="apple-glass rounded-3xl p-5 md:p-6 space-y-3">
                <h2 class="font-bold text-neutral-900 dark:text-white">2. Makroyu kurun</h2>
                <p class="text-neutral-500 dark:text-neutral-400">MacroDroid → <strong>Makro ekle</strong>. Bir tetikleyici, bir eylem; kısıt yok. Bitince makroyu <strong>etkin</strong> yapın (sağdaki anahtar yeşil).</p>
                    <div class="text-xs space-y-2 text-neutral-600 dark:text-neutral-300">
                        <p><strong>Tetikleyici:</strong> Bildirim → "Bildirim alındı" → uygulama <strong>WhatsApp</strong> (WhatsApp Business kullanılıyorsa onu da seçin) → metin filtresi boş.</p>
                        <p><strong>Eylem:</strong> Bağlantı → <strong>HTTP İsteği</strong> → Yöntem <strong>POST</strong>.</p>
                        <p>Adres:</p>
                        <code class="block p-3 rounded-xl bg-neutral-100 dark:bg-neutral-900 font-mono break-all" id="setup-url">{{ $webhookUrl }}</code>
                        <p><strong>İçerik türü: application/x-www-form-urlencoded</strong> → "Parametreler" bölümüne şu 5 alanı ekleyin (ad = değer; süslü parantezliler MacroDroid değişkenidir, sihirli metin düğmesinden seçilir):</p>
                        <table class="w-full text-[11px] font-mono">
                            @foreach($params as $k => $v)
                                <tr class="border-b border-neutral-200/60 dark:border-neutral-800"><td class="py-1 pr-3 font-bold">{{ $k }}</td><td class="py-1 break-all">{{ $v }}</td></tr>
                            @endforeach
                        </table>
                        <p class="text-neutral-400">Neden form alanı? Mesajda tırnak ya da satır sonu olduğunda JSON gövde bozulur, form alanları bozulmaz. JSON tercih ederseniz: içerik türü application/json, gövde:</p>
                        <code class="block p-3 rounded-xl bg-neutral-100 dark:bg-neutral-900 font-mono break-all" id="setup-body">{{ $body }}</code>
                        <div class="flex gap-2">
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('setup-url').textContent).then(()=>this.textContent='Adres kopyalandı')" class="btn-apple-secondary py-2 px-4 text-xs">Adresi kopyala</button>
                            <button type="button" onclick="navigator.clipboard.writeText(@js($params['token'])).then(()=>this.textContent='Anahtar kopyalandı')" class="btn-apple-brand py-2 px-4 text-xs">Anahtarı kopyala</button>
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('setup-body').textContent).then(()=>this.textContent='Gövde kopyalandı')" class="btn-apple-secondary py-2 px-4 text-xs">JSON gövdeyi kopyala</button>
                        </div>
                    </div>
            </li>

            <li class="apple-glass rounded-3xl p-5 md:p-6 space-y-2">
                <h2 class="font-bold text-neutral-900 dark:text-white">3. İzinleri verin</h2>
                <ul class="list-disc pl-5 text-neutral-500 dark:text-neutral-400 space-y-1">
                    <li>Telefon Ayarları → Uygulamalar → Özel erişim → <strong>Bildirim erişimi</strong> → MacroDroid <strong>açık</strong>.</li>
                    <li>Telefon Ayarları → Uygulamalar → MacroDroid → Pil → <strong>Kısıtlama yok</strong> (Xiaomi/Huawei/Oppo'da "Otomatik başlat" da açık).</li>
                    <li>WhatsApp → Ayarlar → Bildirimler → Grup bildirimleri açık; <strong>bildirim önizlemesi</strong> (mesaj metni) açık.</li>
                    <li>Telefon uyanıkken ve kilitliyken de bildirim metni gösterilmeli (kilit ekranı: "İçeriği göster").</li>
                </ul>
            </li>

            <li class="apple-glass rounded-3xl p-5 md:p-6 space-y-2">
                <h2 class="font-bold text-neutral-900 dark:text-white">4. Deneyin</h2>
                <p class="text-neutral-500 dark:text-neutral-400"><strong>Başka biri</strong> gruba deneme ilanı yazsın, örneğin: <em>Ankara'dan İzmir'e 24 ton palet yük, tenteli tır lazım 0532 123 45 67</em>. Kendi yazdığınız mesaj telefonunuzda bildirim üretmez, o yüzden sayılmaz. Mesaj gelirken WhatsApp o sohbette açık olmasın (açıkken bildirim çıkmaz) ve grup sessize alınmış olmasın. Yukarıdaki "Sunucuya son ulaşan istek" satırı birkaç saniye içinde değişir.</p>
                <p class="text-neutral-500 dark:text-neutral-400">Değişmiyorsa sırayla: (1) Yukarıdaki <strong>Sına</strong> düğmesi çalışıyor mu? (2) MacroDroid'de makroyu açıp HTTP İsteği eylemine uzun basın → <strong>Eylemi test et</strong>; Canlı akışa "Atlandı" satırı düşerse makro istek atabiliyor, sorun tetikleyicidedir. (3) MacroDroid → Sistem günlüğü'nde "Bildirim alındı" tetiklenmiş mi bakın; tetiklenmiyorsa 3. adımdaki bildirim erişimi izni kapanmıştır.</p>
                <p class="text-[11px] text-neutral-400">Yeni gruplar NavlunIQ panelinde önce "onay bekliyor" olarak görünür; yönetici aktif edince ilanlar işlenmeye başlar.</p>
            </li>
        </ol>
    </div>
</x-layouts.frontend>
