# Canlıya geçiş planı ve eksikler (2026-10-05 gece incelemesi)

Osman'ın sorusu: "Bu hafta ödeme (iyzico), Netgsm, Rota Bulut ERP ve Tamamliyo sigortasını tamamlayınca sistem tamamen hazır
oluyor mu, atladığım bir şey var mı?" Üç ayrı kod incelemesinin (ödeme/SMS, fatura/sigorta, yasal/işletim/ürün) birleşik sonucu.

## 1. Kısa cevap

Hayır, dördü tek başına yetmez. Dördü de gerekli, ama ikisi (ERP ve sigorta) **canlıya çıkmak için şart değil**, sonradan eklenebilir.
Canlıya çıkmayı asıl engelleyen, listede olmayan **altı konu** var:

1. **Yük sahibi doğrulama kuralı kodda yok.** Karar (TC+NVİ / VKN+GİB; doğrulanmamış yük sahibi teklif kabul edemez; şoföre rozet) hiç
   uygulanmamış. GİB sorgusu gerçek değil (yalnız VKN sağlaması), NVİ "eşleşmedi" dese de kayıt devam ediyor, doğum yılı kaydedilmiyor.
2. **Açık adres ve yük sahibinin adı herkese açılıyor.** Telegram kanalı ve şoför kartı tam adresi ve bireysel yük sahibinin adını
   basıyor (KVKK). Çözüm, yapılandırılmış ilan formu (il/ilçe seçici + gizli açık adres).
3. **KVKK metninde yurt dışına aktarım yok.** Grup mesajları telefon numaralarıyla yurt dışındaki yapay zeka sağlayıcılarına gidiyor;
   aydınlatma metninde yazmıyor. Sözleşmeler kodda olmayan şeyleri anlatıyor (selfie, OCR, U-ETDS, mesajlaşma, PWA konum).
4. **Defter (muhasebe kayıtları) güvenilmez:** yük sahibi hizmet bedeli gelire geçmiyor, komisyonda KDV ayrımı yok, hatalar yutuluyor,
   yük sahibine hiç fatura kesilmiyor. ERP bağlanmadan önce bu düzelmeli, yoksa ERP yanlış rakam alır.
5. **Premium satın alma yolu yok:** ödeme açılana kadar şoför ürünün ana değerini (dış kaynak ilanları) göremiyor; açılış kampanyası /
   belge onayında deneme süresi gerekiyor.
6. **Depo herkese açık, imza anahtarı içinde** (karar verildi, sırada).

Bunların dışında kesin hata olan beş küçük şey bu gece düzeltildi (bkz. §6).

## 2. Osman'ın dört maddesi: durum ve eksik

### 2.1 iyzico ödeme (bu hafta)

**Kodda var:** ödeme formu, pazaryeri (alt üye işyeri kaydı, şoför payının ayrılması, teslimatta kalem onayı), iade, webhook, hazırlık
listesi, 40'a yakın test. **Gerçek bir sandbox ödemesi hiç yapılmadı.**

Osman'dan gerekenler (sözleşme + anahtar):
- iyzico **Pazaryeri** sözleşmesi; canlı API ve gizli anahtar (Panel → Ödeme altyapısı; sandbox kutusu kapalı, "Pazaryeri ürünü aktif" açık).
- iyzico panelinde sunucu bildirimi adresi: `https://navluniq.com/odeme/bildirim/iyzico`.
- Önce sandbox anahtarıyla uçtan uca deneme: ödeme → teslimat onayı → şoföre aktarım → iade (ben yönlendiririm, sen tıklarsın).

Kodda yapılacaklar (sandbox denemesi sırasında/sonrasında, 1-2 gün):
- Şirket türündeki şoför için **vergi dairesi** alanı (iyzico `PRIVATE_COMPANY` kaydı vergi dairesi istiyor; bugün boş gidiyor).
- Alt üye işyeri **güncelleme** (bugün IBAN/kimlik değişince yeni kayıt açılıyor) ve ödenmiş kalemin alt üyesini değiştirme.
- Webhook imza doğrulaması (`X-IYZ-SIGNATURE-V3`) ve webhook adresine istek sınırı.
- Kalem onayı geri alma (disapprove); iade isteğindeki sabit IP yerine gerçek IP.
- Şoföre "paranız hesabınıza geçti" yerine "iyzico hesaplaşma takvimine göre aktarılır" metni; iyzico raporuyla mutabakat (sonra).
- Premium (abonelik) kaleminin pazaryeri hesabında alt üyesiz geçip geçmediğini sandbox'ta görmek.
- ✅ Sağlık ekranı "Ödeme kuruluşu" ışığı artık pazaryeri kapalıyken yeşil yanmıyor (bu gece düzeltildi).

