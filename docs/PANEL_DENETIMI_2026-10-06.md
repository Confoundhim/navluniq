# Panel ve süreç denetimi (2026-10-06)

Osman'ın sorusu: "Sistem ilanlarında, canlı takipte, yük sahibi ve şoför panellerinde hata ya da eksik var mı; daha işlevsel ne yapılabilir;
yasal eksik var mı?" Üç ayrı salt okunur inceleme (sistem ilanı akışı + canlı takip, yük sahibi paneli, şoför paneli) ve yasal gözden
geçirme. Testler: tam paket 737/738 (tek hata ortamın PHP 8.3'üne özgü), ilgili alt paketler 102/102, 37/37, 20/20.

## 1. Kesin hatalar (düzeltilmeli)

### Sistem ilanı akışı ve canlı takip
- **T1. Uyuşmazlık açılınca konum sessizce atılıyor.** `DriverLocationService::record` yalnız `in_transit` sevkiyata yazar; uyuşmazlıkta
  sevkiyat `disputed` olur, şoför ekranı "paylaşımı açık tutun" der, JS 200 görüp "gönderildi" yazar ama `recorded:false` okunmaz. Yük
  sahibi haritası da uyuşmazlıkta gizlenir. Düzeltme: `in_transit|disputed` (teslim edilmemiş) kabul, owner haritasına aynı koşul, JS'te
  `recorded=false` → uyarı.
- **T2. CSP zorunlu kılınınca harita döşemeleri kırılır.** `SecurityHeaders` `img-src` yalnız openstreetmap'e izin verir; şoför ve yük
  sahibi haritaları `basemaps.cartocdn.com` kullanır. `csp_enforce=1` yapılınca iki harita boş kalır. Düzeltme: CSP'ye cartocdn ekle.
- **T3. İlan yayınında premium şoförlere e-posta eşzamanlı gidiyor.** `LoadReleaseService::onPublished` → `NotificationService::notify`
  → `Mail::send` kuyruksuz; 50 premium şoförde 25-100 sn, zaman aşımı + çift ilan riski. Düzeltme: e-postayı kuyruğa al
  (`MAIL_PENDING` + `notifications:retry-mail` ya da Job).
- **T4. Kilit sırası tutarsız (deadlock riski).** `ShipmentService` Shipment→Load, `LoadService::cancelPaidLoad` / `DisputeService::open`
  / `OfferService::reopen` Load→Shipment. Düzeltme: her yerde Load→Shipment, `DB::transaction(fn, 3)`.
- **T5. Konum izni reddedilince durum "Açık" kalır;** "Son gönderim" saniyeli saat gösterir (§7 kuralı). Düzeltme: `err.code===1` →
  durdur + "Tarayıcı ayarlarından konum iznini açın"; `HH:mm`.

### Yük sahibi paneli
- **Y1. Kurumsal hesap VKN/unvanını hiçbir yerden yazamıyor** (profil salt okunur), oysa hata mesajı "Profil sayfasından yazın" diyor.
  Düzeltme: profilde küçük form (unvan, VKN mod-10, vergi dairesi), kaydedince `gib_verified=false`.
- **Y2. Hoş geldin bildirimi/e-postası eski kuralı anlatıyor** ("kimlik belgenizi yükleyin; teklif kabul için gerekli"). Düzeltme: metin.
- **Y3. Sevkiyat sayfasında "Marka / model" satırı araç tipini gösteriyor** (brand/model kolonları var). Düzeltme: `brand model`.
- **Y4. Profilde "vergi levhanızı aşağıdan yükleyin"** derken belge bölümü telefonda üstte. Düzeltme: "Belgeler bölümünden".
- **Y5. Bireysel yük sahibine hâlâ "Kimlik kartı" yükleme kutusu çıkıyor** (belge gerekmez dedikten hemen sonra). Düzeltme: bireyselde
  belge bölümünü gizle; kurumsalda vergi levhası/imza sirküleri isteğe bağlı kalsın.
- **Y6. NVİ servisi çökünce deneme hakkı yanıyor** (sayaç sorgudan önce artıyor). Düzeltme: `success=false` iken sayacı artırma,
  `nvi_message` yazma.
- **Y7.** `LoadService::repeat` yorumu "teslim noktaları taşınır" der, kod taşımaz (bugün etkisiz). **Y8.** Ödeme sayfasında iyzico bandı
  ödeme kapalıyken de görünür; görseller `asset_v()` ile değil. **Y9.** Bildirimler başlığındaki üçlü düğme satırı 390 px'te sarmıyor
  (`flex-wrap` yok; şoförle ortak dosya).

### Şoför paneli
- **S1. Genel bakışta ölü "Teklif ver" düğmesi:** teklif verilmiş ya da tarihi geçmiş ilan listelenir, havuzda "İlan bulunamadı" der.
  Aynı eksik dönüş yükü listesinde. Düzeltme: `Load::scopeOfferableBy(DriverProfile)` + üç yerde kullan.
- **S2. Sistem ilanı kartı dört yerde kopya ve farklı** (rozet adları, tarih biçimi, doğrulanmış rozeti). Düzeltme: tek bileşen
  `<x-system-load-card>`.
- **S3. Genel bakış listesi parmağın altından kayıyor:** `tick` imzası tüm dış kaynağın `max(id)`'sine bağlı, `PausePollWhileInteracting`
  yalnız `$refresh`'i duraklatır. Düzeltme: imza = gösterilen 8 ilanın id'leri; kullanıcı etkileşimde çizme.
- **S4. Geçmiş sekmesinde kırık "Ayrıntı" bağlantısı:** vazgeçilen/ödemesi dolan işte `loads.driver_profile_id` null olur, sayfa "size
  ait değil" der. Düzeltme: sahiplik `shipments.driver_profile_id`/`driver_trips` üzerinden ya da kapalı işte link yok.
- **S5. Büyük yazı kipi rozet/etiketleri büyütmüyor** (`text-[11px]`/`[10px]` px birimli, 80+ yer). Düzeltme: `text-2xs/3xs` rem
  boyutları ve toplu değiştirme.
- **S6. "Teklif sonuçları" ve "Tercih ettiğim rotalar" ayarları hiçbir şey yapmıyor.** Düzeltme: `OfferService` tercihi okusun; rota
  tercihini kaldır (kalıcı filtre zaten var).
- **S7. Yükleme zamanı filtresi dış kaynak sekmesinde çalışmıyor ama çip görünüyor.** Düzeltme: sekmede gizle ya da uygula.
- **S8. Ücretsiz şoförde "N yeni ilan" sayacı premium süresi dolan ilanı saymıyor** (düğmesiz kayma); dönüş yükü taramasında da.
  Düzeltme: `COALESCE(available_to_free_at, created_at)`.
- **S9. Yönetici görünümü kapısı eksik:** `completeByDriver` ve `toggleSave` `is_staff_view` denetlemiyor. Düzeltme: aynı RuntimeException.
- **S10. 390 px taşma riski:** dış kaynak kartında numara + hat etiketi + WhatsApp düğmesi `nowrap`. Düzeltme: `flex-wrap`.

## 2. Eksikler / takılma noktaları (kullanıcı haber alamıyor ya da iş yarım kalıyor)
- Konum paylaşımı el ile ve yalnız ayrıntı sayfasında başlıyor; "Yola çıktım" iş kartından basılınca harita yok; sayfadan çıkınca
  paylaşım durur; telefon kilitlenince/navigasyona geçince iz kesilir (wake lock, görünürlük yok). Yük sahibi çoğu zaman "henüz
  paylaşılmadı" görür.
- Konum tazeliği yok (3 saatlik konum "canlı" görünür); iz 200 noktayla (~50 dk) kesik; yükleme/teslim işaretçisi yok; ETA yok
  (`estimated_days` alınıyor, gösterilmiyor).
- Yolda takılan sevkiyat için zaman aşımı/hatırlatma yok; otomatik onaydan önce yük sahibine hatırlatma yok; yönetici "zorla kapat" yok.
- Zaman çizelgesinde "teslim alındı" ve "yola çıkıldı" aynı anda; şoför vazgeçince yük sahibine iki bildirim; `OutboxEvent` yazılıyor
  okunmuyor; iade ile kapanan sevkiyat "Sevkiyatlarım"da yok; yükleme fotoğrafı adımı yok, kanıt önizlemesi yok, sevkiyat özeti PDF yok.
- Yük sahibi: ilan düzenleme yok; il/ilçe seçici + gizli açık adres yok (açık adres tüm şoförlere gidiyor, plan E6/E7); ilan notu
  alanı yok; taslak yok; şoför profili/geçmiş sevkiyat sayısı yok; fatura PDF yok; ödeme sayfasında son ödeme saati yok; teklif
  kartında "ödeyeceğin toplam" yok.
- Şoför: kartta mesafe/₺-km yok; ilanı paylaş yok; teklif şablonu/son teklif yok; il/rota bazlı bildirim tercihi yok (ön ayar altyapısı
  hazır); benzer ilanlar bağlantısı yok; PWA yok; araç müsaitlik takvimi yok; NavlunIQ işinde yük sahibine WhatsApp düğmesi yok.

## 3. Yasal
- Beş metin dün kodla hizalandı (`docs/CANLIYA_GECIS_PLANI.md`, PR #137). Kodda eksik olmayan, **hukukçuya sorulacak** 5 nokta: KVKK m. 9
  yurt dışı aktarım dayanağı (gerekirse telefon numarası gönderimden önce maskelenir, küçük iş); konum için ayrı açık rıza gerekip
  gerekmediği; premium cayma istisnasının satın alma ekranındaki onay kaydı; uyuşmazlık "hakem" kararının hukuki niteliği; İYS kaydı
  yapılmadan 7A cümlesi.
- Osman'ın dış işleri: VERBİS kaydı (gerekliyse), İYS, ETBİS, e-arşiv fatura (premium satışı için mali müşavir), iyzico pazaryeri
  sözleşmesi, KVKK yurt dışı aktarım için sağlayıcı sözleşmeleri.
- Kodda kalan yasal eksik: açık adres ve yük sahibinin adının teklif öncesi herkese açılması (E6/E7; "Ad S." yapıldı, adres kaldı).

## 4. Paketler ve durum

**Osman'ın kararları (2026-10-06):** 1. paket tamamen; canlı takip yalnız sistem ilanında, dış kaynakta yalnız dönüş yükü radarı;
bildirimler (yeni ilan, dönüş yükü) yalnız premium şoföre, standart üyeye bildirim yok (panelde görür); dış kaynak standart üyeye kapalı;
Telegram ilanı herkese açıldığı anda; çelişen tanıtım metinleri düzeltildi. 3. ve 4. paket uygun görüldüğü gibi.

**Paket 2: ✅ uygulandı** (konum "Yola çıktım"la başlar + wake lock + görünürlükte yeniden bağlanma; tazelik; işaretçiler ve seyreltilmiş tam iz;
varışa kalan km; tahmini varış; yolda takılma bekçisi; otomatik onay hatırlatması; e-posta kuyruğu Paket 1'de; B8, B11 düzeltildi).

**Paket 1: ✅ uygulandı** (T1-T5, Y1-Y7, Y9, S1, S3, S4, S6-S10; S2 ve S5 Paket 4'e alındı; Y8 küçük, Paket 3'e). Test `ProcessAuditFixesTest`.

- **Paket 1 (kesin hatalar, 1 gün):** T1-T5, Y1-Y6, Y9, S1, S3, S4, S6-S10; testler.
- **Paket 2 (canlı takip iyileştirme, 1 gün):** "Yola çıktım" ile konum başlat + wake lock + görünürlükte yeniden bağlan; tazelik
  ("4 dk önce", eski konum gri); yükleme/teslim işaretçileri ve seyreltilmiş tam iz; ETA; yolda takılma bekçisi; otomatik onay
  hatırlatması; bildirim e-postaları kuyruğa.
- **Paket 3 (yük sahibi, 1-2 gün):** il/ilçe seçici + gizli açık adres (E6/E7), ilan düzenleme + "Tekrar yayınla"da tarih, ilan notu,
  teklif kartında ödenecek toplam, ödeme sayfasında son saat, şoför geçmiş sevkiyat sayısı, bireyselde belge bölümü gizli.
- **Paket 4 (şoför, 1 gün):** tek kart bileşeni, mesafe ve ₺/km, bildirimde ön ayar, son teklif/hazır not, paylaş, benzer ilan
  listesi, büyük yazı kipi düzeltmesi.
- **Sonra:** PWA kabuğu, sevkiyat özeti PDF, yükleme fotoğrafı, araç müsaitlik.
