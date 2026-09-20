# Android bildirim iletici kurulumu (WhatsApp ilanları)

WhatsApp'a hiçbir cihaz bağlanmaz. Telefonda çalışan MacroDroid uygulaması, seçili grupların
bildirim metnini NavlunIQ'ya iletir; sunucu tekrarları eler, ilanı ayrıştırır ve onay kuyruğuna alır.

## 1. Sunucu: gizli anahtar (panelden)

Anahtar sunucuda **kendiliğinden üretilir** ve panelde durur; `.env` düzenlenmez.
Yönetim paneli → **Dış Kaynak İlanları → Kaynaklar ve telefon** sekmesinde "Telefon bağlantısı" kutusu
adresi ve anahtarı **hazır JSON gövdesi** olarak gösterir; **Kopyala** ile alıp MacroDroid'e yapıştırırsınız.
**Anahtarı yenile** bağlantısı yeni anahtar üretir; o zaman tüm telefonlarda gövde değiştirilmelidir.
(Eski kurulumlarda `.env` içindeki `SCRAPER_API_TOKEN` panelde anahtar yoksa geçerli kalır.)

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
- Gövde: panelin **Kaynaklar ve telefon** sekmesindeki hazır gövdeyi yapıştırın (süslü parantezli alanlar MacroDroid değişkenleridir, elle yazılırsa sihirli metin düğmesinden seçin):

