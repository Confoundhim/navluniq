# NavlunIQ: Yeni Sunucuya Taşınma Rehberi

Bu rehber siteyi mevcut sunucudan yeni bir sunucuya, veri kaybı olmadan ve 15-30 dakikalık kesintiyle taşımak
içindir. Eski sunucuya hiçbir adımda zarar verilmez; bir sorun olursa alan adı eski IP'ye geri çevrilir ve site
eski yerinde çalışmaya devam eder.

Taşınan şeyler: veritabanı (kullanıcılar, ilanlar, ayarlar, sözleşmeler), yüklenen belgeler (KYC, yedekler),
`.env` (gizli anahtarlar; **APP_KEY** şifreli telefon numaraları için şarttır), SSL sertifikası.
Telefondaki bildirim iletici (MacroDroid) aynı adrese göndermeye devam eder; orada değişiklik gerekmez.

## 0. Yeni sunucu seçimi

- En az 2 çekirdek, 4 GB bellek; tercihen 4 çekirdek, 8 GB. **NVMe/SSD disk şart** (2026-09'daki yavaşlığın sebebi yavaş diskti).
- İşletim sistemi: **Ubuntu 24.04** (temiz kurulum, üzerinde panel/yazılım olmasın).
- Yurt dışı sunucu (ör. Almanya) seçilirse KVKK metnine "yurt dışına aktarım" bilgilendirmesi eklenir; yurt içi
  sunucuda gerekmez. Kararı vermeden önce bu maddeyi düşünün.
- Alan adı paneline (DNS) erişiminiz olsun; A kaydını değiştireceksiniz.

Disk hızı testi (yeni sunucuda, root ile). Sonuç MB/s düzeyinde olmalı; kB/s ise sunucuyu almayın:

```bash
dd if=/dev/zero of=/root/disktest bs=4k count=500 oflag=dsync 2>&1 | tail -1; rm -f /root/disktest
```

## 0.5 Deneme kopyası: siteyi yeni sunucuda IP ile çalıştırıp denemek (kesinti yok)

Karar vermeden önce aynı sistemi yeni sunucuda kurup IP adresiyle açmak için. İki seçenek:

- **Birebir kopya** (`DOMAIN=YENI_IP`): e-posta, Telegram, ödeme ve zamanlanmış görevler canlıdaki ayarlarla aynen
  çalışır. Gerçek kullanıcı yokken uygundur; iki sunucu da aynı olaylar için e-posta/Telegram gönderebilir.
- **Yalıtılmış kopya** (`--deneme`): dışarıya hiçbir şey gönderilmez (e-posta kapalı, Telegram kapalı, ödeme
  sağlayıcısı boş); yöneticiler kendi e-posta ve şifreleriyle, doğrulama kodu `123456` ile girer. Gerçek kullanıcı
  varken tercih edilir.

Her iki seçenekte de canlı siteye dokunulmaz.

1. Eski sunucuda yedek: `bash /var/www/navluniq/deploy/backup.sh`
2. Yeni sunucuda yedeği çekin: `scp root@ESKI_IP:/var/backups/navluniq/navluniq-*.tar.gz /root/`
3. Betiği indirip deneme kipinde kurun (10-15 dk):

   ```bash
   curl -fsSL https://raw.githubusercontent.com/Confoundhim/navluniq/main/deploy/tasima.sh -o /root/tasima.sh
   DOMAIN=YENI_IP bash /root/tasima.sh /root/navluniq-*.tar.gz            # birebir kopya
   DOMAIN=YENI_IP bash /root/tasima.sh --deneme /root/navluniq-*.tar.gz   # ya da yalıtılmış kopya
   ```

   Telefondaki bildirim iletici canlı adrese gönderir; grup mesajlarını kopyada da görmek için MacroDroid'deki adresi
   geçici olarak `http://YENI_IP/api/v1/webhook/notification` yapın (aynı anahtar), deneme bitince geri alın.

4. Tarayıcıda `http://YENI_IP/` açın (https yok; IP ile normaldir). Yönetici paneli, ilan listesi, şoför ekranları,
   Sistem sağlığı ("Kuyruk işçisi: çalışıyor").
5. Beğenirseniz gerçek geçiş için aşağıdaki bölümler; aynı sunucu kullanılır, geçiş günü `FORCE_IMPORT=1` ve
   `LETSENCRYPT_EMAIL` ile alan adı kipinde yeniden çalıştırılır (deneme kipi ayarları o zaman kalkar).
   Vazgeçerseniz sunucuyu kapatmanız yeter.

## 1. Hazırlık (kesinti yok, geçiş gününden 1-2 gün önce)

1. **DNS bekleme süresini düşürün.** Alan adı panelinde `navluniq.com` ve `www` A kayıtlarının TTL değerini
   300 saniyeye (5 dk) çekin. Böylece geçiş günü değişiklik hızlı yayılır.
2. **Eski sunucuda deneme yedeği alın:**

   ```bash
   bash /var/www/navluniq/deploy/backup.sh
   ```

   Çıktıdaki dosya adını not edin (`/var/backups/navluniq/navluniq-YYYYmmdd-HHMMSS.tar.gz`).
3. **Yeni sunucuya bağlanın ve yedeği çekin** (eski sunucunun root şifresi sorulur):

   ```bash
   scp root@ESKI_IP:/var/backups/navluniq/navluniq-*.tar.gz /root/
   ```

4. **Taşıma betiğini alın ve yedeği kontrol edin** (hiçbir şey kurmaz):

   ```bash
   curl -fsSL https://raw.githubusercontent.com/Confoundhim/navluniq/main/deploy/tasima.sh -o /root/tasima.sh
   bash /root/tasima.sh --kontrol /root/navluniq-YYYYmmdd-HHMMSS.tar.gz
   ```

   Veritabanı adı, alan adı, belge ve SSL satırlarını görmelisiniz.
5. **Deneme kurulumu** (yeni sunucuda; eski site çalışmaya devam eder, 10-15 dk sürer):

   ```bash
   LETSENCRYPT_EMAIL=siz@ornek.com bash /root/tasima.sh /root/navluniq-YYYYmmdd-HHMMSS.tar.gz
   ```

   Betik MySQL, Redis, PHP 8.4, nginx, kuyruk işçisi ve "Siteyi güncelle" düğmesini kurar; veritabanını ve
   belgeleri yerine koyar. Sonunda kullanıcı ve ilan sayılarını yazar; eski panelde gördüğünüz sayılarla
   karşılaştırın.
6. **Alan adını değiştirmeden yeni sunucuyu deneyin.** Bilgisayarınızda Not Defteri'ni yönetici olarak açıp
   `C:\Windows\System32\drivers\etc\hosts` dosyasının sonuna şu satırı ekleyin (YENI_IP yerine yeni sunucunun IP'si):

   ```
   YENI_IP navluniq.com www.navluniq.com
   ```

   Tarayıcıda `https://navluniq.com` açın: giriş yapın, ilan listesine, yönetici paneline ve Sistem sağlığı
   ekranına bakın ("Kuyruk işçisi: çalışıyor", "Zamanlayıcı: çalışıyor"). Bittiğinde hosts satırını silin.
   Bu denemede yaptığınız değişiklikler (ilan ekleme vb.) geçiş günü silinir; yalnız bakmak için kullanın.

## 2. Geçiş günü (kesinti 15-30 dk; ilan trafiğinin az olduğu bir saat seçin)

1. **Eski sunucuyu bakım moduna alın** (yeni veri gelmesin; bu süre boyunca telefondan gelen grup mesajları işlenmez,
   geçiş bitince gelenler normal işlenir):

   ```bash
   cd /var/www/navluniq && php artisan down --retry=60
   ```

2. **Eski sunucuda son yedeği alın:**

   ```bash
   bash /var/www/navluniq/deploy/backup.sh
   ```

3. **Yeni sunucuda son yedeği çekin ve veritabanını yeniden yükleyin** (deneme kurulumundaki veriler silinir, yerine
   güncel veri gelir; kod ve ayarlar aynı kalır):

   ```bash
   scp root@ESKI_IP:/var/backups/navluniq/navluniq-*.tar.gz /root/
   FORCE_IMPORT=1 LETSENCRYPT_EMAIL=siz@ornek.com bash /root/tasima.sh /root/navluniq-EN-YENI.tar.gz
   ```

   (`EN-YENI` yerine en son dosya adı. Betik "döküm yüklendi: N tablo" ve sayıları yazar.)
4. **Alan adını yeni IP'ye çevirin.** DNS panelinde `navluniq.com` ve `www` A kayıtlarını YENI_IP yapın.
   5-10 dakika içinde yayılır. Kontrol (bilgisayarda, hosts satırı silinmiş olmalı):

   ```
   nslookup navluniq.com
   ```

5. **Yeni sunucuda kontrol:** `https://navluniq.com` açın; yönetici paneli → Sistem sağlığı tüm satırlar yeşil olmalı.
   Dış kaynak sayfasında "Kuyruk: çalışıyor" ve "Zamanlayıcı: çalışıyor" rozetleri görünmeli.
   Telefondan bir grup mesajının canlı akışa düştüğünü görün (iletici otomatik olarak yeni sunucuya ulaşır).
6. **Eski sunucuyu bakım modunda bırakın** (kapatmayın). 2-3 gün sorun çıkmazsa hosting panelinden silin.

## 3. Geri dönüş (yeni sunucuda beklenmedik sorun çıkarsa)

1. DNS A kayıtlarını ESKI_IP'ye geri çevirin.
2. Eski sunucuda bakım modunu kaldırın: `cd /var/www/navluniq && php artisan up`.
3. Site eski yerinde, eski veriyle çalışmaya devam eder (geçiş sırasında yeni sunucuya girilen veriler kaybolur;
   bu yüzden geçiş kısa tutulur ve trafiğin az olduğu saat seçilir).

## 4. Geçişten sonra

- Yeni sunucuda yedek dizini `/var/backups/navluniq` boştur; ilk gece `system:backup` görevi panel yedeğini alır.
  İsterseniz hemen: `bash /var/www/navluniq/deploy/backup.sh`.
- Hosting panelinde yeni sunucu için otomatik anlık görüntü (snapshot) varsa açın.
- SSL sertifikası eski sunucudan taşındığı için hemen çalışır; yenilemeyi certbot kendisi yapar (alan adı artık bu
  sunucuya döndüğü için yenileme sorunsuz geçer). Kontrol: `certbot renew --dry-run`.
- Eski sunucunun IP'si e-posta ayarlarında (SPF kaydı) geçiyorsa DNS'te yeni IP ile değiştirin.

## Sık karşılaşılan durumlar

- **"LETSENCRYPT_EMAIL verilmedi"**: komutun başına `LETSENCRYPT_EMAIL=siz@ornek.com` ekleyin.
- **"Veritabanında zaten N tablo var; döküm yeniden yüklenmedi"**: bilinçli koruma. Güncel yedeği yüklemek için
  `FORCE_IMPORT=1` ile çalıştırın (mevcut tablolar silinir).
- **Sertifika alınamadı**: alan adı henüz yeni sunucuya dönmemiştir. Yedekte sertifika varsa https zaten çalışır;
  yoksa DNS geçişinden sonra `bash /var/www/navluniq/deploy/install.sh` komutunu tekrar çalıştırın.
- **Kuyruk işçisi "nabız yok"**: `supervisorctl status` ile işçilerin RUNNING olduğuna bakın;
  `grep ^QUEUE_CONNECTION /var/www/navluniq/.env` ile `supervisorctl` komutundaki bağlantı aynı olmalı (`database`).
