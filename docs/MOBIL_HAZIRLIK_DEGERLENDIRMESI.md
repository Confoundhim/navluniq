# NavlunIQ – Mobil (Google Play / App Store) Hazırlık Değerlendirmesi

Tarih: 2026-09-13
Kapsam: `main` dalındaki ilk commit (1734ea8) üzerinde yapılan kod incelemesi.

## 1. Doğrulanan durum

| Kontrol | Sonuç |
|---|---|
| `composer install` | Başarılı |
| `npm ci` + `npm run build` (Vite) | Başarılı (CSS 79 KB, JS 47 KB) |
| `php artisan test` | 5/5 geçti (testler yüzeysel, aşağıya bakın) |
| `php -l` (tüm PHP) | Sözdizimi hatası yok |
| `php artisan route:list` | 67 route |
| `php artisan migrate` (SQLite) | **Başarısız** – `spatialIndex` SQLite'ta desteklenmiyor |
| `vendor/bin/pint --test` | 60+ dosyada stil uyarısı (kozmetik) |

Teknoloji: Laravel 13, Livewire 4 + Volt (tek dosya bileşenler), Tailwind 3, Alpine, Leaflet. Ayrı bir API yok; her şey sunucu tarafında render ediliyor ve oturum çerezi ile çalışıyor. Bu, WebView tabanlı bir mobil uygulama için **uygun bir temel**.

## 2. Mobil açısından iyi olan şeyler

- Tüm layout'larda `viewport` meta var; CSS'te 320 px alt sınır, 16 px input (iOS otomatik zoom engeli), tablo yatay kaydırma, modal taşma koruması eklenmiş.
- Panel menüleri mobilde drawer olarak açılıyor.
- Dosya yüklemeleri standart `<input type="file">` ile yapılıyor; WebView içinde kamera/galeri seçici bununla açılabiliyor.
- Kimlik doğrulama çerez tabanlı; native kabuk (Capacitor) çerezleri paylaştığı için ek token altyapısı gerekmiyor.
- Sanctum kurulu ama kullanılmıyor; ileride native API gerekirse hazır.

## 3. Mağazaya çıkmadan önce kapatılması gereken eksikler

Öncelik sırasına göre.

### A. Mağaza politikası gereği zorunlu

1. **Hesap silme akışı yok.** Apple (5.1.1) ve Google Play, uygulama içinden hesap kapatma istiyor. Profil sayfalarına "Hesabımı sil" eklenmeli (soft delete + KVKK bildirimi).
2. **Şifremi unuttum çalışmıyor.** `login.blade.php:173` linki `href="#"`. Mağaza incelemesi bu akışı dener. Laravel'in `Password::sendResetLink` akışı eklenmeli.
3. **Sadece siteyi saran uygulama riski (Apple 4.2).** Uygulamayı ayırt eden en az iki native özellik olmalı: push bildirimi ve şoför konum takibi. İkisi de şu an yok (aşağıda).

### B. Uygulamanın çalışması için gerekli

4. **Şoför canlı konum toplama tarafı yok.** `driver_locations` tablosu ve modeli var, ama kodun hiçbir yerinde `navigator.geolocation` veya konum POST eden bir route yok. Yük sahibi haritasında gösterilecek veri üretilmiyor. Gerekli: `/panel/sofor/konum` POST route'u (oturum + CSRF), 10–30 sn'de bir konum gönderen JS, native tarafta arka plan konum eklentisi.
5. **Push bildirimi altyapısı yok.** `device_tokens` tablosu, FCM/APNs gönderimi ve Capacitor Push eklentisi gerekiyor.
6. **Dış bağlantılar WebView'da kırılır.** `target="_blank"`, `wa.me`, `tel:`, `t.me`, `mailto:` linkleri (frontend layout, driver/loads, scrapers) native kabukta sistem tarayıcısına / uygulamasına yönlendirilmeli. Tek noktadan çözüm: `app.js` içinde tıklama yakalayıp `window.Capacitor` varsa `Browser.open` / `App.openUrl` çağırmak.
7. **PayTR ödeme sayfası yer tutucu.** Gerçek entegrasyon yapılırken 3D Secure yönlendirmesi WebView içinde kalmalı; Capacitor `allowNavigation` listesine `*.paytr.com` eklenmeli. Fiziksel hizmet olduğu için Apple in-app purchase zorunluluğu yok.

