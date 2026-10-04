# Canlıya hazırlık incelemesi (2026-10-04)

Paket A, Paket B ve ilan hattı düzeltmelerinden sonra sitenin **tüm modülleri** altı ayrı gözle baştan sona tarandı:
yük sahibi akışı, şoför akışı, yönetici paneli, para modeli, işletim/altyapı, veri modeli ve durum geçişleri.
Her bulgu kodda doğrulandı; en kritik üçü (durum makinesi) test yazılarak kanıtlandı ve **aynı gün düzeltildi** (§1).
Geri kalanı önerilen çözüm, emek tahmini (K: saatler, O: 1-2 gün, B: 3+ gün) ve öncelikle burada listelenir.
Osman'ın kararıyla sırayla uygulanır.

Okuma kılavuzu: §1 bugün yapılanlar · §2 **Osman'ın karar vermesi gereken 5 konu** · §3-§6 öncelik paketleri ·
§7 durum tabloları · §8 yayın günü yol haritası.

---

## 1. Bugün düzeltilenler (test edilerek, PR'da)

| # | Hata | Etkisi | Düzeltme |
|---|------|--------|----------|
| V1 | Ödenmeyen kabul geri açılınca ("Vazgeç" ya da 24 saat ödeme süresi dolunca) ilan **bir daha hiç atanamıyordu**; ikinci kabul veritabanı hatasıyla düşüyordu (ilan başına tek sevkiyat kuralı iptal edilmiş satırla çakışıyordu). Paket A ile gelen bir regresyondu. | İlan ölüyor, yük sahibi hata görüyor | Kabulde iptal edilmiş sevkiyat satırı yerinde yeni şoföre sıfırlanır; test eklendi. |
| V4 | Aynı ilan için ikinci bir ödeme emrinin "başarılı" bildirimi (ör. hizmet bedeli ayarı değişince açılan ikinci emir, ya da başarısız emrin geç gelen başarısı) havuzu ikinci kez dolduruyordu; **yük sahibinden iki kez para çekiliyor, iade yok**. | Çift tahsilat | Havuz zaten doluysa ikinci tahsilat yetim sayılır ve kendiliğinden iade edilir; tutar değişince eski açık emirler kapanır. |
| V3 | Finans ekranındaki "Başarısız" düğmesi ödenmiş hakedişi de "başarısız" yapabiliyordu (kilit ve durum koruması yoktu). | Para gitmişken "ödenmedi" kaydı ve yanlış mail | Yalnız bekleyen/işlemdeki hakediş başarısız olur; neden ayrı alanda. |
| V5 | Teklif geri çekme / ret / süre dolumu "oku sonra yaz" idi; aynı anda kabul edilen teklif "geri çekildi" ya da "süresi doldu" olabiliyordu. | Kabul edilmiş teklif kayboluyor | Koşullu yazım (yalnız hâlâ bekliyorsa). |

Toplam 567 test geçiyor.

---

## 2. Osman'ın karar vermesi gereken konular

1. **Para hangi hesapta durur?** Bugünkü varsayılan: tüm navlun NavlunIQ'nun iyzico hesabına girer, teslimattan sonra finans
   şoföre havale eder. Bu, NavlunIQ'nun üçüncü kişi parası tutması demektir (ödeme kuruluşu lisansı alanı) ve sözleşme
   metni tersini söylüyor. **Öneri:** iyzico Pazaryeri (alt üye işyeri) modeli tek canlı yol olsun: şoförün TC/VKN'si ve
   IBAN'ı belge aşamasında alınır, ödeme iyzico'da şoförün payıyla ayrışır, NavlunIQ yalnız komisyon + hizmet bedeli +
   premium cirosu görür. Bunu muhasebeci ve iyzico ile konuşup karara bağlamak gerekir; **pazaryeri olmadan escrow ile
   yayına çıkılmamalı** (premium-yalnız yayın bir ara seçenek).
2. **Depo herkese açık ve Android uygulamasının imza anahtarı deponun içinde.** Herkes aynı imzayla sahte bir "güncelleme"
   üretip telefona kurdurabilir; uygulama WhatsApp bildirimlerini ve ekranı okuyor. **Öneri:** depoyu gizli yap, imza
   anahtarını depodan çıkar ve yenile (telefonlarda uygulama bir kez silinip yeniden kurulur), APK özetini panelde göster.
   Depo gizli kalacaksa doküman ve betikler de orada rahatça durur.
