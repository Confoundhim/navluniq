# Android bildirim iletici kurulumu (WhatsApp ilanları)

WhatsApp'a hiçbir cihaz bağlanmaz. Telefonda çalışan MacroDroid uygulaması, seçili grupların
bildirim metnini NavlunIQ'ya iletir; sunucu tekrarları eler, ilanı ayrıştırır ve onay kuyruğuna alır.

## 1. Kurulum bağlantısı

Panel → **Dış Kaynak İlanları → Kaynaklar ve telefon** → "Kurulum bağlantısı". Bu bağlantıyı (ya da QR'ı) telefon
sahibine gönderin; sayfa kurulumu adım adım anlatır, MacroDroid'e girilecek adres ve alanları hazır verir,
"Bu telefondan sunucuya ulaşabiliyor muyum? Sına" düğmesi ve "Sunucuya son ulaşan istek" satırıyla kurulumun
çalıştığını gösterir. Bağlantıyı bilen herkes kurabilir; **Bağlantıyı yenile** eski bağlantıyı öldürür.

Adres + alanlar hangi telefona yazılırsa o telefon sunucuya ilan iletmeye başlar; sunucu tarafında telefon başına ayar
yoktur. "Yanıtı değişkene kaydet" seçeneği gerekmez.

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
- **Kaynaklar ve telefon**: hazır MacroDroid gövdesi, anahtar yenileme, kaynak ekleme/aktif-pasif/silme. Silinen kaynak
  "Silinen kaynaklar" listesine düşer; ondan gelen mesajlar yok sayılır ama sayılır ("silindikten sonra N mesaj"). Oradan
  **Geri al** (onay bekleyen kaynak olur) ya da **Kalıcı sil** (grup ve ondan gelen tüm adaylar hiç okunmamış gibi silinir).
- Üstteki rozetler: **Zamanlayıcı** (cron son 3 dakikada çalıştıysa yeşil; kırmızıysa otomatik onay, temizlik ve yapay zeka
  kuyruğu çalışmıyordur — sunucuda `bash /root/update.sh` cron'u yeniden kurar), **Otomatik onay**, **Yapay zeka**.
- Dış kaynak ilanlar Telegram kanalına gönderilmez; kanal yalnız sistem ilanları içindir (docs/TELEGRAM_KANAL_KURULUM.md).

## Yapay zeka ile ilan anlama (ücretsiz sağlayıcılar)

Kural tabanlı çözümleme her ilanda ücretsiz çalışır. Yapay zeka **Sistem Ayarları → Dış kaynak ve Telegram → Yapay zeka**
bölümünden açılır; anahtarlar veritabanında şifreli saklanır, `.env` gerekmez.

- **Kural eksik bırakınca** (varsayılan): kural telefon, il veya araç tipini çözemediyse yapay zekaya sorulur.
- **Her ilanda (yapay zeka öncelikli, varsayılan)**: telefon numarası olan her mesaj yapay zekaya gider; "ilan mı,
  sohbet mi, boş araç ilanı mı" kararını ve il/ilçe, araç, tonaj, fiyat, yük türü, aciliyet alanlarını yapay zeka verir.
  Kural tabanlı ayrıştırma yalnız yedektir (yapay zeka yanıt vermezse). Telefonu olmayan mesaj kota harcamaz.
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
| 6 | Moonshot Kimi | Ücretli ama çok ucuz; deneme kredisi | platform.moonshot.ai |
| 7 | OpenAI (ChatGPT API) | Ücretli; ChatGPT'nin ücretsiz uygulaması API vermez | platform.openai.com |

Kota rakamları sağlayıcıların o günkü politikasına bağlıdır; panelde her sağlayıcının yanında "Bugün: N çağrı" sayacı
ve kota dolduysa uyarı görünür. İlk 2-3 sağlayıcıya anahtar girmek günde binlerce ilanı ücretsiz karşılar.

**Hatasız içerik güvencesi.** Kuralın kesin çözdüğü il ile yapay zekanın bulduğu il farklıysa ilan otomatik yayınlanmaz;
kuyrukta "kural ve yapay zeka farklı il buldu; elle kontrol" uyarısıyla bekler (filtre: "Kural / yapay zeka çelişen").
Yapay zeka güveni %50'nin altındaysa da elle kontrol istenir. Yöneticinin elle düzenlediği ilanlara dokunulmaz.
Kuyrukta **Yapay zeka ile çözümle** ile tek tek veya toplu yeniden çözümleme yapılabilir.

**Bir mesajda birden çok ilan, bir ilanda birden çok numara.** Gruplarda tek mesajla 5-10 yük listelenir ve "Ahmet
0532…, Mehmet 0533…" gibi birden çok irtibat yazılır. Sistem mesajı önce ilanlara ayırır, her ilanı ayrı aday olarak
kaydeder ve o ilana ait tüm numaraları saklar:

- **Yapay zeka öncelikli kipte** mesajın tamamı bir kez gönderilir; yapay zeka her ilanı (`ads`) kendi numaraları ve
  mesajdan aynen alıntısıyla döndürür. Ortak irtibat satırı her ilana eklenir. Tek çağrı = tek kota.
- **Kural yedeği**: boş satırla ayrılan bloklar, farklı il çifti taşıyan satırlar ve "numarası yazılmış ilandan sonra
  gelen yeni il satırı" ayrı ilan sayılır; numarasız rota satırları en yakın irtibat numarasını devralır.
- Her parça kendi tekrar denetiminden geçer (aynı ilan başka gruptan gelirse ilgili adayın sayacı artar).
- Ana numara `encrypted_sender_phone`, diğerleri `parse_metadata.extra_phones_enc` içinde şifreli tutulur. Şoför
  panelinde premium üye tüm numaraları arama/WhatsApp bağlantısıyla görür; ücretsiz üye maskeli numara ve "+N numara
  daha" görür. Kuyrukta "+N numara" ve "mesajın 2/5. ilanı" notu bulunur.
- Canlı akışta bir mesajın her ilanı ayrı satırdır (kuyruğa alındı / tekrar / elendi kendi gerekçesiyle).

## Kendi ekosistemimizde öğrenen çözümleme (dış servise bağımlı olmayan katman)

Dış yapay zeka sağlayıcıları kota, bakiye ve erişim sorunlarıyla kesilebilir. Bu yüzden sistemin kendi içinde,
sizin kararlarınızdan öğrenen iki parça vardır; ikisi de **Dış Kaynak İlanları → Sözlük ve öğrenme** sekmesinden yönetilir.

**1. Jargon sözlüğü.** Tırcıların dilini siz öğretirsiniz, kural anında uygular; yapay zekaya gerek kalmaz:

| Tür | Örnek | Etkisi |
|---|---|---|
| Konum kısaltması / semt | `ostim` → Ankara, `gebze osb` → Kocaeli Gebze, `büsan` → Konya | Kalkış/varış çözümü, "il çözülemedi" engeli kalkar |
| Araç sözcüğü | `mega tenteli` → TIR, `açık kasa` → 6 teker kamyon | Araç tipi kesin eşleşme |
| Yük sözcüğü | `salça` → Gıda | Yük kategorisi |
| "İlan değil" ifadesi | `satılık`, `iş arıyorum` | Mesaj kota harcamadan elenir (canlı akışta "sözlük: ilan değil") |
| İlan işareti | `yükümüz var` | Kural ön elemesini geçer |

Kuyrukta bir adayın **ilini düzelttiğinizde** mesajdaki çözülemeyen yer adı kendiliğinden sözlüğe girer ("öğrenildi").
Araç tipini ya da yükü düzelttiğinizde sekmeye bir **öneri** düşer; mesajdaki sözcüğü yazıp "Öğret" derseniz kalıcı olur.

**2. Yerel sınıflandırıcı (ilan mı, değil mi).** Naive Bayes; veritabanında sözcük sayaçları tutar. "Yayınla" dediğiniz
her aday ve yapay zeka doğrulamalı otomatik onaylar ilan örneği, "Reddet" dediğiniz her aday ilan-değil örneğidir.
Her sınıfta 15 örnek olunca karar vermeye başlar:

- Alımda her adaya "yerel %N" güveni yazılır (kuyrukta görünür). Çok düşük olasılıklı metin (yapay zeka bakmadıysa) elenir.
- **Dış yapay zeka kotası dolduğunda / ulaşılamadığında** otomatik onay durmaz: yerel güven ayarlardaki eşiğin
  (varsayılan %90) üstündeyse aday kendiliğinden yayınlanır. 3 saatten uzun süre yapay zeka bekleyen aday "ulaşılamadı; elle kontrol" der.
- "Geçmişten yeniden öğren" düğmesi sayaçları sıfırlayıp tüm yayınlanmış/reddedilmiş adaylardan yeniden öğrenir
  (komut: `php artisan ai:learn --rebuild`).

Ayarlar: Sistem Ayarları → Dış kaynak → "Yerel öğrenen sınıflandırıcı" (açık/kapalı) ve "en düşük yerel güven (%)".

## Sorun giderme

- Kaynak listesinde grup görünmüyor: MacroDroid'in bildirim erişimi ve WhatsApp'ın bildirim önizlemesi açık mı?
  MacroDroid → Sistem günlüğü'nde HTTP isteğinin gönderilip gönderilmediğini görebilirsiniz.
- Canlı akış tamamen boşsa önce **sınama bağlantısını** (panel → Kaynaklar ve telefon → Sorun giderme, ya da kurulum
  sayfasındaki "Sına" düğmesi) telefonun tarayıcısında açın. "Bağlantı sınaması" satırı düşerse ağ ve anahtar tamamdır;
  sorun MacroDroid'dedir: bildirim erişimi izni, sessize alınmış grup, sohbet açıkken gelen mesaj, **kendi yazdığınız mesaj
  bildirim üretmez** (başkası yazmalı), WhatsApp Business kullanılıyorsa tetikleyicide o uygulama seçilmeli.
  MacroDroid'de HTTP İsteği eylemine uzun basıp "Eylemi test et" ile makronun istek atabildiği görülür (Canlı akışta "Atlandı").
- Mesajda tırnak ya da satır sonu varsa JSON gövde bozulur; sunucu bunu onarır ama en sağlamı içerik türünü
  **application/x-www-form-urlencoded** yapıp alanları "Parametreler" bölümüne girmektir (panel ve kurulum sayfası listeler).
- Yanıt `401` (Canlı akışta "Anahtar hatalı"): telefondaki `token` panelde gösterilenle aynı değil; gövdeyi panelden yeniden kopyalayın.
- Yanıt `status: filtered`: mesajda telefon numarası ya da lojistik işaret yok, il çözülemedi veya yapay zeka "ilan değil" dedi; Canlı akış nedenini yazar.
- Yanıt `status: source_pending`: grup Kaynaklar listesine pasif düşmüştür; aktif edince sonraki mesajlar işlenir.
- Yanıt `status: duplicate`: aynı ilan başka gruptan daha önce gelmiştir; beklenen davranıştır.
- Canlı akış tamamen boşsa telefon istek atmıyordur: MacroDroid → Sistem günlüğü'nde makronun tetiklenip tetiklenmediğine bakın (bildirim erişimi, pil kısıtı).
- Sunucu günlüğü: `tail -f /var/www/navluniq/storage/logs/laravel.log`
