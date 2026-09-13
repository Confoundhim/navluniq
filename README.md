# NavlunIQ

Yük sahipleri ile şoförleri buluşturan lojistik pazaryeri. Laravel 13, Livewire 4 (Volt), Tailwind CSS 3.

## Gereksinimler

- PHP 8.3+ (Herd)
- MySQL 8 / MariaDB 10.11+
- Node.js 20+
- Composer

## Yerel kurulum

```bash
composer install
cp .env.example .env        # ardından DB_*, MAIL_* ve ADMIN_INIT_* değerlerini doldurun
php artisan key:generate
php artisan migrate
php artisan db:seed         # roller, ilk yönetici, SSS ve sözleşme metinleri
php artisan storage:link
npm install
npm run build               # geliştirme sırasında: npm run dev
```

İlk yönetici yalnızca `.env` içindeki `ADMIN_INIT_EMAIL`, `ADMIN_INIT_PASSWORD` (en az 12 karakter) ve `ADMIN_INIT_PHONE` doluysa oluşturulur. Yönetim paneli: `/adminsystem`.

## Doğrulama

```bash
composer check   # pint, phpunit ve vite build
```

## İş akışı

1. Yük sahibi ilan yayınlar (`LoadService`), ilan şoför havuzunda görünür.
2. Belgeleri onaylı şoför teklif verir (`OfferService`); yük sahibi kabul eder, sevkiyat kaydı açılır.
3. Yük sahibi navlun bedelini PayTR ile havuza yatırır; ödeme yalnız imzalı sunucu bildirimiyle "ödendi" sayılır (`PaymentService`).
4. Şoför yola çıkar, canlı konum paylaşır, teslimat kanıtı yükler (`ShipmentService`).
5. Yük sahibi teslimatı onaylar veya süre dolunca otomatik onaylanır; hakediş sıraya girer (`PayoutService`), finans ekibi banka transferini yapıp "ödendi" işaretler.
6. Uyuşmazlık açılırsa havuz askıya alınır, hakem kararıyla ödeme ya da iade yapılır (`DisputeService`).

Her adımdaki durumlar `App\Models\Load` sabitlerinde tanımlıdır.

## Dış servisler

PayTR, NetGSM, NVİ ve yapay zekâ ayrıştırma anahtarları `.env` içinde boşken ilgili özellik güvenli biçimde devre dışı kalır; hiçbir işlem sahte olarak "başarılı" işaretlenmez. PayTR mağaza panelinde bildirim adresi olarak `https://alanadiniz.com/odeme/paytr/bildirim` tanımlanmalıdır.

`whatsapp-scraper-daemon/` klasörü, izinli WhatsApp gruplarındaki yük ilanlarını webhook ucuna ileten ayrı bir Node.js servisidir; kurulumu kendi README dosyasında anlatılır.

## Sunucuya kurulum

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan storage:link
php artisan optimize
```

Zamanlanmış görevler için crontab'a tek satır eklenir (teklif süresi, otomatik teslimat onayı, taslak hesap temizliği):

```
* * * * * cd /var/www/navluniq && php artisan schedule:run >> /dev/null 2>&1
```

Kuyruk kullanılmaz; e-postalar eşzamanlı gönderilir. Uygulama bir yük dengeleyici veya CDN arkasındaysa `bootstrap/app.php` içinde `trustProxies` tanımlanmalıdır; aksi halde güvenlik duvarı ve hız sınırlayıcı proxy IP'sini görür.

## Git akışı

Geliştirme yerelde yapılır, `main` dalına push edilir; sunucuda `git pull` ile alınır.