3. **Yük sahibi belgesi zorunlu mu?** Metinler "teklif kabul ve ödeme için gerekli" diyor, kod hiç bakmıyor; şoför de yük
   sahibinin doğrulandığını görmüyor. **Öneri:** ayarla açılıp kapanan zorunluluk (varsayılan açık), kartlarda "Belgeleri
   doğrulandı" rozeti, doğrulanmamış yük sahibine en çok 3 aktif ilan.
4. **İade ve iptal politikası.** Sözleşme "tek tıkla iptal, bedel iade" diyor; kod ödeme sonrası iptale izin vermiyor.
   Şoför gelmezse yük sahibinin hiçbir düğmesi yok. **Öneri:** yola çıkılmadan önce yük sahibine "iptal et ve iade al"
   (iyzico kesintisini kim öder, komisyon iade edilir mi yazılı karar), şoföre ödeme sonrası vazgeçme + otomatik iade,
   "şoför gelmedi" zaman aşımı; metinler ayarlardan saat okur.
5. **Pazarlama e-postası.** Panelde "Pazarlama duyurusu" tüm kullanıcılara rıza ve abonelikten çıkma bağlantısı olmadan
   gönderiyor (İYS/ETK kapsamı). **Öneri:** kayıtta ayrı pazarlama rızası + abonelikten çık bağlantısı gelene kadar
   sekme "Duyuru (hizmet bildirimi)" olarak yalnız süper yöneticiye açık.

---

## 3. Paket C1 — Para ve sevkiyat mantığı (yayın engelleyici; önce bunlar)

