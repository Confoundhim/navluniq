# E-posta ve bildirim sistemi

Tek kapı: `App\Services\NotificationService`. Her bildirim önce `user_notifications` tablosuna yazılır
(panel zili ve Bildirimler sayfası), sonra aynı içerik markalı e-posta olarak gönderilir. E-posta
başarısız olursa kayıt `failed` kalır; `notifications:retry-mail` görevi 10 dakikada bir en fazla 4 kez
yeniden dener. Doğrulama kodları (OTP) ve şifre sıfırlama bağlantısı ise beklemeden, anında gönderilir.

## Kim, ne zaman, ne alır

| Olay | Alıcı | Tür |
|---|---|---|
| Kayıt doğrulandı | Kullanıcı: hoş geldiniz + ilk adımlar | welcome |
| Giriş, kayıt, rol değişimi | Kullanıcı: 6 haneli kod (5 dk) | OTP |
| Şifremi unuttum / şifre değişti | Kullanıcı: sıfırlama bağlantısı (60 dk) / güvenlik uyarısı | security |
| Belgeler tamamlandı | Kullanıcı: "alındı"; `verify kyc` izni olan personel: "inceleme bekliyor" | kyc / admin |
| Belge onay / ret | Kullanıcı: sonuç ve gerekçe | kyc |
| Yeni teklif | Yük sahibi | offer |
| Teklif kabul / ret / süre doldu / geri çekildi | Şoför (kabul edilmeyenler dahil); geri çekme yük sahibine | offer |
| İlan iptali | Bekleyen ve kabul edilmiş teklif sahibi şoförler | load |
| Navlun ödemesi alındı | Yük sahibi ve şoför | payment |
| Yola çıktı / teslim kanıtı / teslimat onayı | Karşı taraf; otomatik onayda yük sahibi de | shipment |
| Hakediş ödendi / yapılamadı | Şoför; ödeme kuruluşu aktarım hatası `manage payouts` personeline | payout / admin |
| Uyuşmazlık açıldı / savunma / karar | Taraflar ve `manage disputes` personeli | dispute / admin |
| Destek talebi | Talep sahibi (onay), `manage support tickets` personeli; yanıt talep sahibine | support / admin |
| Premium etkinleşti / 3 gün kala / sona erdi | Şoför | subscription |
| Dönüş yükü: açık seferin varış yeri çevresinden (aynı il ya da `return_load_radius_km`) çıkan, araca uyan yeni ilan | Şoför; her 10 dakikada tarama, aynı ilan bir kez; e-posta sefer başına `return_load_mail_hours` aralıkla, uygulama içi her seferinde | return_load |
| Değerlendirme | Değerlendirilen taraf | review |
| Hesap kapatma | Kapatma onayı (anonimleştirme öncesi adrese) | mail |

Uygulama içi bildirim ama e-posta gönderilmeyenler: teklif süresi doldu, teklif geri çekildi
(`sendMail: false`). Yeni olay eklemek için ilgili serviste
`$this->notifications->notify($user, $başlık, [$satırlar], $url, $düğme, $tür)` çağrısı yeterlidir;
yönetici bildirimleri için `notifyAdmins($izin, ...)`.

## Şablonlar

`resources/views/emails/layouts/base.blade.php` tek temel şablondur: logo (`/images/logo-dark.png`,
mutlak adres `APP_URL` ile), turuncu şerit, içerik, imza bloğu (NavlunIQ Ekibi, telefon, e-posta, site,
sosyal bağlantılar), altbilgide şirket künyesi (Sistem Ayarları → Ödeme altyapısı → Şirket künyesi),
ETBİS, KVKK/Gizlilik bağlantıları. Tablo tabanlı ve satır içi stilli olduğundan Outlook ve Gmail'de
bozulmaz. Her e-postanın düz metin sürümü de vardır (`emails/text/*`).

- `emails.notice`: genel bildirim (başlık, paragraflar, düğme)
- `emails.otp`: doğrulama kodu
- `emails.reset-password`: şifre sıfırlama (Laravel'in varsayılan İngilizce şablonu devre dışı)

## Sunucu ayarları

Tercih edilen yol: Yönetici → Sistem Ayarları → **E-posta ve bildirim** → "SMTP ayarları (panelden)".
Natro Kurumsal Posta için değerler hazır gelir (mail.kurumsaleposta.com, 587, TLS, info@navluniq.com);
yalnız posta kutusu şifresi girilir. Şifre veritabanında şifreli tutulur ve `.env` yerine geçer;
`config:cache` gerekmez. Panelde şifre boşsa `.env` ayarları kullanılır:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.saglayici.com
MAIL_PORT=587
MAIL_USERNAME=bildirim@navluniq.com
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=bildirim@navluniq.com
MAIL_FROM_NAME="NavlunIQ"
```

Gönderici adresi mutlaka `navluniq.com` alan adında olmalıdır. Alan adının DNS'inde üç kayıt
tanımlanmadan e-postalar spam klasörüne düşer:

1. **SPF**: `v=spf1 include:<saglayici-spf> ~all`
2. **DKIM**: sağlayıcının verdiği `selector._domainkey` TXT kaydı
3. **DMARC**: `_dmarc` TXT → `v=DMARC1; p=quarantine; rua=mailto:postmaster@navluniq.com`

Yanıt adresi (`Reply-To`) otomatik olarak şirket künyesindeki e-postadır; kullanıcı "yanıtla" derse
mesaj oraya gider.

## Doğrulama

Yönetici → Sistem Ayarları → **E-posta ve bildirim**: SMTP durumu, son 24 saat gönderim sayıları,
"Deneme e-postası gönder", başarısızlar listesi ve "Başarısızları yeniden dene". Aynı sekmede olay →
alıcı tablosu ve son bildirimler görünür.

Komutlar: `php artisan notifications:retry-mail`, `php artisan subscriptions:remind`.
