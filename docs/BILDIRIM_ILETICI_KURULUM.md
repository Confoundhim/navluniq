# Android bildirim iletici kurulumu (WhatsApp ilanları)

WhatsApp'a hiçbir cihaz bağlanmaz. Telefonda çalışan MacroDroid uygulaması, seçili grupların
bildirim metnini NavlunIQ'ya iletir; sunucu tekrarları eler, ilanı ayrıştırır ve onay kuyruğuna alır.

## 1. Kurulum nasıl yapılır

Herkese açık bir kurulum sayfası **yoktur**. Kurulum, panel → **Dış Kaynak İlanları → Kaynaklar ve telefon**
sekmesindeki adres ve alanlarla, aşağıdaki 3. ve 4. bölümlerdeki adımlar izlenerek yöneticinin kendisi tarafından
yapılır. Adres + alanlar hangi telefona yazılırsa o telefon sunucuya ilan iletmeye başlar; sunucu tarafında telefon
başına ayar yoktur. "Yanıtı değişkene kaydet" seçeneği gerekmez. Kurulumun çalıştığını aynı sekmedeki
"Sınama bağlantısı" ile (telefonun tarayıcısında açılır, Canlı akışa "Bağlantı sınaması" düşer) doğrularsınız.

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
açılır; standart üyeler dış kaynak ilanlarını görmez (yalnız premium).

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

Panelde yalnız bu ikisi görünür; Cerebras, OpenRouter, Mistral, OpenAI, Kimi, Claude ve xAI denemede ya ücretli çıktı
ya da kullanılamaz kotalar verdi (kod içinde gizli dururlar). Panelde her sağlayıcının yanında "Bugün: N çağrı" sayacı
ve kota dolduysa uyarı görünür. Hacim büyüyünce Google'da faturalandırmayı açıp Flash-Lite'ı ücretli katmanda kullanmak
en ucuz yoldur (1.000 ilan ≈ 0,2-0,35 $).

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

**Kural katmanı: gerçek grup mesajlarıyla eğitildi.** 23 yük grubundan 9.000 mesajlık bir dışa aktarım üzerinde
ölçülerek kurallar yazıldı (`php artisan intake:analyze dosya.txt` ile ölçüm tekrarlanabilir). Kural şu biçimleri
yapay zekasız çözer:

- **Biçim temizliği** (`TextPrep`): *kalın*/_eğik_ işaretleri, süs satırları (➖➖➖, ━━━, 🔥🔥) blok ayırıcı olur, emoji oklar
  (➡️ 👉 ▶ 🔹 🟰 ➤ »), "…", "__", "--》", ">>" ve iki yer adı arasındaki boşluksuz emoji (MERSİN📍MİDYAT) bağlaç sayılır,
  harf aralıklı sözcükler ("T I R", "O R D U") birleşir, süslü yazı tipleri (ꜱᴇʀɪ̇ɴ ɴᴀᴋʟɪ̇ʏᴀᴛ, 𝐆𝐈𝐃𝐄𝐑 𝐅𝐈𝐒𝐈) düz harfe
  iner. Kiril/Arap alfabesi mesajlar elenir.
- **Rota**: "X - Y", "X ➡️ Y", "X'den Y'ye", "Xden Y", "X, Y" (iki uç da yer ise), "X yükler / Y iner-boşaltır-teslim",
  "X yüklemeli" başlığı altında liste (her varış satırı ayrı ilan; başlık boş satırdan sonra da geçerlidir), tek satırda
  "BOLU YÜKLER ANTALYA BOŞALTIR", "İstanbul Arnavutköy Şırnak Silopi 🚛 TIR" (il+ilçe çiftleri), "Denizli - şehir içi".
- **Yer adları**: 81 il + 973 ilçe kataloğu, kısaltmalar (K.Maraş, Ş.Urfa, Antep, İst, Anadolu/Avrupa yakası), ekli yazım
  (Tarsus'tan, Aliağaya, Kızıltepeden), 6+ harfte yazım hatası (BALIKKESIR, ANAKRA), semt/sanayi/liman/OSB adları
  (Hadımköy, İkitelli, Kemerburgaz, Ostim, Siteler, Gimat, Temelli, Koçhisar, Kazan, Çayırhan, Karabiga, Misis,
  Şekerpınar, Velimeşe, Marport, Ambarlı…), sınır kapıları (Cilvegözü, Habur, Gürbulak, Kapıkule, Sarp, Öncüpınar,
  Çobanbey) ve **yurt dışı varışlar** (Erbil, Zaho, Süleymaniye, Bazargan, Tebriz, Bakü, Tiflis, Kazakistan… "Erbil (Irak)"
  etiketiyle; ilan otomatik onaya girer).
- **Araç**: 13.60/1360/13-60 → TIR, 8.60 → 10 teker, kısa/sal dorse, 2-3 kapak, 40 ayak, tente/tenten/tnt, frigo/firgo/
  firigo/termoking, damper(li)/danper, yüksek yan, kapalı/açık tır, ATS'li, üstten yükleme, "N metre" (3 m kamyonet,
  6 m kamyon), dökme/basar tonaj/sınırsız damperli → tır. Metinde araç yoksa yapay zekaya sorulmaz; tonaj ve yükten çıkarılır.
