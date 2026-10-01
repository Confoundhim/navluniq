# NavlunIQ Toplayıcı (Android uygulaması)

Telefona kurulan kendi küçük uygulamamız. İki iş yapar, başka hiçbir şey yapmaz:

1. **WhatsApp grup bildirimleri** → telefonun bildirim çekmecesinde görünen her grup mesajını sunucuya iletir
   (MacroDroid'in yaptığı işin aynısı, aynı sunucu ucu, aynı alanlar).
2. **Facebook gönderileri** → kullanıcı Facebook'ta normal gezinirken ekranda görünen grup gönderilerini okur ve
   20 saniyede bir paket halinde sunucuya iletir. Düğme, otomatik kaydırma, makro yoktur.

Kod: `android/toplayici/` (Java, 6 sınıf). Derleme: `bash android/build.sh` → `public/toplayici/navluniq-toplayici.apk`
ve `version.json`. APK depoda durur; panelden ve `https://navluniq.com/toplayici/navluniq-toplayici.apk` adresinden
indirilir (anahtar içinde değildir, bağlantı herkese açıktır).

## Neden MacroDroid'den daha güvenli ve daha güvenilir

| Konu | MacroDroid makrosu | NavlunIQ Toplayıcı |
|---|---|---|
| İzinler | Genel amaçlı otomasyon uygulaması: SMS, konum, arama, kamera, kişiler… onlarca izin | Yalnız üç: internet, bildirim erişimi (WhatsApp), erişilebilirlik (Facebook ekranı). Ne SMS ne kişiler ne konum. |
| Veri nereye gider | MacroDroid'in bulut/şablon özellikleri var; makro paylaşımı mümkün | Yalnız `navluniq.com` adresine, TLS ile. Başka sunucu, analitik, reklam yok. Kod depoda açık. |
| Facebook hesabı riski | Makro ekranı kaydırıyor ve dokunuyor: bot gibi görünen düzenli hareket | Hiçbir şeye dokunmaz (isteğe bağlı tek istisna: kısaltılmış gönderinin "diğer" düğmesi). Kullanıcı kendi gezinir; uygulama yalnız ekran okuyucu gibi okur. Facebook sunucusuna bizden tek istek gitmez. Risk profili bir ekran okuyucuyla aynıdır. |
| WhatsApp hesabı riski | Yok (bildirim okuma) | Yok (aynı yöntem: bildirim okuma; WhatsApp'a bağlanılmaz, WhatsApp Web/bot yok). |
| Kurulumun kırılganlığı | Dışa aktarma dosyası alanları tahminle yazılıyordu; her yanlış alan bir deneme turuydu | Kod bizim; alan tahmini yok. Davranış testlerle ve kaynak kodla sabit. |
| Veri kaybı | İstek atılamazsa gider | Her paket önce telefonda kuyruğa yazılır; internet yoksa bekler, sonra sırayla gider. |
| Tekrar / gürültü | Aynı ekran her seferinde yeniden gönderilir | Aynı ekran bir daha gönderilmez; sunucu aynı gönderiyi 24 saat içinde ikinci kez kuyruğa almaz. WhatsApp'ta aynı mesaj bildirim her güncellendiğinde tekrar gitmez. |
| Sorun bulma | MacroDroid sistem günlüğünü okumak gerekir | Uygulama ekranında son gönderim, bekleyen paket ve günlük; ekran görüntüsü yeter. |
| Güncelleme | Dosyayı yeniden içe aktarma | Uygulama yeni sürümü kendisi bildirir, bağlantıyı açıp kurmak yeter (aynı imza, üstüne kurulur). |

### Dürüst riskler (yok sayılmaz)

- **Mağaza dışından kurulum (APK):** Android "bilinmeyen uygulama" uyarısı verir, Play Protect "tanınmayan uygulama" diyebilir;
  "Yine de yükle" denir. Play Store'a koymak ilerde mümkündür (geliştirici hesabı, inceleme).
- **Android 13 ve üstünde "kısıtlı ayar":** mağaza dışından kurulan uygulamaların erişilebilirlik/bildirim anahtarı ilk başta
  gridir; Uygulama bilgisi → ⋮ → "Kısıtlı ayarlara izin ver" denince açılır. Bir kez.
- **Üretici pil yöneticileri (Xiaomi, Huawei, Oppo, Samsung):** arka plandaki hizmetleri durdurabilir. Uygulama "Pil kısıtlamasını
  kaldır" düğmesiyle muafiyet ister; gerekirse "Otomatik başlat" açılır. MacroDroid de aynı kısıta tabidir.
- **Facebook arayüz değişikliği:** ekran metninin biçimi değişirse sunucu ayrıştırıcısı (`NotificationIntakeParser`) güncellenir;
  uygulama değişmez (ham metni yollar).
- **İlk sürüm cihazda denenmedi:** derleme ve paket doğrulandı (imza, izinler, erişilebilirlik tanımı), ama gerçek telefonda
  1-2 düzeltme turu olabilir. Fark: her tur kendi kodumuzda yapılır, tahmin değildir; uygulama günlüğü sorunu gösterir.
- **Erişilebilirlik hizmetlerinin görünürlüğü:** uygulamalar açık erişilebilirlik hizmetlerinin listesini görebilir; Facebook bunu
  görebilir (MacroDroid için de aynıdır). Ekran okuyucular, şifre yöneticileri aynı listede durur; yaygın ve olağandır.

## Kurulum (panelde de yazar)

1. Panel → Dış Kaynak İlanları → Kaynaklar ve telefon → **Uygulamayı indir (APK)**; bağlantı şoföre WhatsApp ile de gönderilebilir.
2. Telefonda dosyaya dokun → İzin ver → Yükle.
3. Uygulamayı aç → anahtarı yapıştır → **Kaydet** → **Bağlantıyı sına** (Canlı akışa "Bağlantı sınaması" düşer).
4. **WhatsApp bildirim iznini aç** ve **Facebook okuma iznini aç** (gri ise: Uygulama bilgisi → ⋮ → Kısıtlı ayarlara izin ver).
5. **Pil kısıtlamasını kaldır.**
6. Facebook'ta Gruplar akışında ya da bir grubun içinde normal kaydır. Canlı akışa "Ekran dökümü alındı" satırı düşer.

Seçenekler: Facebook toplama (açık), uzun gönderileri kendiliğinden açma (**açık**, v1.2: kısaltılmış gönderinin "diğer"
düğmesine kullanıcı değil uygulama dokunur, gönderi yerinde açılır, bir sonraki okumada tam metin gelir; aynı düğmeye 6 sn içinde
ikinci kez dokunulmaz; Facebook tam metni ekrana basmadığı için başka yolu yoktur), WhatsApp iletme (**kapalı**: Osman'ın
kararıyla WhatsApp şimdilik MacroDroid'de kalır; MacroDroid kapatılırsa buradan açılır, ikisi birden açık olmaz), yalnız grup
sohbetleri (açık; kişisel sohbetler hiç gitmez).

## Hızlı kaydırma ve tam metin (v1.2, versionCode 3)

- Okuma sıklığı: kaydırma sürerken en az 350 ms'de bir, kaydırma durunca 700 ms sonra bir kez daha. Aynı ekran iki kez
  gönderilmez; üst üste binen ekranlardaki aynı gönderi sunucuda tek sayılır (24 saat önbellek + metin tekrar denetimi).
- Çok hızlı fırlatılan (parmakla savrulan) akışta Facebook bazı gönderileri hiç çizmez; onlar alınamaz. Normal okuma hızında
  her gönderi alınır.
- Kesik / tam birleştirme (sunucu, `LoadIntakeService::mergeTruncatedFacebookPost`): aynı kaynağın son 7 gün kayıtlarıyla ön ek
  karşılaştırması (en az 40 karakter). Tam metin kuyruktaki kesik kaydın yerini alır (kesik arşive gider); kesik hâl sonradan
  gelirse tam kaydın tekrarı sayılır. Yayınlanmış ya da yönetici düzenlemiş kayıt yerinden oynatılmaz. Ayrıştırıcı kesik gönderiyi
  `truncated=true` ile işaretler.

## Sunucu tarafı

- Aynı uç: `POST /api/v1/webhook/notification`. WhatsApp mesajı JSON (`title` = "Grup: Gönderen", `text`, `app`, `posted_at`),
  Facebook dökümü düz metin (`X-Intake-Kind: screen`). Anahtar `X-Scraper-Token` başlığında; `X-Intake-App: toplayici/1.0`.
- Ekran dökümünde 24 saat içinde görülmüş gönderi (grup + metin özeti, önbellek) kuyruğa girmez; canlı akış satırı
  "N gönderi ayrıştırıldı, M yeni" der.
- Sürüm ucu: `GET /api/v1/toplayici/version` → `versionCode`, `versionName`, `url`. Uygulama açılışta bakar.
- Yeni sürüm çıkarmak: `AndroidManifest.xml` içinde `versionCode` +1 ve `versionName`, `Prefs.VERSION_CODE/VERSION_NAME` aynı
  değerler; `bash android/build.sh`; APK ve `version.json` commit edilir; birleştirip "Siteyi güncelle".
- İmza anahtarı `android/keystore/toplayici.jks` depoda durur (yan yükleme imzası; parola build.sh içinde). Değişirse
  telefonlardaki uygulama silinip yeniden kurulmalıdır.

## Derleme ortamı

Android SDK gerekmez. Ubuntu: `apt-get install -y aapt zipalign apksigner dalvik-exchange` ve JDK 17+. `android.jar` (API 34)
`android/.tools/` altına indirilir (build.sh indirir). Java 8 söz dizimi, lambda yok (dx lambda çevirmez).