### C. Kalite ve dayanıklılık

8. **PWA meta verileri yok.** `manifest.json`, `theme-color`, `apple-touch-icon`, splash. Android'de TWA yolu seçilirse zorunlu; Capacitor'da da ikon/splash için gerekli.
9. **Güvenli alan (çentik) desteği yok.** `viewport-fit=cover` ve `env(safe-area-inset-*)` kullanılmıyor. iPhone'da sabit header ve sağ alttaki WhatsApp butonu durum çubuğu / home göstergesi ile çakışır.
10. **CDN bağımlılıkları.** Leaflet `unpkg.com`'dan, Inter fontu Google Fonts'tan geliyor. Zayıf şebekede harita hiç yüklenmez. `npm i leaflet` ile paketlenmeli, font yerel dosyaya alınmalı.
11. **`wire:poll` sıklığı.** Admin sayfalarında 3 sn, anasayfada 10 sn. Mobilde pil ve veri tüketir; 30 sn+ veya Reverb (websocket) tercih edilmeli.
12. **Karanlık mod anahtarı tutarsız.** `frontend.blade.php` `localStorage['darkMode']`, `app.js` `localStorage['theme']` kullanıyor; iki store birbirini eziyor. Tek anahtara indirilmeli.
13. **Test veritabanı migrate edilemiyor.** `phpunit.xml` SQLite kullanıyor, ama `0001_01_01_000200` migration'ındaki `spatialIndex` SQLite'ta patlıyor. Sonuç: `RefreshDatabase` kullanan hiçbir test yazılamıyor. Çözüm: `if (DB::getDriverName() === 'mysql')` koşulu veya testlerde MySQL.
14. **Testler yüzeysel.** 5 test var, çoğu dosya içinde string arıyor. Kayıt, giriş, ilan oluşturma, teklif kabul gibi kritik akışlar için feature test yok.
15. **İş mantığı Blade içinde.** Volt bileşenleri 400–550 satır ve iş kuralları view dosyalarında. `App\Services` katmanı kısmen var (OfferAcceptance, Ledger). Mantık servislere taşınırsa ileride native API eklemek kopya kod gerektirmez.
16. **Küçük kalıntılar.** `welcome.blade.php` ve README Laravel varsayılanı; `redirectUsersTo` giriş yapmış kullanıcıyı `/panel` yerine `/` sayfasına atıyor.

### D. Hukuki / inceleme notu

17. **WhatsApp kazıyıcı** (`whatsapp-scraper-daemon`, Baileys) resmi olmayan istemci kullanıyor; WhatsApp kullanım şartlarıyla çelişir. Mağaza başvurusunda ve uygulama içi metinlerde bu veri kaynağı öne çıkarılmamalı.

## 4. Önerilen yol: Capacitor kabuğu

En basit ve mağaza tarafından kabul edilen yol:

- `mobile/` altında bir Capacitor projesi, `server.url` ile canlı siteye bağlanır. Web kodu tek yerde kalır, uygulama güncellemesi gerekmeden site güncellenir.
- Eklentiler: App (geri tuşu, deep link), Browser (dış linkler), Geolocation + arka plan konum, Push Notifications, StatusBar, SplashScreen.
- `app.js` içinde `window.Capacitor` kontrolüyle native köprü: varsa native eklenti, yoksa tarayıcı API'si.

Alternatif olan PWA + TWA yalnız Android'de işe yarar; iOS için yine bir kabuk gerekir. Bu yüzden Capacitor tek yol olarak öneriliyor.

## 5. Fazlar

| Faz | İçerik | Tahmini iş |
|---|---|---|
| 0 – Web sertleştirme | A1, A2, C8–C13 maddeleri | 1 hafta |
| 1 – Capacitor kabuk | Proje kurulumu, eklentiler, dış link köprüsü, ikon/splash | 3–4 gün |
| 2 – Native özellikler | Konum route + JS + arka plan; push token + gönderim | 1–2 hafta |
| 3 – Mağaza | Gizlilik politikası URL'si, Play Data Safety formu, Apple inceleme notu, ekran görüntüleri | 2–3 gün |

PayTR, e-fatura ve SMS entegrasyonları bu takvimin dışında ve sağlayıcı hesaplarına bağlı.