### 2.2 Netgsm SMS (bu hafta)

**Kodda var:** yalnız gönderim sınıfı; **hiçbir yerden çağrılmıyor.** Doğrulama kodu yalnız e-postayla gidiyor; e-posta düşerse kimse
giriş yapamıyor (yöneticiler dahil). Telefon doğrulama akışı yok.

Osman'dan gerekenler: Netgsm hesabı (kullanıcı kodu, şifre), onaylı mesaj başlığı ("NavlunIQ"), hesapta IP kısıtı varsa sunucu IP'si.

Kodda yapılacaklar (1-2 gün):
- Panel → Ayarlar'a Netgsm alanları (şifreli), "SMS doğrulama açık" anahtarı, deneme SMS düğmesi; `.env` geri düşüşü.
- Kod gönderiminde kanal seçimi ve **yedekleme**: SMS başarısızsa e-posta, e-posta başarısızsa SMS; ekran metinleri kanala göre.
- Kayıtta/profilde telefon doğrulama adımı (ayrı kod bağlamı), `phone_verified_at`, rozet; gerekirse ilan/teklif için şart.
- Netgsm REST v2 ucuna geçiş (bugünkü eski GET ucuna form-POST atılıyor), SMS başına günlük sınır (maliyet), sağlık ekranına SMS ışığı, testler.

### 2.3 Rota Bulut ERP / e-fatura (görüşme)

**Kodda var:** `invoices` tablosu ve iki yerde numarasız "bekleyen" satır (şoföre komisyon faturası, premium faturası). **Yok:** yük
sahibine hizmet bedeli faturası, fatura kalemi/alıcı bilgisi/ETTN/PDF alanları, sağlayıcı arayüzü, kesme işi, iptal/iade belgesi,
yönetici işlemleri (yeniden dene, numara gir, PDF), muhasebeci CSV'si.

Görüşmede sorulacaklar (Osman):
- API ile e-Arşiv ve e-Fatura kesme var mı; **mükellef sorgusu** (alıcı e-Fatura mükellefi mi) API'de var mı; iptal/iade belgesi; PDF indirme;
  sandbox hesabı; fiyat (belge başına mı, aylık mı).
- Muhasebeciyle karar: pazaryeri modelinde platform yalnız **hizmet bedeli** (yük sahibine) ve **komisyon** (şoföre) faturalar; navlunun
  kendisini faturalamaz. Şoför için gider pusulası gerekip gerekmediği.

Kodda yapılacaklar (3-4 gün, sandbox gelince): önce **defter düzeltmesi** (hizmet bedeli gelire + KDV, komisyonda KDV ayrımı, kayıtlar
aynı veritabanı işleminde, `finance:reconcile`), sonra fatura sağlayıcı katmanı (ödeme geçidiyle aynı desen: sözleşme + yönetici + Null +
Rota adaptörü), `invoice_items` + alıcı anlık görüntüsü + fatura adresi alanları (profil), kesme işi ve yeniden deneme, iptal/iade, panel,
kullanıcıya PDF ve bildirim, yük sahibine ödeme dekontu.

**Canlıya çıkış için şart değil:** sandbox gelene kadar muhasebeci bekleyen satırlardan elle e-arşiv keser (bugünkü plan).

### 2.4 Tamamliyo sigorta (görüşme)

**Kodda var:** yalnız üç boş tablo (teklif, poliçe, hasar) ve `payment_orders.insurance_amount` sütunu. Servis, ekran, ayar, ilanda mal
bedeli alanı yok. Sözleşme ve SSS "sigorta seçeneği sunulduğunda" diyor; söz verilmiyor.