| # | Bulgu | Önerilen çözüm | Emek |
|---|-------|----------------|------|
| K1 | **Yolda açılan uyuşmazlık her kararda sevkiyatı "tamamlandı" yapıyor.** Şoför lehine karar → yük kamyondayken hakediş açılır, takip biter, POD yüklenemez; yük sahibi lehine → taşıma sürerken tam iade. | Karar seçenekleri sevkiyat durumuna bağlı: teslim edilmemişse "Sevkiyat devam eder" (uyuşmazlık kapanır, yola dön) ya da "İptal + iade"; "şoföre öde" yalnız teslim edildiyse. Admin ekranı duruma göre. | O |
| V2 | Yük sahibi lehine karar + iade henüz onaylanmamış → ilan "Tamamlandı" görünür, teslimat sayılır, puanlanabilir. | Havuz durumu `refund_pending` ("İade bekleniyor"); istatistik ve puan dışı; finans "İade yapıldı" ile kapanır. | K |
| P3 | Hakediş oluşturma yeniden denemesiz: teslimat onaylanmış ama hakediş satırı yok ise kimse ödenmez; "işlemde" takılı kalabilir. | `payouts:reconcile` (10 dk): hakedişsiz onaylı ilanlar, 15 dk'dan eski "işlemde", gateway yeniden deneme; `payouts.load_id` benzersiz; sağlık satırı. | K-O |
| P4 | Defter güvenilmez: kayıt hataları yutuluyor, komisyonda KDV ayrımı yok, yük sahibi hizmet bedeli havuz borcunda takılı, tutar uyuşmazlığı defterde yok, mutabakat yok. | Defter kaydı aynı veritabanı işleminde; hakedişte tam dağılım (şoför alacağı, komisyon tabanı, hizmet bedeli, KDV); hizmet bedeli faturası; günlük `finance:reconcile` + sağlık satırı; muhasebeci için CSV. | O |
| P5/A6 | Komisyon oranı hakediş anında okunuyor; şoför teklif verirken net kazancı görmüyor. | Oran ve tutarlar ödeme emrinde dondurulur; teklif penceresinde "size kalan". | K |
| P6 | Tutar uyuşmazlığında çekilen para askıda kalıyor (iade denenmiyor). | Yetim ödeme gibi: defter + iade; reddedilirse `refund_pending`. | K |
| K5/H4 | Ödenmiş ilanda şoför gelmezse yük sahibinin aksiyonu yok; zaman aşımı yok. | Zamanlayıcı (yükleme tarihi + 1 gün geçti → iki tarafa ve operasyona uyarı); yük sahibine "Şoför gelmedi — iptal ve iade talebi" (tipli istek, admin tek tık); şoföre ödeme sonrası vazgeçme + iade. | O |
| A2/M8 | Hesap silme bekleyen teklifleri ve onaylı profili bırakıyor; iade bekleyen emir varken silinebiliyor. | Silmede teklifler geri çekilir, dış seferler kapanır, belge durumu sıfırlanır; `refund_pending` emir varken silinemez; kabul/teklif silinmiş hesabı reddeder. | K |
| A3 | Araç canlı işaretçi: teklif "TIR" ile gösterilip kabulden önce kamyonete çevrilebilir; atanmış aracın plakası sonradan değiştirilebilir. | Teklifte araç anlık görüntüsü (`offers.vehicle_id`), sevkiyat tekliften alır; açık sevkiyata bağlı araç düzenlenemez. | O |
| Y3 | Yönetici "Askıya al" sürücü seferini kapatmıyor, sürücüye haber vermiyor. | `LoadService::adminSuspend` = iptal gövdesi (emirler, teklifler, sevkiyat, sefer, iki tarafa bildirim). | K |
| K7/H6 | Kabul diğer teklifleri kalıcı reddediyor; ilan yeniden açılınca kimseye haber yok; yük sahibi kabul-iptal döngüsüyle şoför gezdirebilir. | `outbid` durumu; yeniden açılınca "tek dokunuşla yenile" bildirimi; kabul sonrası iptal sayacı ve soğuma. | O |
| A5 | IBAN'sız hakediş açılıyor, "IBAN'ınıza yapılacak" deniyor; başarısız hakediş cüzdandan kayboluyor. | IBAN yoksa teklif öncesi uyarı, "Yola çıktım"da engel; "Düzeltme bekleyen" satırı + "IBAN'ı güncelledim, yeniden gönder". | K-O |
| P7 | IBAN değişikliği şifresiz, bildirimsiz, doğrulamasız. | Profil güvenliği kalıbı (şifre + e-posta); finans satırında "IBAN değişti" rozeti; değişiklikten sonra 24 saat otomatik transfer beklet. | K |
| P8 | Ödenmiş hakedişi olan şoför hesabını silince veritabanı hatası (banka hesabı bağı). | Hakedişte IBAN son 4 + hesap sahibi saklanır, bağ boşaltılır. | K |
| P9 | Yasal metinler kodla çelişiyor (24 saat otomatik onay ↔ ayar 72; "tek tıkla iptal"). | Metinler `{{AUTO_APPROVAL_HOURS}}` gibi yer tutucularla ayardan okur; iptal politikası §2-4 kararına göre. | K |
| P10 | PayTR adaptörü canlıda seçilebilir, anahtarları `.env`'den, test kipi varsayılan açık → **parasız "ödendi"**. | Sözleşme yokken seçimden kaldır (sınıf kalsın); test kipi + canlı ortam → ödeme kapalı. | K |
| P11 | Abonelik: hediye satırı bitince yanlış "sona erdi" maili; premium geri alma ücretli aboneliği de iptal ediyor; ay ekleme taşması. | `premium_until` ileri ise bildirim yok; geri alma yalnız hediye (ya da iadeli); `addMonthNoOverflow`. | K |
| P12 | Açık ödeme emirleri hiç süresi dolmuyor; sonuç sayfası iade/zaman aşımında "çekim yapılmadı" diyor. | `payments:expire-stale` (24 sa); "ödeme doğrulanamadı; çekim olduysa iade edilir". | K |
| M1 | NVİ uyuşmazlığı sessiz kabul; doğum yılı hiç kaydedilmiyor (admin "doğum yılı eksik"). | Doğum yılı kaydı; NVİ "eşleşmedi" ise kayıt durur; servis hatasında inceleme işareti. | K |
| M7 | Kurumsal yük sahibi iyzico'ya sahte TC ile gidiyor; adres olarak yükleme metni. | VKN gönder; fatura adresi alanları (profil). | K |
| M2/A19 | Puanlama "teslim edildi"de açık, iade sonrası kalıyor. | Yalnız "tamamlandı" ve iade ile kapanmamışsa. | K |
| M3/M4 | Yük sahibi "Yükü teslim aldım" diyemiyor (şoför POD yükleyemezse havuz takılır); uyuşmazlığı geri çekemiyor. | Yük sahibi onayı = teslim + tamamlandı; "Uyuşmazlığı geri çek" (önceki duruma dön). | K-O |
| A1 (şoför) | Uyuşmazlık açıkken şoför POD/konum yükleyemiyor (servis izin veriyor, ekran göstermiyor). | Ekranda formu "uyuşmazlık açık; kanıt hakeme gider" uyarısıyla göster. | K |

---

## 4. Paket C2 — Güvenlik ve işletim

