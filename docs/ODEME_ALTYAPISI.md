# Ödeme altyapısı

## Mimari

Uygulama yalnız `App\Payments\Contracts\PaymentGateway` arayüzünü bilir. Sağlayıcıya özgü her şey bir adaptördedir:

| Katman | Dosya | Görev |
|---|---|---|
| Sözleşme | `app/Payments/Contracts/PaymentGateway.php` | createCheckout, parseWebhook, refund, (isteğe bağlı) alt üye işyeri kaydı ve aktarımı |
| Adaptörler | `app/Payments/Gateways/PaytrGateway.php`, `NullGateway.php` | PayTR iFrame; anahtar yokken güvenli boş adaptör |
| Seçici | `app/Payments/GatewayManager.php` | Panel ayarı `payment_provider` (bugün yalnız iyzico seçilebilir; PayTR ölü yol); anahtar yoksa NullGateway |
| Akış | `app/Services/PaymentService.php` | sipariş (escrow / subscription), ödeme ekranı, sunucu bildirimi, iade, defter, bildirim |
| Abonelik | `app/Services/SubscriptionService.php` | premium sipariş, ödeme sonrası dönem + fatura + premium süresi |
| Şoför ödemesi | `app/Services/PayoutService.php` | teslimat onayında hakediş; pazaryeri geçidi varsa otomatik aktarım, yoksa manuel |

Ödeme yalnız imzası doğrulanmış **sunucu bildirimi** ile "alındı" sayılır. Kullanıcı dönüş sayfası (`/odeme/sonuc/{sipariş}/basarili|basarisiz`) durumu belirlemez, sorgular.

## Adresler

- Sunucu bildirimi (webhook): `POST /odeme/bildirim/{sağlayıcı}` (ör. `/odeme/bildirim/paytr`); eski `/odeme/paytr/bildirim` de çalışır.
- Sonuç sayfaları: `/odeme/sonuc/{sipariş-uuid}/basarili` ve `/basarisiz`. Sağlayıcıya "başarılı/başarısız dönüş adresi" olarak bunlar verilir (adaptör otomatik geçer).
- Navlun ödemesi: `/panel/yuk-sahibi/odeme/{ilan}`; premium: `/panel/sofor/premium/odeme`.

## Yeni sağlayıcı eklemek

1. `app/Payments/Gateways/XGateway.php` yazın (`PaymentGateway` uygular).
2. `GatewayManager::REGISTRY` içine `'x' => XGateway::class` ekleyin.
3. `config/services.php` içine anahtar tanımlarını, `.env` içine değerleri ekleyin; `PAYMENT_PROVIDER=x`.
4. Testler: `tests/Feature/Payments/PaymentInfrastructureTest.php` içindeki `FakeGateway` deseniyle adaptörünüzü sınayın.

Pazaryeri (alt üye işyeri) ürünü olan sağlayıcıda `supportsSubMerchants()` true döner; şoför `payout_provider_ref` ile kaydedilir ve hakedişler `transferToSubMerchant` ile otomatik aktarılır. Desteklenmiyorsa finans ekibi Finans ve Muhasebe ekranından banka transferini işaretler.

## iyzico

