# Yayın öncesi denetim (2026-10-03)

Osman'ın isteği: "Sitemizin son durumu, gözümüzden kaçanlar, mantık hataları, sistem ilanı süreci baştan sona, ödeme /
Netgsm / Rota Bulut altyapısı hazır mı?" Üç ayrı inceleme yapıldı (sistem ilanı akışı; ödeme ve entegrasyonlar; hesaplar,
premium, bildirim, işletim, KVKK). Kritik bulgular koddan tek tek doğrulandı. Bu belge yapılacaklar listesidir; her madde
kapanınca buradan işaretlenir.

## 1. Kısa cevaplar

- **Sistem ilanı süreci kusursuz mu?** Hayır. Mutlu yol (ilan → teklif → kabul → ödeme → yola çık → teslim → onay → ödeme
  çıkışı) çalışıyor ve 50 testle korunuyor. Ama olağan dışı yollarda **para kaybettiren ya da kullanıcıyı çıkmaza sokan 8 hata**
  var (bkz. §2). Yayından önce en az 1-5 kapanmalı.
- **Ödeme altyapısı:** iyzico adaptörü tam yazılmış, panelden anahtar girilir; **gerçek bir sandbox ödemesi hiç denenmemiş**.
  PayTR adaptörü var ama anahtarları yalnız sunucu dosyasından okuyor (panel kuralına aykırı). Canlıya geçiş = anahtar + sandbox
  kutusunu kapatmak + bir deneme ödemesi.
- **Netgsm (SMS):** servis sınıfı yazılmış, **hiçbir yerden çağrılmıyor**. Giriş kodu yalnız e-posta ile gidiyor. Yayın için şart
  değil; ama e-posta sunucusu düşerse kimse giriş yapamaz (yönetici dahil).
- **Rota Bulut / e-fatura / GİB:** **yok.** Kodda e-fatura/e-arşiv sağlayıcısı hiç yok; faturalar "bekliyor" durumunda numarasız
  duruyor; GİB servisi yalnız vergi numarası sağlama kontrolü. Yayında faturalar elle (muhasebeci) kesilmek zorunda.
- **NVİ (TC doğrulama):** kamuya açık ücretsiz servis gerçek çalışıyor (bireysel yük sahibi kaydında). Şoförde TC alanı hiç yok.
- **Sigorta, kupon, uygulama içi mesajlaşma:** tablo/model var, işlev yok. Pazarlamada söz verilmemeli.

## 2. Para kaybettiren / çıkmaza sokan hatalar (yayın engelleyici)

**Durum (2026-10-04): P1-P8'in tümü Paket A ile kapatıldı; ayrıntı §7.**

| # | Bulgu | Nerede | Ne olur |
|---|---|---|---|
| P1 | **İptal ile ödeme bildirimi yarışı.** Yük sahibi ödeme sayfasında 3D ile öder; sağlayıcının bildirimi gelmeden "İptal et"e basarsa ilan iptal olur, para gelince ilan `iptal + havuzda ödendi` kalır. İade eden kod yok. | `LoadService::cancel` açık ödeme emrine bakmıyor; `PaymentService::handleWebhook` ilan durumuna bakmıyor | Yük sahibinden para çekilir, kimse iade edemez |
| P2 | **Tutar uyuşmazlığı yine "ödendi" sayılıyor.** Sağlayıcı daha az tutar bildirse günlüğe yazılıp ödeme tam kabul ediliyor. | `PaymentService.php:220` | Eksik tahsilatla şoföre tam ödeme |
| P3 | **Ödenmiş ilanda şoför gelmezse çıkmaz.** Ödeme yapıldıktan sonra yük sahibi iptal edemez, uyuşmazlık açamaz (yola çıkmamış), şoför vazgeçemez, yönetici de iptal+iade yapamaz. | `LoadService::cancel`, `DisputeService::open`, operasyon ekranı | Destek elle veritabanı düzeltmek zorunda |
| P4 | **İade reddedilirse sessizce kaybolur.** Hakem "yük sahibine iade" derse ilan "iade edildi" yazar, sağlayıcı iadeyi reddederse yalnız günlük satırı; yöneticiye bildirim yok, tamamlama düğmesi yok. | `DisputeService.php:128`, `PaymentService.php:325`, finans ekranı | Müşteri "iade edildi" görür, para gitmez |
| P5 | **Belge yüklendi bildirimleri hiç gitmiyor.** `return DB::transaction(...)` satırından sonra kalan "Belgeleriniz alındı" ve yöneticiye "Yeni belge incelemesi bekliyor" kodu ulaşılamaz. | `KycService::upload` | Şoför kaydı sessizce bekler, kimse görmez |
| P6 | **Uyuşmazlık kararı bildiriminin bağlantısı bozuk.** `notify()`'a tür yerine adres parametresi olarak `'dispute'` geçiliyor; zil ve e-posta düğmesi 404'e gider. | `DisputeService.php:145` | Her karar bildirimi kırık |
| P7 | **Kabul edilip ödenmeyen ilan için süre yok.** Hatırlatma, otomatik iptal, şoför için "vazgeç" yok. | `OfferService`, zamanlayıcı | Şoförde sonsuz "ödeme bekleniyor" işleri birikir |
| P8 | **İlanlar süresi geçince kapanmıyor.** Yükleme tarihi geçmiş ilan havuzda kalır, teklif alınabilir, Telegram'da durur. | havuz sorgusu, zamanlayıcı | Şoför geçmiş tarihli işe teklif verir |

