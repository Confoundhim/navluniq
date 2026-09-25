# NavlunIQ — Proje Notu (yeni oturum burada başlar)

Bu dosya, önceki geliştirme oturumlarında oluşan kuralları ve bilgileri taşır. Yeni bir oturum bu dosyayı
okuyarak kaldığı yerden devam eder. Osman ile çalışırken burada yazan kurallar geçerlidir; değişiklik
olursa bu dosya da güncellenir. **Bu dosyaya asla şifre, anahtar ya da .env içeriği yazılmaz.**

## 1. Proje ve kişiler

- **NavlunIQ**: yük sahipleri ile belgeleri doğrulanmış şoförleri buluşturan freight (navlun) pazaryeri.
  Canlı: https://navluniq.com. Laravel 13 + Livewire 4 (Volt, tek dosya bileşenler) + Alpine + Tailwind.
- **Osman** (proje sahibi): Türkçe yazar, teknik değildir, çoğunlukla telefondan yazar, Windows/PowerShell
  kullanır. Konuşma dili Türkçe, sade, kısa; teknik terim yerine ne işe yaradığı anlatılır. Kod, dosya
  adları ve komutlar yalnız gerekince ve kod bloğu içinde verilir.
- **Sevda Abla**: sektör (tırcı dili) danışmanı; kasa/dorse kurallarının kaynağı.
- **Engin Abi**: şoför tarafı kullanıcı deneyimi önerileri (il/ilçe seçim kutuları, "Her yer").

## 2. Çalışma düzeni (değişmez kurallar)

