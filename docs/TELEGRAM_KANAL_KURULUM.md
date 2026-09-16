# Telegram kanalı kurulumu

Onaylanan dış kaynak ilanları, ücretsiz üyelere açıldığı anda (varsayılan 20 dakika sonra)
Telegram kanalına otomatik gönderilir. Mesajda rota, yük, tonaj, fiyat, kaç kaynakta görüldüğü,
maskeli telefon ve siteye bağlantı bulunur. Tam numara isteğe bağlı olarak açılabilir.

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
- Telegram kanal kimliği: `@navluniq_ilanlar`
- Telegram kanalına paylaş: **Açık**
- Telegram mesajında tam numara: varsayılan **maskeli** (premium değerini korur, kanal siteye üye toplar)

**Kaydet**, ardından **Kanala deneme mesajı gönder** düğmesiyle bağlantıyı doğrulayın.

## 4. Otomatik onay

Aynı ekranda **Otomatik onay** açıldığında her dakika çalışan görev, şu kriterleri sağlayan adayları
kendiliğinden yayınlar: kaynak aktif, kalkış ve varış çözümlenmiş, telefon var, (isteğe bağlı) fiyat ve tonaj var.
Kapalıyken adaylar Dış Kaynak İlanları ekranında elle onaylanır.

## Sorun giderme

- Deneme mesajı gitmiyor: bot kanala yönetici olarak eklendi mi, "mesaj gönderme" yetkisi var mı?
- `chat not found`: kanal kimliği yanlış; herkese açık kanalda `@` ile başlamalı.
- Sunucuda zamanlayıcı çalışıyor mu: `crontab -u www-data -l` satırında `schedule:run` olmalı.
- Günlük: `grep -i telegram /var/www/navluniq/storage/logs/laravel.log | tail`
