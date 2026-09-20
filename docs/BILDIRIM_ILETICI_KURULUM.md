# Android bildirim iletici kurulumu (WhatsApp ilanları)

WhatsApp'a hiçbir cihaz bağlanmaz. Telefonda çalışan MacroDroid uygulaması, seçili grupların
bildirim metnini NavlunIQ'ya iletir; sunucu tekrarları eler, ilanı ayrıştırır ve onay kuyruğuna alır.

## 1. En kolay yol: kurulum bağlantısı (tek dokunuş)

Panel → **Dış Kaynak İlanları → Kaynaklar ve telefon** → "Kurulum bağlantısı". Bu bağlantıyı (ya da QR'ı) telefon
sahibine gönderin; sayfa adım adım anlatır, hazır **NavlunIQ.macro** dosyasını indirtir ve "Sunucuya son ulaşan istek"
satırıyla kurulumun çalıştığını gösterir. Bağlantıyı bilen herkes kurabilir; **Bağlantıyı yenile** eski bağlantıyı öldürür.

Hazır dosya için bir kez, çalışan telefonda MacroDroid → makro → **Dışa aktar** ile alınan `.macro` dosyasını panele
yükleyin. İndirilen kopya her zaman **güncel anahtarı ve adresi** taşır; anahtar yenilense bile telefona dosyayı yeniden
yüklemek yeter. Şablon yüklenmemişse kurulum sayfası elle kurulum adımlarını ve gövdeyi gösterir.

Adres + gövde hangi telefona yazılırsa o telefon sunucuya ilan iletmeye başlar; sunucu tarafında telefon başına ayar
yoktur. "Yanıtı değişkene kaydet" seçeneği gerekmez; olsa da olmasa da makro çalışır.

## 2. Sunucu: gizli anahtar (panelden)

Anahtar sunucuda **kendiliğinden üretilir** ve panelde durur; `.env` düzenlenmez.
"Elle kurulum için adres ve gövde" bölümü adresi ve anahtarı **hazır JSON gövdesi** olarak gösterir; **Kopyala** ile alıp
MacroDroid'e yapıştırırsınız. (Eski kurulumlarda `.env` içindeki `SCRAPER_API_TOKEN` panelde anahtar yoksa geçerli kalır.)

Uç nokta: `https://navluniq.com/api/v1/webhook/notification` (POST, JSON).
Alanlar: `title` (bildirim başlığı = grup adı), `text` (bildirim metni), `ticker` (isteğe bağlı; "Gönderen @ Grup: mesaj"),
`app` (uygulama adı), `token`.

## 3. Telefon: WhatsApp bildirim ayarları

1. WhatsApp → Ayarlar → Bildirimler: **Bildirim önizlemesini göster** açık olmalı (metin bildirimde görünsün).
2. Dinlenmeyecek grupları sessize alın (grup → üç nokta → Bildirimleri sessize al → Her zaman).
3. Dinlenecek her grup için: grup → Grup bilgisi → Bildirimler → **Sessiz** bildirim seçin (ses yok ama bildirim düşer).
   Bildirimi tamamen kapatmayın; kapalı grupların mesajı iletilmez.
4. Android → Ayarlar → Uygulamalar → WhatsApp → Pil: **Kısıtlama yok** (arka planda gecikme olmasın).

## 4. Telefon: MacroDroid (elle kurulum)

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

## 5. Deneme

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
  kuyruğu çalışmıyordur — sunucuda `bash /root/update.sh` cron'u yeniden kurar), **Otomatik onay**, **Yapay zeka**.
- Dış kaynak ilanlar Telegram kanalına gönderilmez; kanal yalnız sistem ilanları içindir (docs/TELEGRAM_KANAL_KURULUM.md).

## Yapay zeka ile ilan anlama (ücretsiz sağlayıcılar)

Kural tabanlı çözümleme her ilanda ücretsiz çalışır. Yapay zeka **Sistem Ayarları → Dış kaynak ve Telegram → Yapay zeka**
bölümünden açılır; anahtarlar veritabanında şifreli saklanır, `.env` gerekmez.

- **Kural eksik bırakınca** (varsayılan): kural telefon, il veya araç tipini çözemediyse yapay zekaya sorulur.
- **Her ilanda**: her aday yapay zekaya gider (en isabetli; il/ilçe, araç, tonaj, fiyat, yük türü, aciliyet ve
  "bu bir yük ilanı değil" ayrımı). Yük ilanı olmadığına yüksek güvenle karar verilen mesaj elenir.
- **Kapalı**: yalnız kural.

**Sağlayıcı zinciri.** Anahtarı girilen sağlayıcılar sırayla denenir; günlük kotası dolan (429) atlanır, ertesi gün
yeniden denenir; hiçbiri yanıt vermezse aday "yapay zeka bekliyor" kalır ve `scraped-loads:ai-enrich` görevi 5 dakikada
bir yeniden dener. Varsayılan sıra ücretsiz katmanlardan başlar:

| Sıra | Sağlayıcı | Ücretsiz katman | Anahtar |
|---|---|---|---|
| 1 | Google Gemini (Flash-Lite / Flash) | Kart istemez; günlük istek sınırı modele göre (Flash-Lite daha yüksek) | aistudio.google.com/apikey |
| 2 | Groq (Llama 3.3 70B) | Kart istemez; günlük ~1.000 istek, çok hızlı | console.groq.com/keys |
| 3 | Cerebras (Llama 3.3 70B) | Kart istemez; günlük ~1 milyon jeton | cloud.cerebras.ai |
| 4 | OpenRouter (":free" modeller) | Kartsız günlük ~50 istek; bir kez 10 $ kredi alınırsa günlük 1.000 | openrouter.ai/keys |
| 5 | Mistral (Small) | Deneme katmanı, telefon doğrulaması ister; aylık ~1 milyar jeton | console.mistral.ai |
| 6 | Claude (Anthropic) | Ücretli; yalnız istenirse, zincirin sonunda | console.anthropic.com |

Kota rakamları sağlayıcıların o günkü politikasına bağlıdır; panelde her sağlayıcının yanında "Bugün: N çağrı" sayacı
ve kota dolduysa uyarı görünür. İlk 2-3 sağlayıcıya anahtar girmek günde binlerce ilanı ücretsiz karşılar.

**Hatasız içerik güvencesi.** Kuralın kesin çözdüğü il ile yapay zekanın bulduğu il farklıysa ilan otomatik yayınlanmaz;
kuyrukta "kural ve yapay zeka farklı il buldu; elle kontrol" uyarısıyla bekler (filtre: "Kural / yapay zeka çelişen").
Yapay zeka güveni %50'nin altındaysa da elle kontrol istenir. Yöneticinin elle düzenlediği ilanlara dokunulmaz.
Kuyrukta **Yapay zeka ile çözümle** ile tek tek veya toplu yeniden çözümleme yapılabilir.

## Sorun giderme

- Kaynak listesinde grup görünmüyor: MacroDroid'in bildirim erişimi ve WhatsApp'ın bildirim önizlemesi açık mı?
  MacroDroid → Sistem günlüğü'nde HTTP isteğinin gönderilip gönderilmediğini görebilirsiniz.
- Yanıt `401` (Canlı akışta "Anahtar hatalı"): telefondaki `token` panelde gösterilenle aynı değil; gövdeyi panelden yeniden kopyalayın.
- Yanıt `status: filtered`: mesajda telefon numarası ya da lojistik işaret yok, il çözülemedi veya yapay zeka "ilan değil" dedi; Canlı akış nedenini yazar.
- Yanıt `status: source_pending`: grup Kaynaklar listesine pasif düşmüştür; aktif edince sonraki mesajlar işlenir.
- Yanıt `status: duplicate`: aynı ilan başka gruptan daha önce gelmiştir; beklenen davranıştır.
- Canlı akış tamamen boşsa telefon istek atmıyordur: MacroDroid → Sistem günlüğü'nde makronun tetiklenip tetiklenmediğine bakın (bildirim erişimi, pil kısıtı).
- Sunucu günlüğü: `tail -f /var/www/navluniq/storage/logs/laravel.log`
