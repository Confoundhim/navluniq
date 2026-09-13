# NavlunIQ – Durum Raporu ve Mobil Yol Haritası

Son güncelleme: 2026-09-13 (dal: `claude/laravel-marketplace-mobile-a68zgo`)

## 1. Bu turda yapılanlar

Beş modül raporuyla tespit edilen sorunların tamamı ele alındı; proje görsel prototipten çalışan bir pazaryerine dönüştürüldü.

| Alan | Durum |
|---|---|
| Kayıt / giriş / rol değişimi | Ortak `OtpService`; kilitli kimlik, hız sınırı, oturum yenileme, KVKK rıza kaydı, taslak hesap temizliği |
| Şifre sıfırlama, hesap kapatma | Eklendi (mağaza politikası gereği) |
| İlan → teklif → kabul | `LoadService`, `OfferService`; ilanlar şoför havuzunda görünür, tek aktif teklif, KYC şartı |
| Ödeme | `PaymentService` PayTR iFrame API + imzalı callback; anahtarlar gelene kadar dürüst "aktivasyon aşamasında" ekranı |
| Sevkiyat | Yola çıkış yalnız havuz ödemesi sonrası; teslimat kanıtı dosyası; otomatik onay süresi |
| Hakediş | `PayoutService`; komisyon oranı ayarlardan; finans ekibi banka transferini işaretler; çift taraflı defter kaydı |
| Uyuşmazlık | Havuz askıya alınır; savunma; hakem kararı ödeme/iade |
| KYC | Belge yükleme (özel disk), yönetici inceleme, imzalı dosya erişimi |
| Şoför konumu | Tarayıcıdan `watchPosition` ile paylaşım, yük sahibi haritasında iz |
| Admin paneli | Gerçek KPI, rol bazlı yetki, sahte veri ve simülasyon blokları kaldırıldı, HTMLPurifier |
| Altyapı | Leaflet ve yazı tipi yerel pakette, güvenli alan CSS'i, migration'lar SQLite + MySQL |
| Testler | 66 test (birim, servis, akış, sayfa render, rol erişimi) + tarayıcı senaryosu |

## 2. Canlıya çıkmadan önce sizin tarafınızda tamamlanacaklar

1. `.env`: şirket künyesi (`COMPANY_*`), SMTP, `ADMIN_INIT_*`, `APP_URL`, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`.
2. PayTR mağaza anahtarları gelince `PAYTR_*` doldurulur; PayTR panelinde bildirim adresi `/odeme/paytr/bildirim`. Sandbox ile bir tam akış test edilmeli.
3. Sözleşme metinleri `.env` künyesiyle dolar; hukuk danışmanı onayından geçirilmeli.
4. Sunucuda crontab satırı (`schedule:run`) ve `storage:link`.
5. WhatsApp toplama servisi yalnız yazılı izin alınmış gruplarla çalıştırılmalı.
6. NetGSM SMS altyapısı hazır; şu an OTP e-posta ile gidiyor. SMS OTP istenirse `OtpService` içine kanal seçimi eklenir.

## 3. Mobil uygulama (bir sonraki aşama)

Site artık WebView için uygun: rotalar çerez tabanlı, dış CDN yok, çentik güvenli alanı ve CSRF meta etiketleri hazır.

| Adım | İçerik | Tahmin |
|---|---|---|
| Capacitor kabuk | `mobile/` altında proje, `server.url` canlı site, ikon ve splash | 2 gün |
| Native köprü | Dış linkleri sistem tarayıcısına yönlendirme, geri tuşu, konum eklentisi (arka plan), push bildirimi | 1 hafta |
| Backend | `device_tokens` tablosu, FCM/APNs gönderimi, mevcut `NotificationService` üzerine push kanalı | 3 gün |
| Mağaza | Gizlilik politikası adresi, Play Data Safety formu, Apple inceleme notu, ekran görüntüleri | 2 gün |