`IyzicoGateway` (Ödeme Formu): anahtarlar panelden (Sistem Ayarları → Ödeme altyapısı → "Ödeme kuruluşu ve
anahtarlar"; gizli anahtar veritabanında şifreli) ya da `.env` `IYZICO_API_KEY` / `IYZICO_SECRET_KEY`.
Akış: initialize → kullanıcı iyzico sayfasına yönlendirilir → iyzico kullanıcıyı `POST /odeme/bildirim/iyzico`
adresine `token` ile döndürür → token sunucudan sorgulanır → sipariş "paid" → kullanıcı sonuç sayfasına
yönlendirilir. iyzico panelinde webhook adresi olarak aynı adres girilebilir (JSON, `iyziEventType`).
Pazaryeri ürünü ("Pazaryeri ürünü aktif" kutusu): şoför teklifi kabul edilirken TC/VKN ve IBAN'ıyla alt üye işyeri olarak
kaydedilir, navlun kalemi `subMerchantKey` ile gönderilir, teslimat onayında kalem onayı (item approve) ile
tutar şoföre aktarılır. Sandbox anahtarlarıyla test modunda deneyip canlıya geçerken test modunu kapatın.

İnceleme (test) hesapları: ödeme kuruluşu siteyi içeriden görmek isterse Sistem Ayarları → Genel'de
"İnceleme hesapları e-postaları" ve "sabit doğrulama kodu" doldurulur; listedeki hesaplar e-posta almadan
şifre + bu sabit kodla giriş yapar. İnceleme bitince kodu silin. Sitede "cüzdan/bakiye" ifadesi
kullanılmaz (şoför sayfası "Ödemelerim"); `faq:refresh --if-stale` eski SSS metnini yeniler.

Site kriterleri (iyzico başvurusu): Hakkımızda, SSL, Teslimat ve İade Şartları, Gizlilik, Mesafeli Satış
sayfaları; altbilgide ve ödeme sayfalarında "iyzico ile Öde" + Mastercard/Visa/Amex/Troy logoları
(`public/images/payment`).

### Otomatik yenilenen premium (kart saklama, 2026-10-10)

iyzico pazaryeri başvurusunu yazılı olarak reddetti (aracılık modeli); sanal POS yalnız yazılım/SaaS satışı için kalıyor.
Premium üyelik buna uyar. Osman'ın kararı: "bir kere kayıt olsun, her ay otomatik devam etsin". Akış:

- Panel: Sistem Ayarları → Ödeme altyapısı → "Kart saklama ürünü aktif" (`iyzico_card_storage`). Kapalıyken hiçbir ekranda
  otomatik yenileme görünmez, abonelik eskisi gibi tek seferliktir. iyzico "kart saklama" ve "kayıtlı kartla tahsilat"
  ürünlerini hesapta açınca kutu işaretlenir; "Bağlantıyı sına" kart saklama satırı yeşil olmalı.
- Satın alma: ödeme sayfasında "Otomatik yenile" kutusu (varsayılan açık). İşaretliyse Ödeme Formu'na kullanıcının önceki
  kart anahtarı (`cardUserKey`) gider; kullanıcı iyzico sayfasında "kartımı sakla" der; sorgu yanıtındaki `cardUserKey`/
  `cardToken`/son 4 hane `stored_cards` tablosuna yazılır (token şifreli; kart numarası hiç gelmez). Kart kaydedilmediyse
  yenileme açılmaz, şoföre söylenir.
- Yenileme: `subscriptions:renew` (saatlik) dönem bitimine 72 saat kala `POST /payment/auth` ile `paymentCard{cardUserKey,
  cardToken}` çekimi yapar (`IyzicoGateway::chargeStoredCard`, `PaymentService::chargeRenewal`, emir `stored_card_id` taşır),
  başarıda `activate()` süreyi uzatır ve "yenilendi" bildirimi gider. Başarısızlıkta 24 saat sonra tekrar (en çok 3); kart
  geçersizse (`CARD_INVALID_CODES`) ya da üçüncü başarısızlıkta yenileme kapanır, şoför bilgilendirilir, dönem sonunda üyelik biter.
  Tutar yenileme günündeki panel fiyatıdır; `subscriptions:remind` yenilenecek aboneliğe 5 gün kala tutarı yazar (bedel önceden bildirilir).
- Şoför Premium sayfası: "Otomatik yenileme" kartı (açık/kapalı, tek dokunuşla kapat/aç, kayıtlı kartı sil →
  `DELETE /cardstorage/card`). Kapatma dönem sonuna kadar hakları etkilemez.
- Sözleşmeler: MSS 2.1/3.1 ve İade 3.2 "satın alma ekranında seçilmediği sürece otomatik yenilenmez" + yenileme kuralları;
  `legal:refresh --if-stale` metni yeniler. İkinci bir kuruluş (navlun için) seçilirse abonelik iyzico'da kalır; sağlayıcı
  seçimi amaca göre (abonelik / navlun) ayrılacak.

## iyzico üye işyeri paneli: hangi ayar nasıl olmalı (2026-10-07)

| iyzico paneli | Olması gereken | Neden |
|---|---|---|
| Ayarlar → API Anahtarları | API anahtarı + Güvenlik (gizli) anahtarı **yalnız NavlunIQ paneline** (Sistem Ayarları → Ödeme altyapısı) girilir; sohbete, dosyaya, depoya yazılmaz | Gizli anahtar veritabanında şifreli; sızarsa iyzico panelinden "Yenile" |
| Test (sandbox) modu | Canlı anahtarla **kapalı**; `sandbox-` ile başlayan anahtarla açık | Yanlış eşleşmeyi "Bağlantıyı sına" kırmızı gösterir |
| İşyeri Bildirimleri | "Ödeme bildirimlerini gönder" **açık**, Url: `https://navluniq.com/odeme/bildirim/iyzico` | Tarayıcı dönüşü kesilse de ödeme sunucuya ulaşır; adaptör token ile sunucudan sorgular |
| Para Gönderimi Tercihi | **Banka Hesabıma** (şirket IBAN'ı) | Komisyon ve premium gelirleri periyodik olarak şirkete geçer; iyzico bakiyesinde beklemez |
| Üye İşyerine Açık Taksitler | Fark etmez; NavlunIQ tek çekim gönderir (`enabledInstallments: [1]`) | Pazaryeri kaleminde taksit komisyonu karmaşası olmasın |
| Ödeme Bazında 3D Secure → Tutar | **1** (her ödeme 3D Secure) | Navlun tutarları yüksek; itiraz (chargeback) riski 3D ile taşınmaz |
| Kart Saklama / BKM Express | Kapalı kalabilir | Kullanılmıyor |
| Pazaryeri (alt üye işyeri) ürünü | iyzico temsilcisinden **açtırılır** (ayrı sözleşme); sonra NavlunIQ panelinde "Pazaryeri ürünü aktif" | Navlun tahsilatı canlıda yalnız bu modelle açılır (`PaymentReadiness::escrowBlocker`) |

**Premium süreleri:** 1 ay tam fiyat; 3/6/12 ay panel ayarlı indirimle (`premium_discount_3m/6m/12m`). Ödeme emri `subscription_months`
taşır, onayda o kadar ay eklenir. iyzico'nun "Abonelik" (tekrarlayan ödeme) ürünü ve kart saklama kullanılmaz; her dönem tek çekimdir.
XML ürün yükleme ve sonuç sayfası alanları iyzico panelinde boş bırakılır (dönüş adresi her ödemede `callbackUrl` ile gönderilir).

**Para akışı (pazaryeri):** yük sahibi navlunun tamamını iyzico ödeme formunda öder → tutar iyzico'da bekler (NavlunIQ hesabına
girmez) → teslimat onayında NavlunIQ "kalem onayı" (`/payment/iyzipos/item/approve`) verir → iyzico şoför payını (`subMerchantPrice`,
navlun − hizmet bedeli) şoförün alt üye işyeri IBAN'ına, kalanı NavlunIQ'ya aktarır. Teslimden önce iptal/uyuşmazlıkta kalem
onaylanmadan iade edilir (`/payment/refund`). Premium üyelik pazaryeri dışı normal tahsilattır.

**Alt üye işyeri türleri (resmî belge "Satıcı Oluşturma" ile birebir):** bireysel şoför `PERSONAL` (TC + IBAN; IBAN ad-soyada ait
olmalı), şahıs şirketi `PRIVATE_COMPANY` (TC + vergi dairesi + unvan; tırcıların çoğu bu tiptedir, Ödemelerim'de "Şahıs şirketi"),
limited/anonim `LIMITED_OR_JOINT_STOCK_COMPANY` (VKN + vergi dairesi + unvan). Unvan = Ödemelerim'deki "Hesap sahibi" alanı; IBAN
unvana ait olmalı (iyzico başka ada aktarım yapmaz). Vergi dairesi şahıs şirketi ve şirkette zorunlu (`DriverProfile::requiresTaxOffice`).
Aynı dış kimlik (`DRV-{şoför}-{hesap}`) iyzico'da zaten varsa kayıt yerine güncelleme (PUT + `subMerchantKey`) yapılır.

**iyzico platform sözleşmeleri (zorunlu, belge "Alıcı ve Satıcı Sözleşmeleri"):** pazaryerinde ödeme akışını iyzico yürüttüğünden
satıcı (şoför) ve alıcı (yük sahibi) iyzico sözleşmesini **bir kez** dijital olarak onaylar. Şoför: Ödemelerim formunda
"iyzico Pazaryeri Satıcı Sözleşmesi" kutusu (`driver_profiles.iyzico_seller_agreed_at`, `0001_01_61`); onaysız alt üye işyeri kaydı
yapılmaz ve teklifi kabul edilemez (`PayoutService::payoutReadinessBlocker`). Yük sahibi: ilk navlun ödemesinde "Ödemeden önce tek
seferlik onay" adımı (`users.iyzico_buyer_agreed_at`); onaylayınca iyzico formuna geçer, sonraki ödemelerde sorulmaz. Her iki kutu
yalnız etkin ödeme kuruluşu iyzico iken çıkar. Bağlantılar `IyzicoGateway::SELLER_AGREEMENT_URL / BUYER_AGREEMENT_URL`.

**Resmî pazaryeri belgesiyle karşılaştırma (2026-10-08, docs.iyzico.com/urunler/pazaryeri + iyzipay-php örnekleri):** alt üye
oluşturma/güncelleme/sorgulama (`/onboarding/submerchant`, PUT, `/retrieve`), sepet kaleminde `subMerchantKey` + `subMerchantPrice`
(ödeme formunda da geçerli), kalem onayı (`/payment/iyzipos/item/approve`, `paymentTransactionId`), iade (`/payment/refund`:
`paymentTransactionId` + `price` + `ip`, kırılım tutarına kadar art arda iade olabilir, 365 gün) kodla uyumlu. "Onay Geri Çekme"
(`/payment/iyzipos/item/disapprove`) `IyzicoGateway::withdrawApproval` olarak hazır, akışta kullanılmaz (onay yalnız teslimat
onayında verilir). Belgenin notu: sandbox hesabında pazaryeri, üye işyeri numarasıyla entegrasyon@iyzico.com'a yazılarak açtırılır;
canlıda temsilciden. Belge kalem onayı için süre sınırı yazmıyor; iyzico'ya sorulacaklar listesinde (2) duruyor.

**"Bağlantıyı sına" (Ödeme altyapısı sekmesi):** para hareketi yapmaz; BIN sorgusu ile anahtar/imza, alt üye işyeri sorgusu ile
pazaryeri yetkisi, ayrıca anahtar–ortam uyumu ve bildirim adresi denetlenir; iyzico'nun hata metni aynen gösterilir.

**iyzico'ya sorulacaklar:** (1) pazaryeri ürünü hesapta açık mı, açık değilse sözleşme; (2) kalem onayı için azami süre var mı,
süre dolunca kalem kendiliğinden onaylanır mı yoksa iade mi edilir (uzun seferlerde teslim gecikebilir); (3) alt üye işyeri
ödemelerinin takvimi (onaydan kaç gün sonra IBAN'a geçer) ve şoföre giden tutardan iyzico kesintisi olup olmadığı.

## Ödeme kuruluşu başvurusu

Yönetici paneli → Sistem Ayarları → **Ödeme altyapısı** sekmesindeki hazırlık listesi (31 madde) yeşil olmalı:
şirket künyesi, ETBİS kodu ve KDV oranı (aynı sekmedeki "Şirket künyesi" formu; .env gerekmez, künye değişince altbilgi/iletişim/sözleşmeler kendiliğinden güncellenir), beş yasal sayfa, "havuz/bloke/escrow" ifadesi yok, HTTPS, fiyat ve KDV, gönderici e-posta.

Başvuruda iş modelini şöyle anlatın: "Yük sahipleri ile belgeleri doğrulanmış şoförleri buluşturan dijital platform. Yük sahibi navlun bedelini lisanslı ödeme kuruluşu üzerinden öder; ödeme teslimat onayına bağlı olarak şoföre tamamlanır (pazaryeri / alt üye işyeri modeli). Ayrıca şoförlere aylık premium üyelik (dijital hizmet, KDV dahil) satılır."

Yasal metinler `{{COMPANY_*}}` yer tutucularıyla saklanır ve gösterimde künyeyle doldurulur. Eski biçimdeki (künyesi gömülü) metinler `update.sh` içindeki `php artisan legal:refresh --if-stale` ile bir kez yenilenir; panelde "Yasal metinleri güncel şablonla yenile" düğmesi aynı işi elle yapar.

## Faturalar

`invoices` tablosuna abonelik (`subscription`) ve platform hizmet bedeli (`commission`) kayıtları KDV ayrıştırılmış olarak düşer; `invoice_no` e-belge sağlayıcısı bağlanınca yazılır (`status = pending`).
