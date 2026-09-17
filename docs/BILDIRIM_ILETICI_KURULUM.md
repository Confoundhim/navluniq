# Android bildirim iletici kurulumu (WhatsApp ilanları)

WhatsApp'a hiçbir cihaz bağlanmaz. Telefonda çalışan MacroDroid uygulaması, seçili grupların
bildirim metnini NavlunIQ'ya iletir; sunucu tekrarları eler, ilanı ayrıştırır ve onay kuyruğuna alır.

## 1. Sunucu: gizli anahtar

Sunucuda bir kez çalıştırılır; üretilen anahtar telefona da yazılacaktır:

```bash
TOKEN=$(openssl rand -hex 24)
sed -i "s|^SCRAPER_API_TOKEN=.*|SCRAPER_API_TOKEN=${TOKEN}|" /var/www/navluniq/.env
cd /var/www/navluniq && php artisan config:cache
echo "Telefona yazılacak anahtar: ${TOKEN}"
```

Uç nokta: `https://navluniq.com/api/v1/webhook/notification` (POST, JSON).
Alanlar: `title` (bildirim başlığı = grup adı), `text` (bildirim metni), `ticker` (isteğe bağlı; "Gönderen @ Grup: mesaj"),
`app` (uygulama adı), `token`.

## 2. Telefon: WhatsApp bildirim ayarları

1. WhatsApp → Ayarlar → Bildirimler: **Bildirim önizlemesini göster** açık olmalı (metin bildirimde görünsün).
2. Dinlenmeyecek grupları sessize alın (grup → üç nokta → Bildirimleri sessize al → Her zaman).
3. Dinlenecek her grup için: grup → Grup bilgisi → Bildirimler → **Sessiz** bildirim seçin (ses yok ama bildirim düşer).
   Bildirimi tamamen kapatmayın; kapalı grupların mesajı iletilmez.
4. Android → Ayarlar → Uygulamalar → WhatsApp → Pil: **Kısıtlama yok** (arka planda gecikme olmasın).

## 3. Telefon: MacroDroid

1. Play Store'dan **MacroDroid** kurun (ücretsiz sürüm yeterlidir).
2. İlk açılışta istenen izinleri verin; **Bildirim erişimi** izni şarttır
   (Ayarlar → Bildirimler → Cihaz ve uygulama bildirimleri → MacroDroid → izin ver).
3. Ayarlar → Uygulamalar → MacroDroid → Pil: **Kısıtlama yok**.
4. **Makro ekle** ve üç parçayı doldurun:

**Tetikleyici**
- "Bildirim" → "Bildirim alındı" → uygulama olarak **WhatsApp** (varsa WhatsApp Business'ı da ekleyin)
- Metin eşleme: **Herhangi bir içerik**
- "Bildirim güncellendiğinde de tetikle" seçeneği varsa **kapalı** bırakın (aynı bildirim tekrar gönderilmesin;
  gönderilse de sunucu tekrarları eler)

**İşlem**
- "Bağlantı" → **HTTP İsteği**
- Yöntem: **POST**, adres: `https://navluniq.com/api/v1/webhook/notification`
- İçerik türü: **application/json**
- Gövde (sihirli metin düğmesinden seçerek yazın; süslü parantezli alanlar MacroDroid değişkenleridir):

```json
{"title": "{not_title}", "text": "{notification}", "ticker": "{not_ticker}", "app": "{not_app_name}", "token": "SUNUCUDAN_ALDIGINIZ_ANAHTAR"}
```

- Yeni Android sürümlerinde başlık "Grup (3 mesaj): Gönderen" biçimindedir; sunucu grup adını ve göndereni
  başlıktan ayıklar, kaynak her zaman grup adıyla (`notif:grup-adi`) açılır. `ticker` alanı yedek gönderen kaynağıdır.
  MacroDroid'de ticker değişkeni yoksa alanı silin, ilan yine işlenir (gönderen adı boş kalır).
- "Bildirim büyük metni" gibi genişletilmiş metin değişkeni sunuluyorsa `text` için onu seçin; uzun ilanlar kırpılmaz.
- Zaman aşımı 20 saniye; "Yanıtı değişkene kaydet" gerekmez.

**Kısıt**
- Boş bırakın. (İsteğe bağlı: yalnız Wi-Fi/mobil veri açıkken.)

5. Makroyu kaydedip **etkin** yapın.

## 4. Deneme

Dinlenen bir gruba deneme ilanı yazdırın, örneğin:
`Ankara'dan İzmir'e 24 ton palet yük, tenteli tır lazım 0532 123 45 67`

Yönetim paneli → **Dış Kaynak İlanları → Kaynaklar** listesinde grup adıyla pasif bir kaynak belirir.
Kaynağı **aktif** edin. Sonraki ilanlar **onay kuyruğuna** düşer; onaylananlar premium şoförlere anında,
diğerlerine 20 dakika sonra açılır.

## Sorun giderme

- Kaynak listesinde grup görünmüyor: MacroDroid'in bildirim erişimi ve WhatsApp'ın bildirim önizlemesi açık mı?
  MacroDroid → Sistem günlüğü'nde HTTP isteğinin gönderilip gönderilmediğini görebilirsiniz.
- Yanıt `401`: telefondaki `token` ile sunucudaki `SCRAPER_API_TOKEN` farklı; `config:cache` unutulmuş olabilir.
- Yanıt `status: filtered`: mesajda telefon numarası ya da lojistik işaret yok; sohbet sayılmıştır.
- Yanıt `status: source_pending`: grup Kaynaklar listesine pasif düşmüştür; aktif edince sonraki mesajlar işlenir.
- Yanıt `status: duplicate`: aynı ilan başka gruptan daha önce gelmiştir; beklenen davranıştır.
- Sunucu günlüğü: `tail -f /var/www/navluniq/storage/logs/laravel.log`