- **Tonaj ve fiyat**: "0-25 ton", "20 25 ton", "0,2 ton", "2.000 kg"; "950+BASAR", "900+TONAJLI", "1300+KDV", "28+kdv"
  (=28.000), "40.000 peşin", "1 400 TL", "1800$", "1750 USD" (para birimi USD/EUR saklanır).
- **Yük**: kömür (dökme/torbalı/çuvallı), dökme maden (klinker, pomza, mıcır, grit, cüruf), tuz, gübre/kemre, lastik,
  tarım (saman, silaj, balya, yem, yonca, pancar), tahta cips, tavuk/yumurta, peçete/kağıt, meyve-sebze, demir-çelik…
- **Ortak bağlam**: mesajın başındaki/sonundaki "KAPALI TIR", "13-60 TENTELİ-FRİGO", "ARAÇLAR DAMPER DORSE OLACAK",
  "ÖDEME PEŞİN" satırları her ilana eklenir; sondaki tek numara tüm ilanlara yazılır.

Ölçüm (9.056 mesaj): tekrar/telefonsuz/sohbet elemesi sonrası 4.967 aday mesaj → 16.400 ilan parçası; kural
kalkış-varış-telefonu parçaların **%99'unda** çözdü. Yapay zeka yalnız kalan %1 ve yeni biçimler için gerekir.

**Şablon hafızası.** Yük gruplarındaki ilanların çoğu aynı komisyoncuların her gün aynı kalıpla attığı ilanlardır.
Bir gönderenin (numara) bir kalıbı yapay zeka (güven ≥ %80, kuralla çelişmeden) ya da yönetici onayıyla bir kez
doğrulanınca kalıp saklanır: yer adları `{yer}`, sayılar `{n}`, telefon `{tel}` olur; hangi yer adının kalkış, hangisinin
varış olduğu sırayla bilinir. Aynı numaradan **aynı kalıba uyan** sonraki ilan yapay zekasız çözülür (kuyrukta
"Çözümleme: şablon"), yapay zeka doğrulaması sayılır ve otomatik onaya girer. Kalıba uymayan mesaj yine yapay zekaya
gider; kalıp kişiye özeldir (başka numara aynı kalıpla yazsa yapay zekaya gider). Yapay zekanın "ilan değil" dediği
kalıp da öğrenilir ve aynı gönderenden bir daha sorulmaz. Kalıptan çözülen bir adayı **reddederseniz kalıp silinir**.

**2. Yerel sınıflandırıcı (ilan mı, değil mi).** Naive Bayes; veritabanında sözcük sayaçları tutar. "Yayınla" dediğiniz
her aday ve yapay zeka doğrulamalı otomatik onaylar ilan örneği, "Reddet" dediğiniz her aday ilan-değil örneğidir.
Her sınıfta 15 örnek olunca karar vermeye başlar:

- Alımda her adaya "yerel %N" güveni yazılır (kuyrukta görünür). Çok düşük olasılıklı metin (yapay zeka bakmadıysa) elenir.
- **Dış yapay zeka kotası dolduğunda / ulaşılamadığında** otomatik onay durmaz: yerel güven ayarlardaki eşiğin
  (varsayılan %90) üstündeyse aday kendiliğinden yayınlanır. 3 saatten uzun süre yapay zeka bekleyen aday "ulaşılamadı; elle kontrol" der.
- "Geçmişten yeniden öğren" düğmesi sayaçları sıfırlayıp tüm yayınlanmış/reddedilmiş adaylardan yeniden öğrenir
  (komut: `php artisan ai:learn --rebuild`).