Görüşmede sorulacaklar (Osman):
- Gömülü sigorta API'si: teklif → poliçe → sertifika PDF; iptal ve prim iadesi; hasar bildirimi; sandbox.
- **Primi kim tahsil eder?** NavlunIQ para tutmaz kararıyla çelişmemesi için ya Tamamliyo iyzico'da ayrı alt üye olur ya da primi kendi
  sayfasında tahsil eder. Bu, entegrasyonun biçimini belirler.
- Emtia sınıfları (bizim yük türlerimizle eşleme), en düşük/en yüksek sigorta bedeli, BSMV, platform komisyonu, sözleşmeye konacak
  "sigorta aracı tarafından düzenlenir" ibaresi.

Kodda yapılacaklar (4-5 gün, API gelince): sağlayıcı katmanı, ilanda mal bedeli, ödeme adımında seçim + "Sigorta primi (BSMV dahil)"
satırı, ödeme sonrası poliçe düzenleme + sertifika, iptal/iade kuralı, hasar kaydı, panel, KVKK metnine veri aktarımı. **Yapılandırılmış
ilan formuna (il/ilçe) bağımlı.** Canlıya çıkış için şart değil.

## 3. Listede olmayan engelleyiciler (kod; yayından önce)

| # | Konu | Ne yapılacak | Emek |
|---|---|---|---|
| E1 | Doğrulanmamış yük sahibi teklif kabul ediyor | `OfferService::accept` ve ilan yayınında yük sahibi doğrulama şartı; panel ayarıyla (varsayılan açık) | 1 gün |
| E2 | "Doğrulanmış yük sahibi" rozeti yok | Şoför ilan kartında ve teklif ekranında rozet; bireysel yük sahibi "Ad S." | ½ gün |
| E3 | GİB doğrulaması gerçek değil | Karar: e-Fatura mükellef sorgusu (Rota API'sinde varsa) ya da vergi levhası + elle onay; `gib_verified` yazılsın | 1 gün |
| E4 | NVİ sessiz, doğum yılı kaydedilmiyor | Eşleşmezse kayıt durur / "belge kontrolü" durumuna düşer; `birth_year` yazılır (✅ XML kaçışı bu gece) | ½ gün |
| E5 | Yük sahibinden kimlik fotoğrafı isteniyor (karar: istenmez) | `KycDocument::CARGO_OWNER_REQUIRED` bireyselde boş, kurumsalda vergi levhası | ½ gün |
| E6 | Açık adres ve ad herkese açık | Telegram ve kartlarda il/ilçe; açık adres yalnız ödeme sonrası atanmış şoföre | E7 ile |
| E7 | İlan formu serbest metin adres | Yapılandırılmış form: il/ilçe seçici, gizli açık adres, saat penceresi, irtibat, araç adedi, çok durak; adres defteri de | 3 gün |
| E9 | KVKK metninde yurt dışı aktarım yok; tek onay kutusu | Aydınlatma metnine yapay zeka/e-posta/Telegram sağlayıcıları ve KVKK m.9; aydınlatma ile açık rıza ayrı; konum için ayrı rıza | 1 gün + hukukçu |
| E10 | Sözleşmeler kodda olmayan özellik anlatıyor | Selfie/OCR/U-ETDS/mesajlaşma/PWA/dil cümleleri çıkar; "20 dakika" yer tutucuya bağlan; madde sırası | ½ gün + hukukçu |
| E8 | Mesafeli satış bağlantısı 404 | ✅ bu gece düzeltildi | — |
| E11 | Yeniden onay penceresinde "Çıkış yap" çalışmıyor | ✅ bu gece düzeltildi | — |
| E12 | İmza anahtarı açıkta | Depo gizlenince anahtar yenileme + APK özeti panelde | ½ gün |
| P4 | Defter güvenilmez | §2.3'teki defter düzeltmesi (ERP'den önce) | 1-2 gün |
| Ö7 | Premium satın alma yolu yok | Belge onayında otomatik deneme süresi (ayar), açılış kampanyası; ödeme açılınca satın alma | 1 gün |

## 4. Önemli (yayın haftası)