```json
{"title": "{not_title}", "text": "{notification}", "ticker": "{not_ticker}", "app": "{not_app_name}", "token": "PANELDEKI_ANAHTAR"}
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

Yönetim paneli → **Dış Kaynak İlanları → Canlı akış** sekmesinde telefondan gelen **her istek** anında listelenir
(sonuç: kuyruğa alındı / tekrar / elendi + nedeni / kaynak onay bekliyor / anahtar hatalı). Telefon hiç istek
atmıyorsa akış boş kalır; sorun telefondadır. **Kaynaklar ve telefon** listesinde grup adıyla pasif bir kaynak
belirir; kaynağı **aktif** edin. Sonraki ilanlar **İnceleme kuyruğuna** düşer; yayınlananlar premium şoförlere
anında, diğerlerine 20 dakika sonra açılır.

## Yönetici ekranı

- **İnceleme kuyruğu**: arama (rota, yük, ham mesaj, #no), kaynak / araç / durum (çözülmemiş il, fiyatlı, fiyatsız, acil,
  tekrar, yapay zeka bekleyen) / dönem / sıralama filtreleri. Satır seçip **toplu** yayınla, reddet, yapay zeka ile
  çözümle, kalıcı sil. Her satırda "Otomatik onay" satırı adayın neden kendiliğinden yayınlanmadığını söyler.
- **Yayında**: yayındaki dış kaynak ilanları; geri çekme/reddetme.
- **Reddedilenler**: kuyruğa geri alma, tek tek veya toplu **kalıcı silme**, "Tümünü temizle". Reddedilenler ayarlardaki
  saklama süresi (varsayılan 7 gün) sonunda kendiliğinden silinir.
- **Canlı akış**: telefondan gelen isteklerin günlüğü (5 sn'de bir yenilenir).
- **Kaynaklar ve telefon**: hazır MacroDroid gövdesi, anahtar yenileme, kaynak ekleme/aktif-pasif/silme.
- Üstteki rozetler: **Zamanlayıcı** (cron son 3 dakikada çalıştıysa yeşil; kırmızıysa otomatik onay, temizlik ve yapay zeka
  kuyruğu çalışmıyordur — sunucuda `bash /root/update.sh` cron'u yeniden kurar), **Otomatik onay**, **Yapay zeka**, **Telegram**.

## Yapay zeka ile ilan anlama

Kural tabanlı çözümleme her ilanda ücretsiz çalışır. Yapay zeka **Sistem Ayarları → Dış kaynak ve Telegram → Yapay zeka**
bölümünden açılır; anahtar veritabanında şifreli saklanır, `.env` gerekmez.

- **Kural eksik bırakınca** (varsayılan): kural telefon, il veya araç tipini çözemediyse yapay zekaya sorulur.
- **Her ilanda**: her aday yapay zekaya gider (en isabetli; il/ilçe, araç, tonaj, fiyat, yük türü, aciliyet ve
  "bu bir yük ilanı değil" ayrımı). Yük ilanı olmadığına yüksek güvenle karar verilen mesaj elenir.
- **Kapalı**: yalnız kural.
- Sağlayıcı: **Claude** (varsayılan model Claude Opus 5; Sonnet 5 ve Haiku 4.5 seçilebilir) veya **Gemini**.
  Claude yapılandırılmış JSON çıktı şeması ile çağrılır; kota/ağ hatasında aday "yapay zeka bekliyor" kalır ve
  `scraped-loads:ai-enrich` görevi 5 dakikada bir yeniden dener. Kuyrukta **Yapay zeka ile çözümle** ile tek tek
  veya toplu yeniden çözümleme yapılabilir.
- Kural çözemediği alanları yapay zeka doldurur; kuralın kesin (anahtar sözcük) araç eşleşmesi korunur, yöneticinin
  elle düzenlediği ilanlara dokunulmaz.

## İlan standardizasyonu

Her mesaj kaydedilmeden önce standartlaştırılır; onaylanırken bir kez daha çalışır:

- **Konum**: 81 il + 973 ilçe kataloğuna bağlanır; yazım hataları düzeltilir ("Diyarbakr" → Diyarbakır, "İstanbl Kartala" → İstanbul Kartal).
  İl çözülemeyen ilan yayınlanamaz; yönetici satırdaki **Düzenle** ile ili seçer.
- **Yük**: kataloğa bağlanır (Paletli yük, Beyaz eşya, Demir / çelik, Soğuk zincir gıda…); kırılgan, soğuk zincir, ADR, gabari dışı etiketleri eklenir.
- **Araç**: açık araç adı → kasa ipucu → tonaj → palet → hacim → yük türü. Araç yazmayan ilanda yükten en küçük uygun araç seçilir ve
  şoförlere "Orta Panelvan ve üzeri" gibi gösterilir; daha büyük tüm araçlar ilanı görür (otomobil hariç).
- **Fiyat**: "45 bin", "45.000 TL", "fiyat: 45000". **Aciliyet** ("acil", "hemen") ve **yükleme notu** ("yarın", "pazartesi") işaretlenir.
- Yöneticinin düzenlediği ilanlar sonraki otomatik standardizasyondan etkilenmez.
- Eski kayıtlar: `php artisan scraped-loads:classify` (update.sh her güncellemede çalıştırır; `--all` bütün kayıtlar).

## Sorun giderme

- Kaynak listesinde grup görünmüyor: MacroDroid'in bildirim erişimi ve WhatsApp'ın bildirim önizlemesi açık mı?
  MacroDroid → Sistem günlüğü'nde HTTP isteğinin gönderilip gönderilmediğini görebilirsiniz.
- Yanıt `401` (Canlı akışta "Anahtar hatalı"): telefondaki `token` panelde gösterilenle aynı değil; gövdeyi panelden yeniden kopyalayın.
- Yanıt `status: filtered`: mesajda telefon numarası ya da lojistik işaret yok, il çözülemedi veya yapay zeka "ilan değil" dedi; Canlı akış nedenini yazar.
- Yanıt `status: source_pending`: grup Kaynaklar listesine pasif düşmüştür; aktif edince sonraki mesajlar işlenir.
- Yanıt `status: duplicate`: aynı ilan başka gruptan daha önce gelmiştir; beklenen davranıştır.
- Canlı akış tamamen boşsa telefon istek atmıyordur: MacroDroid → Sistem günlüğü'nde makronun tetiklenip tetiklenmediğine bakın (bildirim erişimi, pil kısıtı).
- Sunucu günlüğü: `tail -f /var/www/navluniq/storage/logs/laravel.log`