## 3. Diğer mantık hataları

- Sıfır gecikme ayarında premium şoförler hiç bildirim almıyor (`LoadReleaseService.php:62`); 3 günden eski ilan hiç "herkese
  açılmıyor" (zamanlayıcı dursa kaçar).
- Yük sahibi hizmet bedeli defterde "havuz borcu" olarak takılı kalıyor, gelire geçmiyor, fatura kesilmiyor (`commission_cargo_owner` > 0 ise).
- "Tekrar yayınla" kasa tipi, yük biçimi ve teslim noktalarını kopyalamıyor; fiyat olarak kabul edilen teklifi alıyor.
- Yük sahibine iade ile biten uyuşmazlık "Tamamlandı" görünüyor; istatistikte teslimat sayılıyor, karşılıklı puan verilebiliyor.
- Yolda uyuşmazlık açılınca şoför teslim kanıtı yükleyemiyor.
- Sağlayıcı değiştirilirken açık emir varsa iade/ödeme yanlış sağlayıcıya gider; iade olay kimliği saniye bazlı, aynı saniyede iki iade çakışır.
- iyzico pazaryeri alt üye kaydı sahte TC ile yapılıyor (şoförde TC yok) → canlıda reddedilir, ödemeler sessizce elle yola düşer.
- Sağlayıcıya HTTP çağrıları veritabanı kilidi içinde yapılıyor (yavaş sağlayıcı = kilit bekleyen istekler).
- İlan yayınlanınca tüm premium şoförlere e-posta **istek içinde** gidiyor (kuyruk yok); şoför sayısı artınca zaman aşımı.
- Premium hatırlatma/sona erdi bildirimleri abonelik satırı başına; hediye + satın alma birleşince yanlış "sona erdi" mesajı.
- E-posta/telefon değişikliği doğrulamasız; telefon hiç doğrulanmıyor (giriş kimliği olmasına rağmen); kayıtta istek sınırı ve bot
  koruması yok (OTP e-posta bombalama, başkasının numarasıyla kayıt).
- Güncelleme betiği yarıda hata alırsa siteyi yarım kodla açıyor (`trap ... php artisan up` her çıkışta).
- Hesap silme (KVKK) belgeleri, konum izini, TC/vergi no'yu bırakıyor; sözleşme metni değişince yeniden onay alınmıyor.
- Canlı akış kaydında gönderen adı (bildirim başlığı) 30 gün saklanıyor; KVKK metni "saklanmaz" diyor. Ham mesajda numara düz, şifreli
  alan gösterişte kalıyor.
- OTP kodu e-posta konusunda; şifre değişince diğer oturumlar kapanmıyor.
- Özel 404/419/500/503 sayfası yok (güncellemede herkes İngilizce Laravel sayfası görür); meta description / OG / sitemap yok;
  robots.txt her şeyi tarattırıyor (/adminsystem dahil); günlük dosyası sınırsız büyüyor; yedek iki kez aynı hasta diske alınıyor,
  dışarıda kopya yok; panel yedeği `.env` ve tüm kimlik belgelerini içeriyor ve her "ayar yönetme" yetkilisi indirebiliyor.