- **Grup dışa aktarımlarından toplu öğretme.** Sözlük sekmesindeki sayaçlar yalnızca sitede verilen kararları sayar; kural
  katmanına gömülen 9.000 mesaj oraya yazılmaz. Aynı dosyalarla sınıflandırıcıyı da beslemek için .txt dosyalarını sunucuya
  atıp (`scp .\WhatsApp_Sohbetleri.zip root@SUNUCU:/root/`, `unzip -o /root/WhatsApp_Sohbetleri.zip -d /root/wa`) şunu çalıştırın:

  ```bash
  cd /var/www/navluniq && php artisan intake:analyze /root/wa --learn
  ```

  Kuralın telefon + kalkış + varışla tam çözdüğü her parça "ilan", sabit kalıpla elenen mesajlar (e-fatura, şoför ilanı,
  boş araç) "ilan değil" örneği olur; aynı sözcük dizisi bir kez sayılır. Numarası profilde olan ilanlar olumsuz örnek
  sayılmaz. 9.000 mesaj sunucuda 3-5 dakika sürer; sonunda "Yerel sınıflandırıcı öğrendi: … ilan, … ilan-değil" satırı
  görünür ve Sözlük sekmesindeki sayaçlar yükselir. Dosyaları işiniz bitince silin: `rm -rf /root/wa /root/WhatsApp_Sohbetleri.zip`.
- Sınıflandırıcı sınıf öncülü kullanmaz: binlerce ilan örneğine karşı birkaç ret, her metni "ilan" saymaz; her sözcüğün
  katkısı sınırlıdır ve 3'ten az görülen sözcük sayılmaz. "İlan değil" diye **eleme** en az 50 ilan-değil örneğinden sonra
  başlar; ondan önce yalnızca güven puanı üretir (otomatik onay için).

Ayarlar: Sistem Ayarları → Dış kaynak → "Yerel öğrenen sınıflandırıcı" (açık/kapalı) ve "en düşük yerel güven (%)".

## Yerel model (Ollama): dış servise hiç bağlı olmayan yapay zeka

