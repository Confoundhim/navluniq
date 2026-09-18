# Ödeme altyapısı

## Mimari

Uygulama yalnız `App\Payments\Contracts\PaymentGateway` arayüzünü bilir. Sağlayıcıya özgü her şey bir adaptördedir:

| Katman | Dosya | Görev |
|---|---|---|
| Sözleşme | `app/Payments/Contracts/PaymentGateway.php` | createCheckout, parseWebhook, refund, (isteğe bağlı) alt üye işyeri kaydı ve aktarımı |
| Adaptörler | `app/Payments/Gateways/PaytrGateway.php`, `NullGateway.php` | PayTR iFrame; anahtar yokken güvenli boş adaptör |
| Seçici | `app/Payments/GatewayManager.php` | `PAYMENT_PROVIDER` ile seçim; anahtar yoksa NullGateway |
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

## Ödeme kuruluşu başvurusu

Yönetici paneli → Sistem Ayarları → **Ödeme altyapısı** sekmesindeki hazırlık listesi (28 madde) yeşil olmalı:
şirket bilgileri (`COMPANY_*` .env), ETBİS kodu (CMS), beş yasal sayfa, "havuz/bloke/escrow" ifadesi yok, HTTPS, fiyat ve KDV, gönderici e-posta.

Başvuruda iş modelini şöyle anlatın: "Yük sahipleri ile belgeleri doğrulanmış şoförleri buluşturan dijital platform. Yük sahibi navlun bedelini lisanslı ödeme kuruluşu üzerinden öder; ödeme teslimat onayına bağlı olarak şoföre tamamlanır (pazaryeri / alt üye işyeri modeli). Ayrıca şoförlere aylık premium üyelik (dijital hizmet, KDV dahil) satılır."

## Faturalar

`invoices` tablosuna abonelik (`subscription`) ve platform hizmet bedeli (`commission`) kayıtları KDV ayrıştırılmış olarak düşer; `invoice_no` e-belge sağlayıcısı bağlanınca yazılır (`status = pending`).