- Geliştirme dalı: oturumun verdiği `claude/...` dalı (bu oturumda `claude/navluniq-continuation-mok1oc`; önceki
  `claude/laravel-marketplace-mobile-a68zgo` main'e birleşti). Osman'ın verdiği dal adı oturumunkinden farklıysa
  oturumun dalı kullanılır ve Osman'a tek satırla söylenir. Her iş bu dala commit edilir, push edilir ve `main`'e PR açılır. Osman PR'ları hemen birleştirir; **push etmeden önce PR durumunu kontrol et**:
  PR birleşmişse `git fetch origin main && git merge --no-edit origin/main` yap, push et ve **yeni PR** aç.
  Asla force-push yapma, asla başka dala push etme.
- Commit mesajları Türkçe, ne yapıldığını ve nedenini anlatır. Ortamın verdiği yazar/oturum satırlarını
  (Co-Authored-By, Claude-Session) mesajın sonuna ekle. PR açıklamasının sonuna ortamın verdiği
  "Generated with Claude Code" satırı ve oturum bağlantısı gelir. Depoya giden hiçbir şeye model kimliği yazılmaz.
- `.env` ve gizli bilgiler asla commit edilmez. Osman sohbette gizli bilgi paylaşmış olabilir; bunları
  tekrar etme, dosyaya yazma, "anahtarı değiştir" uyarısı yapma (sorumluluk onda).
- **Her ayar yönetici panelinden** yapılır (`App\Support\Settings`, CmsContent tabanlı); `.env` düzenlemesi
  istenmez. Yalnız ücretsiz yapay zeka API'leri kullanılır (Gemini, Groq, Cerebras, OpenRouter, Mistral, Ollama).
- İlan metinleri hiçbir zaman BÜYÜK HARF olmaz; her ilan standart, sade karta dönüştürülür
  (`App\Support\TurkishText`: PHP'nin mb_convert_case'i İ harfini bozar, bu sınıf kullanılır).
- WhatsApp grup mesajları üçüncü kişilere aittir: depoya yalnız türetilmiş kurallar (sözlük, örüntü) girer,
  ham mesaj ya da numara girmez. Testlerde uydurma numara ve metin kullanılır.
- Yönetici ekranındaki "Geçmişten yeniden öğren" düğmesine basılmaması Osman'a hatırlatılır.
- Her işten sonra: `vendor/bin/pint --dirty`, ilgili testler, tercihen tüm paket (`php artisan test --compact`),
  tarayıcıda 390 px genişlikte ekran görüntüsü ile doğrulama (bkz. §6). Sonuç Osman'a kısa Türkçe özetle,
  "birleştirdikten sonra panelden **Siteyi güncelle**" notuyla verilir.
- Osman "olur mu / öneriniz var mı" diye sorduğunda önce değerlendirme ve öneri yazılır, onay gelince yapılır.
  Doğrudan iş istediğinde sorulmadan yapılır.

## 3. Sunucu ve güncelleme

- Sunucu: Ubuntu, nginx, PHP 8.4, MariaDB, Redis; uygulama `/var/www/navluniq`. SSH ile root girer.
- Güncelleme: yönetici panelinde **Sistem sağlığı → "Siteyi güncelle"** ya da `bash /root/update.sh`.
  Akış: `App\Services\DeployService` → `/usr/local/bin/navluniq-update` (transient systemd servisi) →
  `deploy/update.sh` (bakım modu, PHP-FPM yeniden başlatma, git reset, composer, npm build, migrate,
  seed, önbellek). Günlük: `storage/logs/update.log`; durum JSON'u bakım modundan muaf.
- Migration takılırsa: betik 60 sn'den eski açık işlemleri kapatır; hâlâ kalırsa
  `information_schema.innodb_trx` ile kilit tutan bağlantı bulunup `KILL <id>` yapılır. Migration'lar
  MariaDB'de yeniden çalıştırılabilir yazılır (`Schema::hasTable/hasColumn` koruması).
- Bekleyen dış işler (Osman'ın yapacağı): Google faturalandırma anahtarı, Brevo/DMARC/DKIM kurulumu.
  Şoförlere duyuru: Araçlarım'dan kasa tipini seçsinler.

## 4. Alan bilgisi (sektör kuralları)

- Araç sınıfı ile kasa tipi ayrı boyutlardır (`App\Support\VehicleTypes`, `App\Support\BodyTypes`). Araç tipleri
  (2026-09 revizyonu, Sevda Abla matrisi): panelvan, kamyonet, 6/8/10 teker kamyon, kırkayak, TIR. Otomobil ve minivan yok;
  eski orta/uzun panelvan, minivan, otomobil kayıtları `VehicleTypes::LEGACY` ile panelvana çevrilir.
  Kasa matrisi: panelvan → kapalı, frigo · kamyonet → tenteli, kapalı, açık, frigo · kamyon ve kırkayak → tenteli, kapalı, açık,
  frigo, damperli · TIR → tenteli, kapalı, açık, frigo, damperli, silobas, lowbed; dorse boyu kısa / uzun (13.60) ayrı boyut.
  "Liftli" (kuyruk lifti) kasa cinsi değil ek özelliktir (`BodyTypes::FEATURES`); şoför aracında `has_lift` (var/yok/belirsiz),
  ilan lift isterse "yok" diyen araca gösterilmez.
- "13.60" = damper hariç her kasa; "dökme yük" = damper; "kapalı ≠ tenteli"; "kasalı" ürün → tenteli/kapalı/frigo;
  "her türlü" = kısıt yok; lowbed artık TIR kasa tipidir (iş makinesi → lowbed/açık).
- "Samsun 2 yer" = 2 ayrı tır (vehicle_count), "Adana + Urfa" = çok teslim noktası (delivery_stops).
  Parça / komple yük ayrımı (load_kind). Fiyat ton başına olabilir (price_unit).
- **Eksik bilgili ilanlar**: karar puanı otomatik ret sınırı (%25) ile `scraper_incomplete_max_score` (%60) arasında kalan,
  kalkış-varış ili ve telefonu belli adaylar kuyrukta beklemez; `is_incomplete=true` ile yayınlanır. Şoför tarafında dış kaynak
  sekmesinin "Eksik bilgili ilanlar" bölümünde (araç filtresi uygulanmaz), genel bakış ve dönüş yükü taramasında görünmez
  (`ScrapedLoad::complete()`). Şoför karttaki "Aradım, araç:" seçimiyle (`completeByDriver`) ya da yönetici düzenlemeyle
  tamamlar; ilan normal listeye geçer ("Şoför doğruladı"). %60-%75 arası kuyrukta kalır (sistemi eğitir); ayarlar panelde.
- **Facebook grupları** bot ile taranmaz (Meta kuralları, hesap kapatma, KVKK): WhatsApp gibi telefondaki bildirim iletici
  (MacroDroid) Facebook bildirimlerini aynı adrese yollar; `NotificationIntakeParser::parseFacebook` (uygulama adı "Facebook")
  grup ve gönderiyi ayıklar, kaynak `facebook` türü ve `fb:grup-adi` tanımlayıcısıyla pasif açılır. Yorum/beğeni bildirimleri
  atlanır. Tekrar denetimi metin ve numara+rota üzerinden, kaynaktan bağımsız: aynı ilan WhatsApp'ta da varsa tek kayıt,
  `seen_sources` sayacına yazılır. Gönderen adı saklanmaz. Kurulum: `docs/BILDIRIM_ILETICI_KURULUM.md` Facebook bölümü.
- Dış kaynak ilanları (gruplardan derlenen) yalnız premium şoförlere görünür; sistem ilanları önce premium'a,
  ayarlı süre sonra herkese açılır ve Telegram kanalına gider.

## 5. Kod haritası (en çok dokunulan yerler)

- Ayrıştırma hattı: `LoadIntakeService` (mesajı parçalara böler; büyük harfli başlıklar Türkçe küçültülerek
  eşlenir), `LoadStandardizer`, `AiParserService` (sağlayıcı zinciri), `LocalClassifier`, `Lexicon`,
  `GoodsCatalog`, `TurkishLocations` (il/ilçe, koordinat, takma adlar; ilçe listesi tekil ve Türk alfabesi sırasında).
- Şoför tarafı: `resources/views/livewire/driver/{dashboard,loads/index,jobs/index,jobs/show,vehicles/index}.blade.php`,
  `LoadFilterService` (filtre ön ayarları, il/ilçe, kasa, yakınımda), `DriverTripService` (iş/sefer, dönüş yükü
  taraması 10 dk'da bir, `reconcile` ile sevkiyat-sefer tutarlılığı), `App\Livewire\Concerns\HandlesExternalLoadActions`
  (yıldız, "Bu işi aldım"), `HandlesJobActions` (iş kartı eylemleri), ortak kart bileşenleri `components/job-card`,
  `components/external-load-card`, `components/take-trip-modal`, `components/time-ago`.
- **İşlerim** (`/panel/sofor/islerim`, `driver.jobs.index`): şoförün tek iş listesi. Kayıt `DriverTrip`; NavlunIQ işi
  (`source=system`, teklif kabulünde `fromShipment` ile açılır) ve gruptan alınan iş (`source=external`). NavlunIQ işinde
  durum ilandan türetilir (`displayStatusLabel`), elle kapatılamaz, "Yola çıktım" ödeme alınınca kart üzerinden; teslimat
  kanıtı ve konum `jobs/show` (`/panel/sofor/is/{loadId}`). Eski adresler (`/sevkiyatlarim`, `/seferlerim`, `/sevkiyat/{id}`)
  301 ile buraya yönlenir. Uyuşmazlık kararı ve ilan iptali seferi kapatır; `autoClose` yalnız gruptan alınan işlere dokunur.
- Tablolar: her veri tablosu `table-cards` sınıfı taşır (`resources/css/app.css`): 1024 px altında (telefon dikey/yatay,
  kenar çubuklu orta ekran) her satır kart olur, hücre başına `data-label` sütun adı yazar; `tc-check` seçim kutusu,
  `tc-actions` düğme satırı, `tc-block` uzun içerik. Uzun serbest metin `<x-clamp-text :text lines="3" />` ile kısaltılır,
  "Devamı" ile yerinde açılır (pencere açılmaz). Yeni tablo eklerken aynı kalıp kullanılır.
- Listeler: sayfada 50 kayıt; sayfa numaraları tek görünümde `resources/views/vendor/livewire/tailwind.blade.php`
  ("‹ Önceki 1 … 5 … 12 Sonraki ›", "Toplam N kayıt"). İlan havuzu listeye sabitlenir; yeni ilan gelince
  "N yeni ilan · Göster" düğmesi çıkar, liste yerinden oynamaz.
- Süreli yenileme (`wire:poll`) kullanıcı ekranla uğraşırken çizilmez: `App\Livewire\PausePollWhileInteracting`
  + `resources/js/app.js` (X-User-Idle-Ms başlığı). Göreli zaman etiketleri tarayıcıda kendi ilerler
  (`App\Support\TimeAgo`, `data-ago`). Dinamik Tailwind sınıfları `tailwind.config.js` safelist'e eklenir.
- Bildirimler: `NotificationService` (uygulama içi + e-posta), `App\Livewire\NotificationsPage`
  (okundu / sil / okunanları sil), zil `notifications/bell.blade.php` (`notifications-changed` olayı).
  Dönüş yükü bildirimi aynı sefer için okunmamışsa üstüne yazılır, yığılmaz.
- Sayaçlar: `LoadStatsService` ("bugüne kadar" hiç düşmez; arşivlenen ilanlar sayılır; önbellek 1 dk, yayın/teslimat onayında
  düşürülür). Ana sayfa sayaçları `livewire/frontend/live-stats` (15 sn'de bir, sekme görünürken); tarayıcıda `countUp`
  (app.js) eski değerden yeniye akarak sayar ve `count-pop` vurgusu yapar. Dış kaynak ilanı
  `scraper_list_days` (varsayılan 7) gün sonra listeden kalkar, silinmez (soft delete = arşiv); ayar kısaltılınca yayın tarihi
  süreyi aşanlar bir sonraki günlük temizlikte arşivlenir. Canlı akış kayıtları 30 gün sonra silinir (`purgeIntakeEvents`).
- E-posta: `RuntimeMailConfig` (panelden SMTP), şablon `emails/layouts/base.blade.php`
  (gizli ön izleme metni yok: Natro bunu düşürüyordu), altbilgide yalnız şirket adı; ETBİS yalnız site altbilgisinde.
- Belgeler: `docs/*.md` (bildirim iletici kurulumu, e-posta, ödeme altyapısı, Telegram, mobil hazırlık).

## 6. Yerel geliştirme ve doğrulama

- Yeni kapta ilk kurulum (README "Yerel kurulum"): `composer install`, `.env` yoksa `cp .env.example .env` +
  `php artisan key:generate`, `.env`'de `DB_*` yerel MariaDB'ye göre, `MAIL_MAILER=log`; `php artisan migrate`,
  `php artisan db:seed` (roller, SSS, sözleşmeler), `npm install`, `npm run build`.
- **Deneme hesapları depoda:** `php artisan db:seed --class=LocalDemoSeeder` (yalnız yerel; tekrar çalıştırmak güvenli).
  `admin@test.local` (süper yönetici), `sofor@test.local` (premium, belgeleri onaylı, TIR tenteli 13.60),
  `yuk@test.local` (yük sahibi); şifre `Sifre12345!`, tek kullanımlık kod `123456` (panel ayarı
  `review_login_emails/review_login_code` ile sabitlenir; canlıda yok). Örnek bir sistem ve bir dış kaynak ilanı da açılır.
- Testler SQLite (bellek içi) ile çalışır, MariaDB gerekmez: `php artisan test --compact` (300+ test, ~30 sn).
  Testler yerel `.env`'den bağımsızdır (`phpunit.xml` içindeki `<env>` satırları); `.env`'deki bir değer testi bozarsa oraya eklenir.
  Kapta MariaDB yoksa: `apt-get update && apt-get install -y mariadb-server`, sonra yukarıdaki `mysqld_safe` komutu.
  Yeni özellik = yeni test. Kod biçimi: `vendor/bin/pint --dirty`.
- Yerel MariaDB durmuşsa (kap yeniden başlayınca durur): `(setsid nohup mysqld_safe --user=mysql >/dev/null 2>&1 &)`
  ve `mysqladmin ping` ile bekle. Geliştirme sunucusu: `php artisan serve --host 127.0.0.1 --port 8085` (arka planda).
  Ön yüz: `npm run build` (`public/build` depoda değil; CSS/JS/Tailwind sınıfı değişince derle).
- **Ekran görüntüsü:** `scripts/shot.cjs` (giriş yapar, 390×844 telefon boyutunda parça parça çeker):
  `PW_MODULE=<playwright yolu> CHROME=/opt/pw-browsers/chromium-*/chrome-linux/chrome node scripts/shot.cjs driver /panel/sofor/dashboard normal cikti`
  (`driver|cargo|admin|public`, kip `normal|large`). Playwright depoda bağımlılık değil: çalışma klasöründe
  `npm i --no-save playwright` (indirme yapmaz; tarayıcı `/opt/pw-browsers` altında hazır). Etkileşim gerektiren
  denetimler için aynı betiği temel alıp geçici bir betik yazılır. Toplu taşma denetimi: `scripts/mobile-audit.cjs`.
- Yönetici sekme bağlantıları: `/adminsystem/scrapers?sekme=published`, `/adminsystem/operations`,
  `/adminsystem/settings`, `/adminsystem/health`. Şoför: `/panel/sofor/...` (ilan-havuzu, seferlerim, bildirimler).

## 7. Tasarım ve kullanıcı deneyimi tercihleri (Osman'ın beğenileri)

- Her şey telefonda (390 px) ve büyük yazı kipinde çalışmalı; taşma, üst üste binme olmaz.
- Sade ve hafif: kaba, kalın, "Menü" yazılı düğmeler istenmez; mobil menü düğmesi ince çizgili, hafif gri kutu.
  Bir düğme "çirkin / uyumsuz" diye geri gelirse tasarımı sadeleştir, açıklama ekleme.
- Kullanıcıya iş yaptıran açıklamalar ("Boşluğa dokununca kapanır" gibi) konmaz; davranış kendiliğinden doğru olmalı.
- Süreli hiçbir şey kullanıcıyı bölmez: açık liste kapanmaz, seçim sıfırlanmaz, liste parmağın altından kaymaz.
  Yeni veri "N yeni ilan · Göster" gibi bir düğmeyle gelir; "Göster" listenin başına kaydırır.
- Zaman etiketlerinde saniye gösterilmez ("az önce", "3 dk önce"); etiketler kendiliğinden ilerler.
- Aynı türdeki kart her ekranda birebir aynıdır (tek bileşen). Aktif sefer kartı turuncu, dönüş yükü kartı yeşil.
- Yönetici listelerinde her zaman kaç kayıt olduğu görünür (filtreye uyan sayı dahil).
- Yönetici sayfalarında süreli yenileme ucuz bir değişiklik imzasıyla yapılır (`tick` + `skipRender`; dış kaynak sayfası
  15 sn); veri değişmediyse hiçbir şey çizilmez. Karar puanı istek içinde bir kez hesaplanır (`decision` memo).
- Sayaçlar "bugüne kadar" mantığıyla artar, hiç düşmez; yanında "bugün" ve "günlük ortalama".
- Claude / yapay zeka ürünleri hakkında ders anlatılmaz, model kimliği depoya yazılmaz; sorulursa yalnız cevaplanır.

## 8. Zamanlanmış görevler (routes/console.php)

`offers:expire` (saatlik), `subscriptions:expire` (saatlik), `subscriptions:remind` (09:00), `notifications:retry-mail`
(10 dk), `scraped-loads:purge-expired` (günlük; arşivler, silmez), `scraped-loads:ai-enrich` (5 dk),
`scraped-loads:auto-approve` (dakikada), `loads:release-to-free` (dakikada), `shipments:auto-approve` (saatlik),
`accounts:purge-drafts` (günlük), `system:backup` (03:30), `trips:scan-return-loads` (10 dk), `trips:auto-close` (04:10),
`scheduler-heartbeat` (dakikada; sağlık ekranı buna bakar). Bakım modunda zamanlayıcı çalışmaz.
Güncelleme sonrası `scraped-loads:classify` boş kalan araç/kasa alanlarını doldurur (tekrar çalıştırmak güvenli).

## 9. Test ve kod tuzakları (öğrenilmiş)

- Livewire testinde `->call('$refresh')` tarayıcıdaki poll'u taklit etmez (commit'e dönüşür); poll davranışı için
  `->update(calls: [['method' => '$refresh', 'params' => [], 'path' => '']])` ya da kancayı doğrudan test et.
  `Livewire::withHeaders()` sonraki isteklere taşınmaz.
- Premium/KYC gibi kullanıcı durumu değişince `actingAs($user->fresh())`; Volt bileşenleri modeli önbellekler.
- Aynı saniyede oluşturulan kayıtlar `created_at > now()` ile kaçar; `>=` + "zaten bildirilmişler hariç" kullan.
- `Carbon::createFromTimestamp()` UTC döner; veritabanıyla karşılaştırırken `config('app.timezone')` ver.
  `diffInDays` işaretli döner; `abs`/doğru sıra kullan.
- `mb_convert_case` İ'yi bozar → `TurkishText`; sıralama `TurkishText::compare`; ilçe takma adları tekilleştirilir.
- Blade'de dinamik üretilen Tailwind sınıfları (`trip-status-{{ $x }}`) safelist'e girmezse derlemeden düşer.
- Volt bileşen dosyasında aynı metod iki kez tanımlanırsa PHP fatal verir; trait'e taşınan metodları dosyadan sil.
- MariaDB'de DDL işlemsel değildir; yarım kalan migration ikinci çalıştırmada "already exists" der → `hasTable` koruması.
- `STDERR` sabiti `php artisan serve` altında yoktur; hata ayıklama için `Log` kullan.
- Playwright'ta `getByPlaceholder` gibi seçiciler iki kutuda (çıkış/varış) çift eşleşir; `.first()` kullan.
  Depodaki hazır denetim betiği: `scripts/mobile-audit.cjs` (`PW_MODULE` ile Playwright yolu verilir).

## 10. Bekleyen fikirler (Osman onaylarsa)

- "Merkezim" (şoförün ev/park adresi) ve yol üstü parça yük önerisi.
- Konuma göre anlık bildirim (Paket C) ve PWA / Play Store — mobil uygulama ile birlikte, şimdilik ertelendi.
- Anlık iletim (Laravel Reverb) yerine 15 sn'lik "N yeni ilan · Göster" düzeni yeterli bulundu.