- Yönetici "panel değiştir" ile 10 yıl premium + onaylı KYC + sahte araç alıyor; istatistik ve bildirimlerde gerçek şoför sayılıyor.
- Premium sayfasında "20 dakika" sabit yazıyor; ayar değişirse yalan söyler.

## 4. Entegrasyon durumu

| Entegrasyon | Durum | Canlı için gereken |
|---|---|---|
| iyzico (ödeme) | Kod tam, **gerçek denenmemiş** | Panel: canlı anahtar + gizli anahtar, "Test (sandbox) modu" kapat, iyzico panelinde webhook `/odeme/bildirim/iyzico`; önce bir sandbox ödemesi |
| iyzico pazaryeri (otomatik ödeme çıkışı) | Sözleşme + şoför TC'si gerekli | Sözleşme yoksa finans ekranından elle havale (IBAN göster → havale → "Ödendi") |
| PayTR | Kod tam, yalnız `.env` | Kullanılmayacaksa dokunma |
| Netgsm SMS | Servis var, **kullanılmıyor** | Yayın için şart değil; giriş yedeği olarak bağlanabilir |
| E-fatura / Rota Bulut / GİB | **Yok** | Muhasebeci her `invoices` satırı için elle e-arşiv keser; sağlayıcı entegrasyonu ayrı iş |
| NVİ TC doğrulama | Çalışıyor (ücretsiz kamu servisi) | — |
| KYC belge inceleme | Çalışıyor (elle) | İnceleyici kişi; P5 düzeltmesi |
| E-posta (SMTP) | Panelden ayarlanır, **tek hata noktası** | SMTP bilgileri, deneme e-postası, SPF/DKIM/DMARC |
| Telegram | Çalışıyor | Bot token + kanal kimliği |
| Google | Gerekmez | Gemini anahtarı isteğe bağlı |
| Sigorta / kupon / mesajlaşma | Yer tutucu | Söz verilmez |

## 5. Osman'ın yapacakları (anahtar ve ayarlar)

1. Panel → Ödeme: iyzico canlı anahtarları, sandbox kapalı; iyzico panelinde webhook adresi.
2. Panel → Şirket künyesi: unvan, vergi dairesi/no, MERSİS, adres, telefon, e-posta, ETBİS, KDV oranı.
3. Panel → Komisyon: şoför komisyonu, yük sahibi hizmet bedeli, premium aylık fiyat, en düşük ilan fiyatı.
4. Panel → Yasal metinler: "güncel şablonla yenile"; hazırlık listesi tamamen yeşil.
5. Panel → E-posta: SMTP + deneme; alan adında SPF/DKIM/DMARC.
6. Panel → Telegram: bot + kanal.
7. `review_login_*` ayarları yalnız mağaza/iyzico incelemesi sırasında dolu, sonra **boş**.
8. Sunucu: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `LOG_STACK=daily` (bir kez), yedeğin dışarı kopyası.
9. Muhasebe süreci: faturalar elle; şoför havaleleri finans ekranından.

## 6. Önerilen sıra

- **Paket A (yayın engelleyiciler, kod):** P1-P8 + uyuşmazlık bildirim bağlantısı + yönetici "iptal + iade" işlemi + ödeme
  süresi/hatırlatma + ilan süresi dolma + güncelleme betiği geri alma + özel hata sayfaları + robots/sitemap/meta.
- **Paket B (güven ve KVKK):** e-posta/telefon değişiminde doğrulama, kayıtta istek sınırı ve bot koruması, hesap silmede belge/konum
  temizliği, sözleşme sürümü ve yeniden onay, gönderen adı saklamama, OTP konu satırı, oturum kapatma, günlük/yedek düzeni.
- **Paket C (ölçek ve muhasebe):** e-postaların kuyruğa alınması, sağlayıcı çağrılarının kilit dışına çıkması, defterde hizmet bedeli,
  premium bildirim düzeltmesi, iyzico sandbox ucu testi, Netgsm'in giriş yedeği olarak bağlanması, e-fatura sağlayıcı seçimi.

Testler: denetim günü 505 test yalnız mutlu yolu koruyordu; Paket A sonrası 520 test, para güvenliği ve akış çıkmazları kendi
testleriyle korunur (`tests/Feature/Payments/MoneySafetyTest`, `tests/Feature/Loads/PaymentDeadlineAndExpiryTest`,
`tests/Feature/Launch/LaunchPagesTest`).

