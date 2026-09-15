# NavlunIQ WhatsApp ilan toplama servisi

Yalnızca `WHATSAPP_ALLOWED_GROUP_IDS` listesindeki grupların metin mesajlarını Laravel webhook ucuna iletir. Ayrıştırma, telefon şifreleme ve yayın kararı Laravel tarafında yapılır; servis hiçbir mesajı diske yazmaz.

Gruplardan veri toplamak için grup yöneticilerinin yazılı izni alınmalı ve `source_permissions` kaydı oluşturulmalıdır. Servis, resmi olmayan bir WhatsApp Web istemcisi (Baileys) kullanır; WhatsApp kullanım şartları açısından risk taşıdığını bilerek çalıştırın.

## Kurulum (Linux sunucu)

```bash
cd /var/www/navluniq/whatsapp-scraper-daemon
npm ci --omit=dev
cp .env.example .env    # değerleri doldurun
sudo mkdir -p /var/lib/navluniq-whatsapp && sudo chown www-data:www-data /var/lib/navluniq-whatsapp
```

İlk çalıştırmada terminalde QR kodu belirir; toplayıcı hesabın telefonuyla okutun (WhatsApp → Bağlı Cihazlar → Cihaz Bağla).

## Grup kimliklerini bulma

`WHATSAPP_ALLOWED_GROUP_IDS` boşken servis yine bağlanır ve hesabın üye olduğu tüm grupları
`1203630xxxxx@g.us → Grup adı` biçiminde listeler; hiçbir mesaj iletilmez. İzin alınan grupların
kimliklerini `.env` içine virgülle yazıp servisi yeniden başlatın. Dinlenen gruplar listede ✔ ile
işaretlenir.

Yeni bir gruptan gelen ilk ilan, yönetim panelinde **Dış Kaynak İlanları → Kaynaklar** altında
pasif bir kaynak oluşturur; kaynak aktif edilene kadar o gruptan ilan alınmaz.

## systemd

`/etc/systemd/system/navluniq-whatsapp.service`:

```ini
[Unit]
Description=NavlunIQ WhatsApp ilan toplama servisi
After=network-online.target

[Service]
User=www-data
WorkingDirectory=/var/www/navluniq/whatsapp-scraper-daemon
EnvironmentFile=/var/www/navluniq/whatsapp-scraper-daemon/.env
ExecStart=/usr/bin/node index.js
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now navluniq-whatsapp
journalctl -u navluniq-whatsapp -f
```
