# Telegram kanalı kurulumu

Kanala yalnız **sistem ilanları** (yük sahibi üyelerin NavlunIQ'da açtığı ilanlar) gönderilir.
Yeni ilan önce premium şoförlere açılır ve bildirilir; **premium öncelik süresi** (varsayılan 20 dakika)
dolup ilan herkese açıldığı anda kanala düşer. Mesajda rota, yük, tonaj, araç, navlun, yükleme tarihi ve
"teklif vermek için NavlunIQ'ya gir" bağlantısı bulunur; iletişim bilgisi yoktur (teklif platformda verilir).
**Dış kaynak ilanlar kanala gönderilmez.** Kanal, uygulamayı sürekli açmak istemeyenlerin ilanları takip etmesi içindir.

## 1. Bot oluşturma (5 dakika)

1. Telegram'da **@BotFather** ile sohbet açın, `/newbot` yazın.
2. Bir ad (örn. "NavlunIQ İlan Botu") ve `bot` ile biten bir kullanıcı adı (örn. `navluniq_ilan_bot`) verin.
3. BotFather size `123456789:AAF...` biçiminde bir **anahtar** verir. Kopyalayın; kimseyle paylaşmayın.

## 2. Kanal oluşturma

1. Telegram → Yeni kanal → ad verin (örn. "NavlunIQ Yük İlanları"), **Herkese açık** seçip bir bağlantı adı belirleyin
   (örn. `navluniq_ilanlar`). Kanal kimliği bu addır: `@navluniq_ilanlar`.
2. Kanal → Yöneticiler → Yönetici ekle → botunuzu bulun → **Mesaj gönderme** yetkisini verin.

Özel kanal kullanacaksanız kimlik `-100...` ile başlayan sayıdır; kanala bir mesaj gönderip
`https://api.telegram.org/bot<ANAHTAR>/getUpdates` adresinden `chat.id` değerini okuyabilirsiniz.

## 3. NavlunIQ ayarları

Yönetim paneli → **Sistem Ayarları → Dış kaynak ve Telegram**:

- Telegram bot anahtarı: BotFather'ın verdiği anahtar
- Telegram kanal kimliği: `@navluniq_ilanlar` (herkese açık kanalda `@` ile; sitedeki "Kanala katıl" bağlantısı bundan üretilir)
- Sistem ilanlarını Telegram kanalına paylaş: **Açık**
- Premium öncelik süresi (dakika): varsayılan 20; hem sistem hem dış kaynak ilanlar için geçerlidir

**Kaydet**, ardından **Kanala deneme mesajı gönder** düğmesiyle bağlantıyı doğrulayın.
Her dakika çalışan `loads:release-to-free` görevi süresi dolan ilanları herkese açar, ücretsiz şoförlere
bildirir ve kanala gönderir; gönderilemeyen mesaj 5 kez yeniden denenir.

## 4. Dış kaynak otomatik onayı (kanalla ilgisi yok)

Aynı ekranda **Otomatik onay** açıldığında her dakika çalışan görev, şu kriterleri sağlayan adayları
kendiliğinden yayınlar: kaynak aktif, kalkış ve varış çözümlenmiş, telefon var, (isteğe bağlı) fiyat ve tonaj var.
Kapalıyken adaylar Dış Kaynak İlanları ekranında elle onaylanır.

## Sorun giderme

- Deneme mesajı gitmiyor: bot kanala yönetici olarak eklendi mi, "mesaj gönderme" yetkisi var mı?
- `chat not found`: kanal kimliği yanlış; herkese açık kanalda `@` ile başlamalı.
- Sunucuda zamanlayıcı çalışıyor mu: `crontab -u www-data -l` satırında `schedule:run` olmalı.
- Günlük: `grep -i telegram /var/www/navluniq/storage/logs/laravel.log | tail`