> **Şu an kapalı.** 2 çekirdek / 6 GB'lık sunucuda model işlemciyi kilitleyip siteyi yavaşlattığı için kaldırıldı.
> Panelde görünmez; yalnız `.env` içine `AI_ALLOW_LOCAL_MODELS=true` yazılırsa (GPU'lu ya da büyük sunucuda) açılabilir.
> Kaldırmak için: `systemctl disable --now ollama; rm -rf /etc/systemd/system/ollama.service /etc/systemd/system/ollama.service.d /usr/local/bin/ollama /usr/local/lib/ollama /usr/share/ollama; userdel ollama`.

Sözlük ve sınıflandırıcı "ilan mı / hangi il" sorularını çözer; alanları (araç, tonaj, fiyat, yük, aciliyet) serbest
metinden anlamak için yine bir dil modeli gerekir. Bunu da sunucuda çalıştırabilirsiniz: kota yok, anahtar yok,
veri dışarı çıkmaz. Açıkken zincirin başındadır; yanıt veremezse dış sağlayıcılara düşülür.

**Gereksinim.** Boş RAM: 4B model için ~4 GB, 7B için ~7 GB. İşlemcide bir ilan 10-40 sn sürer (günde birkaç yüz ilan
için yeterli). Sunucuda `free -h` ile boş belleğe bakın; 4 GB'tan azsa RAM artırmadan açmayın.

**Kurulum (Ubuntu, root):**

```bash
curl -fsSL https://ollama.com/install.sh | sh
systemctl enable --now ollama
ollama pull qwen3:4b
curl -s http://127.0.0.1:11434/v1/models
```

Son komut `qwen3:4b` içeren bir JSON dönerse hazırdır. Ollama yalnız 127.0.0.1'i dinler; dışarıdan erişilemez.

**Panel.** Sistem Ayarları → Dış kaynak → Yapay zeka → "Yerel model (Ollama)": **Açık**, adres `http://127.0.0.1:11434/v1`,
model **Otomatik** (qwen3 önce seçilir). "Bağlantıyı sına" ile örnek ilanı çözdürün. Zincir sırası: Yerel model → Gemini → Groq → …

Belleği az sunucularda `llama3.2:3b` (~2,5 GB) çalışır ama Türkçe isabeti düşer; `qwen3:4b` önerilir.

## Fiyat birimi: toplam mı, ton başına mı?

Dökme yüklerde ("dökme üzüm 1000+kdv", "kömür 950+basar", "ton başı 1.250 tl") fiyat ton başınadır. Kural bunu ayırt eder
ve kayıtta `price_unit` = `per_ton` olarak tutar; listelerde "1.000 ₺/ton" yazar. Toplam navlun ("45.000 tl", "28+kdv" = 28.000)
`total` olarak kalır. Ton başına sayılanlar: açık yazım (ton başı, tonu, tl/ton), "+basar" / "+tonajlı" ve dökme yük sözcüğü
(dökme, damper, kömür, kum, hububat, üzüm, gübre…) ile birlikte 5.000'in altındaki "+kdv" tutarları. Yönetici düzenleme
penceresinde fiyatın yanındaki "Toplam / Ton başına" seçimiyle düzeltilebilir; yapay zeka da `price_per_ton` alanıyla bildirir.

## Aynı ilanın iki gruptan gelmesi

Aynı metin iki gruptan aynı saniyede gelince iletici her grubu ayrı istek olarak yollar. İki istek yan yana işlenirken tekrar
denetimi henüz yazılmamış kaydı göremiyordu ve aynı ilan iki kez yayınlanabiliyordu. İki koruma var:

- **Mesaj kilidi:** aynı metin için ikinci istek ilkinin bitmesini bekler, sonra kaydı bulur ve "başka kaynaktan alındı"
  diyerek sayaca yazar.
- **Yayın anı denetimi:** "Yayınla" ya da otomatik onay sırasında aynı metin (7 gün) ya da aynı numara + il çifti (48 saat)
  zaten yayındaysa aday yayınlanmaz, "tekrar (#N yayında)" diye reddedilir ve görüldüğü grup yayındaki ilanın sayacına eklenir.

## Yedekleme

Panel → **Yedekleme** (Yönetim ve sistem). "Şimdi tam yedek al" veritabanını (tüm ayarlar, kaynaklar, ilanlar,
kullanıcılar), `.env` dosyasını ve yüklenen dosyaları (KYC belgeleri, faturalar, herkese açık dosyalar) tek zip'e
yazar; **İndir** ile bilgisayarınıza alırsınız. Her gece 03:30'da otomatik tam yedek alınır, son 14 yedek sunucuda
`storage/app/backups` altında tutulur (web'den erişilemez). Sunucudan elle:

```bash
cd /var/www/navluniq && sudo -u www-data php artisan system:backup          # tam yedek
cd /var/www/navluniq && sudo -u www-data php artisan system:backup --type=database
```

Bilgisayara toplu kopyalama (PowerShell): `scp "root@185.22.187.140:/var/www/navluniq/storage/app/backups/*.zip" "$HOME\Downloads\"`.
Zip'in içindeki `BENIOKU.txt` yeni sunucuda geri yükleme adımlarını anlatır (env.txt → .env, `mysql < database.sql`,
storage klasörleri, `php artisan migrate --force`). Yedek dosyası anahtarları ve belgeleri içerir; paylaşmayın.

## Sorun giderme

- Kaynak listesinde grup görünmüyor: MacroDroid'in bildirim erişimi ve WhatsApp'ın bildirim önizlemesi açık mı?
  MacroDroid → Sistem günlüğü'nde HTTP isteğinin gönderilip gönderilmediğini görebilirsiniz.
- Canlı akış tamamen boşsa önce **sınama bağlantısını** (panel → Kaynaklar ve telefon → Sorun giderme, ya da kurulum
  sayfasındaki "Sına" düğmesi) telefonun tarayıcısında açın. "Bağlantı sınaması" satırı düşerse ağ ve anahtar tamamdır;
  sorun MacroDroid'dedir: bildirim erişimi izni, sessize alınmış grup, sohbet açıkken gelen mesaj, **kendi yazdığınız mesaj
  bildirim üretmez** (başkası yazmalı), WhatsApp Business kullanılıyorsa tetikleyicide o uygulama seçilmeli.
  MacroDroid'de HTTP İsteği eylemine uzun basıp "Eylemi test et" ile makronun istek atabildiği görülür (Canlı akışta "Atlandı").
- Mesajda tırnak ya da satır sonu varsa JSON gövde bozulur; sunucu bunu onarır ama en sağlamı içerik türünü
  **application/x-www-form-urlencoded** yapıp alanları "Parametreler" bölümüne girmektir (panel listeler).
- Yanıt `401` (Canlı akışta "Anahtar hatalı"): telefondaki `token` panelde gösterilenle aynı değil; gövdeyi panelden yeniden kopyalayın.
- Yanıt `status: filtered`: mesajda telefon numarası ya da lojistik işaret yok, il çözülemedi veya yapay zeka "ilan değil" dedi; Canlı akış nedenini yazar.
- Yanıt `status: source_pending`: grup Kaynaklar listesine pasif düşmüştür; aktif edince sonraki mesajlar işlenir.
- Yanıt `status: duplicate`: aynı ilan başka gruptan daha önce gelmiştir; beklenen davranıştır.
- Canlı akış tamamen boşsa telefon istek atmıyordur: MacroDroid → Sistem günlüğü'nde makronun tetiklenip tetiklenmediğine bakın (bildirim erişimi, pil kısıtı).
- Sunucu günlüğü: `tail -f /var/www/navluniq/storage/logs/laravel.log`