## 7. Paket A tamamlandı (2026-10-04)

Üç parça halinde birleştirildi; hepsi yönetici paneli ayarlarıyla çalışır, `.env` gerekmez.

**Para güvenliği (P1, P2, P3, P4, P5, P6):**
- İptal ile ödeme bildirimi yarışı: son 15 dakikada güncellenmiş bekleyen ödeme emri varsa ilan iptal edilemez ("ödeme sürüyor");
  daha eski açık emirler iptalle birlikte kapanır. İptal edilmiş ilana yine de para gelirse (sahipsiz ödeme) defter kaydı tutulur,
  **kendiliğinden iade** edilir ve yük sahibine bildirilir.
- Tutar uyuşmazlığı: sağlayıcı farklı tutar bildirirse emir "başarısız" olur, neden `failure_message`'a yazılır, yöneticiye ve yük
  sahibine bildirim gider; ödeme kabul edilmez.
- Yönetici "İptal et ve iade et" (operasyon merkezi, atanmış + ödenmiş ilan): ilan iptal, sefer kapanır, iade sağlayıcıya gider,
  iki tarafa bildirim.
- İade reddedilirse emir `refund_pending` olur, yöneticiye "elle yapılmalı" bildirimi gider; finans ekranında "İade yapıldı" düğmesi
  (banka referansı ile) iadeyi tamamlar, ilan havuzu "iade edildi" olur, yük sahibine bildirilir. İade olay kimliği artık benzersiz.
- Uyuşmazlık kararı: durumlar kilit altında, sağlayıcı çağrıları (hakediş/iade) kilit dışında; "iade edildi" yalnız iade gerçekten
  başarılıysa yazılır; bildirim bağlantıları ilgili tarafın uyuşmazlık sayfasına gider.
- Belge yüklendi bildirimleri (şoföre ve yöneticiye) artık gidiyor.
- Yolda uyuşmazlık açılsa da şoför teslim kanıtı yükleyebilir (uyuşmazlık açık kalır, otomatik onay işlemez). Hakediş yalnız
  "serbest bırakma onaylandı" havuz durumunda açılır.

**Akış çıkmazları (P7, P8):**
- Teklif kabulünde ödeme süresi başlar (`offer_payment_hours`, varsayılan 24 sa); yarısında yük sahibine hatırlatma, süre dolunca ilan
  havuza döner, şoföre ve yük sahibine bildirim (`loads:expire-unpaid`, 30 dk'da bir). Yük sahibi listesinde "Ödeme için son" görünür.
- Şoför ödeme gelmeden "Vazgeç" diyebilir (İşlerim kartı); ilan havuza döner.
- Yükleme tarihi geçen ilanlar kapanır (`loads:expire`, saatlik, `load_expiry_grace_days` varsayılan 1 gün); bekleyen teklifler
  biter; havuz sorgusu geçmiş tarihli ilanı göstermez; geçmiş tarihe teklif verilemez/kabul edilemez.
- Sıfır gecikmede premium bildirimi de gider; "herkese aç" penceresi 14 gün.
- İade ile kapanan ilan "İade ile kapandı" yazar; istatistikte teslimat sayılmaz, puan verilemez.
- "Tekrar yayınla" kasa tipini ve yük biçimini kopyalar.

**İşletim:**
- Güncelleme betiği yarıda hata alırsa önceki commit'e geri döner (`git reset --hard`, composer, önbellek temizliği), sonra bakım
  modundan çıkar; günlükte hangi adımda kaldığı yazar.
- Türkçe 404/403/419/429/500/503 sayfaları (veritabanısız, telefona uygun, koyu tema; 503 15 sn'de, 429 60 sn'de kendini yeniler).
- `robots.txt` panel/yönetici/API/giriş/ödeme adreslerini kapatır; `/sitemap.xml` yalnız tanıtım ve sözleşme sayfalarını listeler;
  tanıtım sayfalarında meta description, canonical ve OG etiketleri; giriş/kayıt/şifre sayfaları ile panel ve yönetici düzeni `noindex`.

**Paket A'da bilerek yapılmayanlar:** §3'teki KVKK/güven maddeleri (Paket B) ve ölçek/muhasebe maddeleri (Paket C) duruyor;
iyzico alt üye TC sorunu ve e-posta kuyruğu Paket C'de.
