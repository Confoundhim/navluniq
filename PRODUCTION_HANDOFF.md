# NavlunIQ üretim adayı teslimi

## Bu pakette doğrulananlar
- 48 route hedefi mevcut.
- 43 Volt bileşeninin sınıf sınırları, kapanışları ve iç metot çağrıları statik olarak doğrulandı.
- Eksik model referansı yok; 53 model ve 9 migration mevcut.
- Bozuk Sistem Sağlığı bileşeni gerçek, salt-okunur sağlık kontrolleriyle yeniden kuruldu.
- Silinmiş `AiParserService::parseMessage()` güvenli regex ön elemesi, katı JSON doğrulaması, ücretsiz-mod sağlayıcı sınırı ve kota kaydıyla geri getirildi.
- Sahte finans/ödeme başarıları ve `generateMock*` mutasyonları bulunmuyor.
- `.env` ve çalışma zamanı oturum/log dosyaları teslim arşivine dahil edilmedi.

## Herd üzerinde tek doğrulama komutu
PowerShell:

```powershell
cd C:\Users\osman\Herd\navluniq
powershell -ExecutionPolicy Bypass -File .\scripts\validate-production.ps1
```

Script; Composer kurulumu, tüm PHP/Blade lint işlemleri, cache temizliği, migration durumu, route derleme, PHPUnit, `npm ci`, Vite build ve üretim bağımlılığı audit işlemlerini hata halinde durarak çalıştırır.

## Kurulum
1. Paket içindeki `navluniq` klasörünü proje kökü olarak kullanın.
2. Kendi `.env` dosyanızı yerleştirin; paket gizli bilgi içermez.
3. `composer install` ve `npm ci` çalıştırın.
4. `php artisan migrate --force` (canlıda) veya `php artisan migrate` (yerelde) çalıştırın.
5. `php artisan storage:link`, `php artisan optimize:clear`, `npm run build` çalıştırın.
6. Queue ve scheduler süreçlerini sunucu servisleriyle başlatın.

## Canlı kabul kapıları
PayTR, ERP/e-Fatura, sigorta, SMTP ve WhatsApp gerçek hesapları olmadan kod hiçbir işlemi başarılıymış gibi işaretlemez. Bu sağlayıcıların sandbox/canlı callback kabul testleri tamamlanmadan ödeme ve fatura modülü canlı kabul edilmemelidir.