- **İYS:** rıza dışa aktarımı (panelden CSV) ve pazarlama e-postasında `List-Unsubscribe` başlığı. İYS kaydı ve entegratör Osman'da.
- **Belge son kullanma takibi:** şoför belgelerinde tarih, `kyc:expiring` (30/7 gün), onaylı şoförün belge yenileyebilmesi, araç başına ruhsat.
- **Yönetici araçları:** işlem kaydı görüntüleyici, kullanıcı detay sayfası, mesaj dizili destek talebi.
- **Yedek:** şifreli zip + dış kopya (Drive/S3); bir kez geri yükleme tatbikatı.
- **Sunucu:** `.env`'de `LOG_STACK=daily`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true` (install.sh yazıyor, update.sh dokunmuyor; kontrol).
- **CSP** rapor kipinden zorunlu kipe (raporlar temizse).
- **Bakım süresi:** güncellemede 3-6 dk bakım sayfası; derlemeyi ayrı dizinde yapıp anlık geçiş (I3).
- **Telefon başına alım anahtarı** (I13), **bildirim e-postaları kuyruğa** (I20).

## 5. Sonra (ilk ay)

Çerez politikası metni (banner gerekmiyor: üçüncü taraf çerez yok), KVKK başvuru formu + saklama-imha politikası, VERBİS eşiği kontrolü,
ilanın yayından sonra düzenlenmesi, teklif sınırları, "tamamlayan" kaydı, Paket C4 (indeks, budama, enum, durum makinesi testleri),
outbox tablosu kararı (kaldır), mobil uygulama kabuğu.

## 6. Bu gece düzeltilenler (kod, PR'da)

- Premium ödeme sayfasındaki mesafeli satış bağlantısı 404 veriyordu (yanlış adres).
- Sözleşme yeniden onay penceresinde "Çıkış yap" düğmesi iç içe form yüzünden onayı gönderiyordu.
- Yapay zeka komutu "sabit hat yazma" diyordu; sabit hat / 0850 / 444 kararıyla uyumlu hale getirildi (komut sürümü artırıldı).
- Sağlık ekranı "Ödeme kuruluşu" ışığı pazaryeri ürünü kapalıyken yeşil yanıyordu; artık sarı ve sebebini yazıyor.
- NVİ sorgusunda ad/soyad XML'e kaçışsız gidiyordu (& ya da < içeren ad isteği bozardı).
- Eski belge satırları (ödeme belgesi ve README'deki webhook adresi) düzeltildi.

## 7. Önerilen sıra (bu hafta)

1. **Pzt-Sal:** iyzico sandbox uçtan uca deneme (Osman anahtar + sözleşme) ↔ kodda vergi dairesi, alt üye güncelleme, webhook imzası.
   Paralel: E1-E5 yük sahibi doğrulama paketi (kod).
2. **Çar:** Netgsm (Osman hesap) ↔ kodda kanal yedeği + telefon doğrulama + panel.
3. **Per:** E7/E6 yapılandırılmış ilan formu (adres gizliliği) ve E9/E10 metin düzeltmeleri → hukukçuya.
4. **Cum:** P4 defter düzeltmesi; Rota ve Tamamliyo görüşmelerinin sonucuna göre entegrasyon tasarımı.
5. **Yayın öncesi son gün:** `docs/CANLIYA_HAZIRLIK_INCELEMESI.md` §8 yol haritası (yedek, tatbikat, sağlık yeşil, 1 ₺ gerçek ödeme + iade).

Osman'ın dış işleri toplu liste: iyzico pazaryeri sözleşmesi ve anahtarlar + webhook adresi · Netgsm hesabı ve başlık · Rota Bulut API/sandbox ·
Tamamliyo API/sandbox ve tahsilat modeli kararı · hukukçuya sözleşme onayı · depoyu gizleme (önce `deploy/github-erisim.sh`) · GitHub
Billing (CI) · Brevo SMTP + SPF/DKIM/DMARC · İYS kaydı · ETBİS kodu · UptimeRobot `/up` · Telegram uyarı sohbeti · sunucu `.env` kontrolü ·
bir kez geri yükleme tatbikatı · iyzico inceleme kodunu silme.