| # | Bulgu | Önerilen çözüm | Emek |
|---|-------|----------------|------|
| I2 | **APK imza anahtarı + parolası herkese açık depoda** (bkz. §2-2). | Depo gizli; anahtar depodan çıkarılıp yenilenir; `build.sh` anahtarı ortamdan okur; APK sha256 panelde; `android.jar` indirmesi özetle sabitlenir. | O + telefonlarda yeniden kurulum |
| I1 | Hiç dış uyarı yok; sağlık yalnız panel açılınca görünür; `/up` sayfası veritabanına/zamanlayıcıya bakmıyor. | `system:watchdog` (5 dk): kuyruk ve zamanlayıcı nabzı, başarısız iş, son yedek, disk, telefon sessizliği, mail hatası, AI kota, Redis, TLS bitişi → **Telegram'a Osman'a mesaj** + yönetici bildirimi; `/up` gerçek sağlık döner; ücretsiz dış izleme (UptimeRobot). | O |
| I3 | Her güncelleme 3-6 dk bakım sayfası; **ödeme geri çağrısı bakım sayfasına çarpıyor** (para çekilmiş, ilan "ödenmedi"). | K: ödeme uçları bakımdan muaf, sınıflandırma güncelleme bitince arka planda. O: derleme bakım öncesi ayrı dizinde, bakım 20-40 sn, geri alma = dizin değiştirme. | K + O |
| I4 | Kuyruk yeniden deneme süresi (90 sn) iş zaman aşımından (120 sn) kısa → aynı mesaj iki işçide (çift AI, çift kayıt). | `retry_after` 180; supervisor `--tries` kaldır; nabız işi tekil. | K |
| I5 | Yedekler: aynı hasta diskte 28 kopya (iki ayrı gece yedeği), dış kopya yok, şifre yok, hata bildirimi yok, en kötü 24 saat kayıp. | Tek yol (panel yedeği, 7 gün) + 6 saatte bir veritabanı dökümü; hata → bildirim; AES-256 şifreli zip (panelden parola, bir kez gösterilir); dış kopya (S3 uyumlu ücretsiz katman) panel ayarlarıyla; çeyreklik geri yükleme tatbikatı. | O |
| Y1 | Gizli ayarı "eski değere dön" maskeli `••••abcd` metnini gerçek anahtar yapıyor → SMTP/ödeme/Telegram sessizce bozulur. | Gizli anahtarlar geri alınamaz, düğme gizli. | K |
| Y2 | Ödeme/mail/AI anahtarları ve ödeme sağlayıcısı her "ayar yöneticisi" tarafından yeniden kimlik doğrulamasız değişebiliyor; açık emir varken sağlayıcı değişimi serbest. | Ödeme sekmesi yalnız süper yönetici; şifre/kod ile "hassas işlem onayı"; her değişiklikte diğer yöneticilere bildirim; açık ödeme emri varken sağlayıcı değişmez. | O |
| Y5 | "Ayar yönetme" yetkisi fiilen tam yetki (deploy, yedek, kullanıcı kalıcı silme, firewall, sabit kod); "personel yönetme" finans hesabı açabiliyor; ölü "AI ayarları" izni. | `manage system` (yalnız süper yönetici); personel oluşturma süper yönetici ya da kendi yetkisini aşamaz; ölü izin kaldırılır. | O |
| Y6 | Para kararları (uyuşmazlık, iade, hakediş ödendi) tek kişi, tutar işlem kaydında yok. | Eşik üstünde dört göz (öner → başka yönetici onaylar); tutarla bildirim ve kayıt. | O |
| Y4 | Kuyruktaki her "Reddet" sınıflandırıcıya "ilan değil" öğretiyor (§9'da "yalnız ilan değil retinden öğrenir" kuralı arayüzle deliniyor). | Ret gerekçesi açılır kutusu (varsayılan: öğrenmez). | K |
| Y19 | "Geçmişten yeniden öğren" tek tık. | Süper yönetici + "YENİDEN ÖĞREN" yazdırma + önceki istatistik kaydı. | K |
| I6 | Redis sert bağımlılık: düşerse giriş, kod ve telefon teslimleri 500. | Redis bellek sınırı + otomatik yeniden başlatma; watchdog ping; kilit/sayaçlarda hata toleransı. | K-O |
| I7/I8 | `install.sh` yeniden çalışınca siteyi http'ye düşürüyor; `LOG_LEVEL=error` uyarıları (yavaş istek, mail hatası) hiç yazmıyor. | https algılama; `warning`. | K |
| I9 | Zamanlayıcı kilitleri süresiz: sert kapanma sonrası dakikalık hat 24 saat durabilir. | Kilit süreleri (10/60/180 dk); çıktı günlüğe. | K |
| I10 | SMTP sertifika doğrulaması kapalı, zaman aşımı yok; SMTP asılınca PHP işçileri tükenir. | 15 sn zaman aşımı, doğrulama açık (panel anahtarı), FPM `request_terminate_timeout`. | K |
| I11 | CI yok; test/pint/altın set/bağımlılık denetimi otomatik çalışmıyor. | GitHub Actions (pint, test, altın set, composer/npm audit, derleme, sürüm eşitliği) + Dependabot; main korumalı. | K-O |
| I12 | Web kullanıcısı tüm kod ağacının ve `.env`'in sahibi (olası açıkta kalıcılık). | `root:www-data`, yazma yalnız storage/cache. | K |
| I13 | Tüm telefonlar tek süresiz anahtar; sınama bağlantısında anahtar adres satırında. | Telefon başına anahtar (ad, son görülme, IP, sürüm) + panel; "telefon sessiz" uyarısı; sınama için 10 dk'lık kod. | O |
| I14 | CSP yok. | Önce rapor modunda (Livewire/Alpine ile uyumlu politika), 1-2 hafta sonra zorunlu. | K |
| I15/Y20 | Telegram ve telefon anahtarları düz metin. | Şifreli saklama (`ENCRYPTED_KEYS`). | K |
| I16/I17 | Özel dosyalar aynı kökende satır içi açılıyor (PDF betiği); nginx: sürüm gizleme, gzip, `/storage/*.php` engeli, ikinci katman istek sınırı. | Sandbox başlığı + PDF indirme; nginx ekleri. | K |
| Y7 | Engelleme iş durumuna bakmıyor (yolda şoför, ödenmiş yük). | Açık işler listesi/engel ya da "engelle ve kapat"; `banned_by`; kullanıcıya e-posta. | O |
| Y10 | Deploy eşzamanlılık 30 dk eski kilit (yavaş diskte aşılır); panelden geri alma yok. | `flock` + süreç canlılığı; "önceki sürüme dön" düğmesi (süper yönetici). | O |
| Y11 | Yedek Livewire isteği içinde senkron (zaman aşımı, çift kayıt). | Kuyruk işi + ilerleme. | K-O |
| Y13 | Çöpte kullanıcı kalıcı silme finans bağlarını siliyor; `legal_*`/token geri alınabiliyor. | Kullanıcı kalıcı silme kaldırılır; kara liste. | K |
| Y17 | Yönetici "panel görünümü" gerçek işlem yapabiliyor (teklif, ilan, dış iş). | `is_staff_view` → işlem engeli + uyarı. | K |
| Y18 | Toplu kuyruk işlemleri sınırsız/senkron (2000 satır, AI yeniden çözüm). | 200 sınırı ya da kuyruk işi; kaynak temizliğinde ad yazdırma. | K |
| I19/I21 | Ölü Baileys ucu; bakım modunda yönetici için geçiş anahtarı yok. | Uç kaldırılır; `--secret` ile bakımı atlama bağlantısı günlükte. | K |
| A22 | Konum ucu sayısal `throttle` (paylaşımlı sayaç). | Adlı sınırlayıcı. | K |

---

## 5. Paket C3 — Ürün eksikleri ve kullanıcı deneyimi

| # | Bulgu | Önerilen çözüm | Emek |
|---|-------|----------------|------|
| K3/K4/M5 | **İlan formu serbest metin adres alıyor; il/ilçe yanlış çözülüyor** (doğrulandı: "Çankaya Caddesi … Bornova/İzmir" → Ankara Çankaya; "Kadıköy/İstanbul" → il yok). Açık adres ve yük sahibi adı Telegram'da ve tüm şoförlerde yayılıyor. Açıklama, saat penceresi, saha irtibatı, araç adedi, çok durak yok. | **Yapılandırılmış ilan formu:** il/ilçe seçici + ayrı özel açık adres (ödeme sonrası atanmış şoföre), açıklama, saat penceresi, irtibat (adres defterinden), araç adedi, çok durak; yük sahibi kartlarda "Kurumsal · doğrulandı" / "Ad S.". | B |
| K2 | Yük sahibi doğrulama kademeleri (bkz. §2-3). | Ayar + rozet + doğrulanmamış sınırları. | O |
| A4/A9/Y8 | Yeni araç belge incelemesinden kaçıyor; ruhsat plakaya bağlı değil; belge son kullanma tarihi toplanıp uygulanmıyor; onaylı şoför belge yenileyemiyor. | Araç başına incelenen ruhsat; teklif için ≥1 onaylı araç; tarihli belgelerde son kullanma + `kyc:expiring` (30/7 gün) + "Yenile" yükleme; admin KYC'de araçlar. | O-B |
| A7 | Eksik ilanı herhangi premium şoför herhangi araçla "tamamlıyor"; geri alınamaz; yanlış etiket kesin sayılıyor. | Tamamlayan kayıtlı; 10 dk düzeltme; 2 bağımsız onaydan sonra kesin; 20/gün; admin geri al. | O |
| A8 | "Bu işi aldım" sınırsız (300 açık sefer → her 10 dk tarama). | En çok N açık dış sefer (ayar 5); arşivlenen ilanın seferi kapanır. | K |
| A10 | Ret sonrası sınırsız yeniden teklif, her seferinde e-posta. | Ret sonrası en çok 1; geri çekme sonrası 10 dk; tekrarlarda mail yok. | K |
| A23/M9 | Teklif süresi yükleme tarihine bakmıyor; süresi dolmuş teklifler kabul edilebilir görünüyor. | Süre = min(yükleme tarihi, +N gün) + "Yenile"; süresi dolanlar "Kapanmış" altında. | K |
| M6 | Yayın sonrası düzenleme yok. | Aktifken düzenleme; fiyat/tarih/araç değişirse teklifler düşer ve bildirilir. | O |
| M11 | Yük sahibine makbuz yok; fatura tablosu boş. | Ödeme dekontu (PDF/yazdır); e-arşiv entegrasyonu Paket C muhasebe maddesi. | K |
| A12/A13/A14/A15/A16/A17/A18/A20/A21 | Şoför tarafı küçükler: ölü tercihler, yanlış sekme bağlantısı, Tekliflerim'de ilan detayı yok, dış sefer kartında telefon yok, hediye premium mesajında "20 dakika", cüzdanda ham etiketler, dış sekmede çalışmayan tarih çipi, yeniden paylaşılan ilanın listeden kayması, cüzdan "onay bekleyen" askıdakileri topluyor. | Tek tek küçük düzeltmeler. | K (toplu O) |
| M10/L1-L14 | Yük sahibi tarafı küçükler: sayaç anlamları, marka/model satırı, "00:00" saati, poll ile kaybolan mesajlar, plaka kabul öncesi tam, adres defteri serbest metin, destek talebi tek atış, "iade bekleniyor" etiketi. | Tek tek küçük düzeltmeler. | K (toplu O) |
| Y9/Y14/Y15/Y16/Y24/Y25 | Yönetici araçları: işlem kaydı görüntüleyici yok; destek talepleri tek cevap alanı (geçmiş kayboluyor), SLA yok; sağlık ekranında zamanlayıcı/yedek/disk/mail/telefon/AI kota probları yok; personel daveti/rol düzenleme yok; finansta arama ve `refund_pending`/işlemde sayaçları yok; kullanıcı detay sayfası (giriş geçmişi, oturum kapat, sıfırlama bağlantısı, KVKK silme) yok. | İşlem kaydı sayfası; mesaj dizili destek; sağlık probları; personel yaşam döngüsü; finans arama/sayaçlar; kullanıcı detay. | B (parçalanabilir) |
| Y21 | Ölü sekmeler/ayarlar (kupon, sayfalar rotasız, diller, bakım notu, site başlığı), çelişen metinler, yedek sayfasında sabit IP. | Yayından önce gizle/kaldır, metinleri düzelt. | K |
| Y12 | Pazarlama duyurusu (bkz. §2-5). | Rıza + abonelikten çık + kuyruk. | O |
| I20/K6 | Bildirimler ve e-postalar istek içinde senkron; her yayın tüm premium şoförlere coğrafyasız mail. | `SendNotificationMail` kuyruk işi; bölgeye göre bildirim; sahip başına ilan sınırları. | O |
| Ürün | Şoför: onboarding kontrol listesi, net kazanç, güvenilirlik puanı, aylık hakediş özeti, "Merkezim". Yük sahibi: teklif karşılaştırma (toplam maliyet, şoför geçmişi), fiyat rehberi (₺/km), kabul sonrası mesajlaşma (model var, ekran yok), bildirim özeti. Panel: huni ve iş KPI'ları. | Yayından sonra sıraya alınır. | B |

---

## 6. Paket C4 — Performans ve veri hijyeni

| # | Bulgu | Önerilen çözüm | Emek |
|---|-------|----------------|------|
| V7/V9 | Eksik benzersizlikler: `payouts.load_id`, `driver_trips.shipment_id`, açık dış sefer (şoför + ilan). | Migration (önce tekrarları temizle) + `firstOrCreate`. | K |
| V8 | Dış kaynak ilanı onayı (admin/toplu/otomatik) kilitsiz → çift onay, çift öğrenme. | Kilitli ya da koşullu yazım. | K |
| V6 | Teklif verirken kilit içinde e-posta gönderiliyor (SMTP yavaşsa ilan kilitli kalır). | Bildirim işlem sonrasına. | K |
| V10 | Sınırsız büyüyen tablolar: işlem kaydı (her ilan işlemi), kullanıcı bildirimleri (ilan başına tüm şoförler), hiç okunmayan `outbox_events`. | Günlük budama (90 gün), `outbox` kaldır; sağlıkta sayılar. | K |
| V11 | 100 bin+ dış ilanda yavaşlayacak sorgular: şoför listesi indeksi, kuyruk sekmesinde JSON sayımı, canlı akış sayfalama, sayaç yeniden hesapları, Facebook kesik birleştirme. | Bileşik indeks `(visibility, status, is_incomplete, last_seen_at)`; `auto_reject_kind` sütunu / 60 sn önbellek; `simplePaginate`; artan sayaç; 100 sınırı/ön ek özeti. | K-O |
| V12 | MySQL bağlantısında saat dilimi yok (sunucu saati); kabulde "süresi doldu" yazımı düzeltildi. | Bağlantıya `+03:00`; belgeye not. | K |
| V13 | Dış sefer durum geçiş matrisi yok (kapalı → planlandı istemciden); ölü durum etiketleri. | İzinli geçiş tablosu; ölü etiketler kaldırılır. | K |
| V14 | Plaka benzersizliği silinmiş araçları da kapsıyor (satılan araç yeni şoförde engelleniyor); ölü tablolar (conversation UI yok, sigorta, kupon, dispute_allocations, outbox). | Mesaj + politika; ölü tabloları karar sonrası kaldır. | K |
| V15 | Durumlar düz metin ('pending' 47 yerde), enum yok; yazım hatası yeni durum üretir. | PHP enum + model doğrulaması; görünümlerde sabitler. | O |
| V16 | Çift tık: dış iş alma ve puan vermede ikinci tık 500 veriyor. | `wire:loading` + benzersizlik yakalama. | K |
| Test | Durum makinesi test matrisi (L1-L12), admin test boşlukları (ayar gizlilik/geri alma, operasyon askıya alma, personel yetki, finans). | `tests/Feature/StateMachine`, `tests/Feature/Admin/*`. | O |

---

## 7. Durum tabloları (kodda olduğu gibi)

### İlan (`status` / `escrow_status`)

| Nereden | Tetikleyici | Nereye |
|---|---|---|
| — | yük sahibi yayınlar | `active_seeking` / `pending_payment`, herkese açık |
| `active_seeking` | teklif kabul | `driver_assigned` / `pending_payment`, ödeme süresi başlar, sevkiyat `awaiting_pickup`, sefer `planned` |
| `active_seeking` | iptal / askıya alma / yükleme tarihi geçti | `cancelled` |
| `driver_assigned` (ödenmedi) | şoför vazgeçti / ödeme süresi doldu | `active_seeking` (sevkiyat satırı iptal; **kabulde yeniden kullanılır — V1**) |
| `driver_assigned` (ödenmedi) | yük sahibi iptal (15 dk içinde ödeme girişimi yoksa) / askıya alma | `cancelled` |
| `driver_assigned` / `pending_payment` | doğrulanmış ödeme bildirimi, tutar uyuyor | `driver_assigned` / `paid_in_escrow` (**ikinci tahsilat yetim → iade, V4**) |
| `cancelled` | geç gelen ödeme | değişmez; emir iade edilir |
| `driver_assigned` / `paid_in_escrow` | yönetici "iptal et ve iade et" | `cancelled` / `refunded_to_owner` (iade reddedilirse `paid_in_escrow` kalır — V2 ile `refund_pending`) |
| `driver_assigned` / `paid_in_escrow` | şoför "Yola çıktım" | `on_the_way` |
| `on_the_way` | teslimat kanıtı | `delivered`, otomatik onay süresi başlar |
| `on_the_way` ya da `delivered` | yük sahibi uyuşmazlık | `disputed` / `on_hold` |
| `delivered` | yük sahibi onayı / otomatik onay | `completed` / `release_approved`, hakediş `pending` |
| `disputed` | karar şoför lehine | `completed` / `release_approved` (**yoldayken de — K1**) |
| `disputed` | karar yük sahibi lehine | `completed` / iade başarılıysa `refunded_to_owner`, değilse `on_hold` (**V2**) |
| `completed` / `release_approved` | hakediş ödendi | `completed` / `released_to_driver` |

### Teklif (`status`)

`pending` → `accepted` (kabul; diğer bekleyenler `rejected`) · `withdrawn` (şoför) · `rejected` (yük sahibi / ilan iptali) · `expired` (saatlik iş / yükleme tarihi) ·
`accepted` → `withdrawn` (vazgeçme) · `expired` (ödeme süresi) · `rejected` (ödenmiş ilan iptali). Kapalı teklif aynı şoför için yeniden kullanılır (geçmiş yazılmaz).
Kabul edilmiş teklif artık geri çekilemez/reddedilemez/süresi dolmaz (**V5**).

### Sevkiyat (`status`)

`awaiting_pickup` → `in_transit` (ödeme alınmışsa) → `delivered` (kanıt) → `completed` (onay) ·
`in_transit`/`delivered` → `disputed` → `completed` (karar) ·
`awaiting_pickup` → `cancelled` (geri açma / iptal; satır kalır, kabulde yeniden kullanılır).

### Hakediş (`status`)

`pending` (teslimat onayı) → `processing` (iyzico transfer) → `paid` · `processing` → `pending` (transfer hatası) ·
`pending`/`processing` → `paid` (finans "Ödendi") · `pending`/`processing` → `failed` (finans "Başarısız"; **ödenmişten değil — V3**) · `failed` → `pending`/`paid`.

---

## 8. Yayın günü yol haritası (panel düğmeleriyle)

**1-3 gün önce**
1. Yedekler → "Şimdi tam yedek al" → indir → bilgisayara ve Drive'a koy.
2. Bir kez geri yükleme tatbikatı (1 saatlik kiralık sunucu, `deploy/geri-yukle.sh --deneme`): site açılıyor mu, yönetici giriyor mu.
3. Sistem sağlığı: tüm satırlar yeşil; "Sabit kodla giriş: Kapalı"; kuyruk işçisi çalışıyor; başarısız iş 0.
4. Ayarlar → E-posta: deneme e-postası (Gmail + Outlook'a; spam'e düşüyorsa Brevo SPF/DKIM bitmeden yayın yok). Ödeme: canlı
   anahtar, test kipi kapalı, iyzico panelinde sunucu bildirimi adresi, **1 ₺ gerçek ödeme + iade**. Telegram: deneme mesajı.
   Kaynaklar ve telefon: iki telefondan bağlantı sınaması; eski MacroDroid makroları kapalı.
5. Sunucu (bir kez, kabuk): `LOG_LEVEL=warning`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`; `certbot renew --dry-run`;
   disk en az %30 boş; iki kuyruk işçisi çalışıyor; servisler açılışta başlıyor; çift gece yedeği cron satırı kaldırıldı.
6. Dış izleme: ücretsiz UptimeRobot → `https://navluniq.com/up` (I1 ile gerçek sağlık döner).
7. Telefon: Toplayıcı güncel sürüm, pil optimizasyonu kapalı, normal Facebook (Lite değil).
8. Hesaplar: süper yönetici şifresi güçlü ve tek kişide; inceleme kodu boş; deneme hesapları canlıda yok.

**Yayın günü:** sabah tam yedek; "Siteyi güncelle" yalnız boş saatte (I3 kapanana kadar); ilk 2 saat Canlı akış açık;
bir şoför + bir yük sahibi hesabıyla uçtan uca deneme (kayıt → kod → belge → onay → ilan → teklif → ödeme → yola çık → teslim → onay).
Sorun sırası: Sistem sağlığı → Canlı akış → Yedekler → günlük dosyası.

**İlk 48 saat:** günde 3 sağlık kontrolü; e-posta "başarısız" sayacı; dış kaynak hat karnesi (medyan açılış→yayın, "yapay zeka
bekliyor"); telefonların "son gönderim" saati; ikinci gün iki gece yedeği de "Hazır".

---

## 9. Önerilen uygulama sırası

1. **Bugün bitti:** V1, V3, V4, V5 (§1).
2. **Yayın öncesi zorunlu (C1 + C2'nin kritikleri):** K1, V2, P3, P5, P6, P10, Y1, Y2, Y3, Y4, Y5, Y17, I2 (karar), I3-K, I4, I5 (tek yol + bildirim + 6 saatlik döküm), I7, I8, I9, I10, I12, P7, P8, A2, A1(şoför POD), M2.
3. **Para modeli kararı (§2-1) ve P4 defter** — muhasebeciyle birlikte; pazaryeri açılmadan escrow ile yayın yok.
4. **Yayın haftası:** I1 watchdog + UptimeRobot, I11 CI, K5 "şoför gelmedi", A3 araç anlık görüntüsü, K7 outbid, A5 IBAN, Y6 dört göz, Y7 ban.
5. **Yayından sonra ilk ay:** C3 yapılandırılmış ilan formu ve yük sahibi doğrulama, belge son kullanma, yönetici araçları, C4 indeks/budama/enum, I5 şifreli yedek + dış kopya, I13 telefon anahtarları, I14 CSP, bildirim kuyruğu.
