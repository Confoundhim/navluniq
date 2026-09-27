# Canlı Yedeği Kendi Bilgisayarınızda Çalıştırma (Windows)

Canlı sitenin tam yedeğini (`deploy/backup.sh` çıktısı) kendi bilgisayarınızda, tarayıcıdan `http://localhost:8080`
adresiyle çalıştırmak için. Kopya gerçek verilerle çalışır ama dışarıya hiçbir şey göndermez: e-posta kapalı,
Telegram kapalı, ödeme sağlayıcısı boş. Yöneticiler kendi e-posta ve şifreleriyle, doğrulama kodu `123456` ile girer.
Canlı siteye hiçbir etkisi yoktur.

## Bir kez yapılacak kurulum

1. **Docker Desktop** kurun: https://www.docker.com/products/docker-desktop/ (Windows'ta WSL 2 ister; kurulum
   sihirbazı kendisi açar, bilgisayar bir kez yeniden başlar). Kurulunca Docker Desktop'ı açın ve açık bırakın.
2. **Git** kurun: https://git-scm.com/download/win (varsayılan seçeneklerle).
3. PowerShell'de projeyi indirin:

   ```powershell
   cd $HOME
   git clone https://github.com/Confoundhim/navluniq.git
   cd navluniq
   mkdir yedek
   ```

4. İmajı derleyin (ilk seferde 5-10 dakika, internetten indirir):

   ```powershell
   docker compose build
   ```

## Yedeği indirip yükleme (her seferinde)

1. Sunucuda yedek alın (SSH ile): `bash /var/www/navluniq/deploy/backup.sh` → dosya adını not edin.
2. Yedeği bilgisayara, projedeki `yedek` klasörüne indirin (sonundaki `yedek\` hedefi şart; root şifresi sorulur):

   ```powershell
   cd $HOME\navluniq
   scp root@185.22.187.140:/var/backups/navluniq/navluniq-20260927-130337.tar.gz yedek\
   ```

   (Dosya adını kendi yedeğinizinkiyle değiştirin. En yenisini almak için adı yıldızla da yazabilirsiniz:
   `.../navluniq-*.tar.gz yedek\`)
3. Veritabanı ve Redis kaplarını başlatıp yedeği yükleyin (ilk seferde 5 dakika kadar):

   ```powershell
   docker compose up -d mysql redis
   docker compose run --rm app bash deploy/docker/yerel-geri-yukle.sh /yedek/navluniq-20260927-130337.tar.gz
   ```

   Sonunda "Yerel kopya hazır" özeti çıkar.
4. Siteyi başlatın ve tarayıcıda açın:

   ```powershell
   docker compose up -d
   ```

   http://localhost:8080 · Yönetim: http://localhost:8080/adminsystem (e-posta + şifre, kod `123456`).

## Günlük kullanım

- Durdurmak: `docker compose down` · Yeniden başlatmak: `docker compose up -d`
- Kodu güncellemek (GitHub'daki son sürüm): `git pull` sonra
  `docker compose run --rm app sh -c "composer install --no-interaction && npm ci && npm run build && php artisan migrate --force"`
- Yeni bir yedek yüklemek: 2. ve 3. adımı tekrarlayın (veritabanı yedekten yeniden yüklenir, eski yerel veri silinir).
- Kayıtlar: `docker compose logs -f app` · Veritabanına dışarıdan bağlanmak (ör. HeidiSQL): `localhost`, kapı `3307`,
  kullanıcı `navluniq`, şifre `navluniq`.

## Sık karşılaşılan durumlar

- **"docker: command not found"**: Docker Desktop kurulmamış ya da açık değil.
- **"port is already allocated"**: 8080 ya da 3307 kapısı başka bir programda. `docker-compose.yml` içinde `"8080:80"`
  satırını `"8081:80"` yapın ve adresi `http://localhost:8081` olarak kullanın (`YEREL_PORT=8081` ile yükleme betiğine de verin).
- **Yedek bulunamadı**: dosya `yedek` klasöründe olmalı; komutta yol `/yedek/dosyaadi.tar.gz` şeklinde yazılır.
- **Sayfa "500" veriyor**: `docker compose logs app` ve `storage/logs/laravel.log` son satırlarına bakın; çoğu zaman
  `docker compose run --rm app php artisan optimize:clear` çözer.
- Telefondaki bildirim iletici canlı adrese gönderir; yerel kopyaya grup mesajı düşmez (istenirse MacroDroid adresi
  geçici olarak bilgisayarın adresine çevrilir, aynı ağda olmak gerekir).
