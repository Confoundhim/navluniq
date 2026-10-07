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

**Para akışı (pazaryeri):** yük sahibi navlunun tamamını iyzico ödeme formunda öder → tutar iyzico'da bekler (NavlunIQ hesabına
girmez) → teslimat onayında NavlunIQ "kalem onayı" (`/payment/iyzipos/item/approve`) verir → iyzico şoför payını (`subMerchantPrice`,
navlun − hizmet bedeli) şoförün alt üye işyeri IBAN'ına, kalanı NavlunIQ'ya aktarır. Teslimden önce iptal/uyuşmazlıkta kalem
onaylanmadan iade edilir (`/payment/refund`). Premium üyelik pazaryeri dışı normal tahsilattır.

**Alt üye işyeri türleri:** bireysel şoför `PERSONAL` (TC + IBAN), şirket şoförü `LIMITED_OR_JOINT_STOCK_COMPANY` (VKN + vergi
dairesi + unvan; vergi dairesi Ödemelerim sayfasında zorunlu). Aynı dış kimlik (`DRV-{şoför}-{hesap}`) iyzico'da zaten varsa kayıt
yerine güncelleme (PUT + `subMerchantKey`) yapılır.

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
