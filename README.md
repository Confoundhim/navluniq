# NavlunIQ

Yük sahipleri ile şoförleri buluşturan lojistik pazaryeri. Laravel 13, Livewire 4 (Volt), Tailwind CSS 3.

## Gereksinimler

- PHP 8.4+ (Herd)
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

Dış kaynak ilanları için önerilen kanal Android bildirim ileticisidir; kurulum `docs/BILDIRIM_ILETICI_KURULUM.md` dosyasında anlatılır. `whatsapp-scraper-daemon/` klasörü, izinli WhatsApp gruplarındaki yük ilanlarını webhook ucuna ileten ayrı bir Node.js servisidir; kurulumu kendi README dosyasında anlatılır.

## Sunucuya kurulum

`deploy/` klasöründe iki betik vardır; ikisi de sunucuda root ile çalıştırılır.

**İlk kurulum** (`deploy/install.sh`): nginx, PHP 8.4, Composer ve Node.js'i kurar, depoyu
`/var/www/navluniq` altına klonlar, `.env` dosyasını üretir, bağımlılıkları ve ön yüzü derler,
migration ve ilk seed'leri çalıştırır, nginx sitesini ve `schedule:run` cron'unu tanımlar.
Alan adı ve `LETSENCRYPT_EMAIL` verilirse ücretsiz SSL de alır.

```bash
curl -fsSL https://raw.githubusercontent.com/Confoundhim/navluniq/main/deploy/install.sh -o /root/install.sh
DB_DATABASE=navluniq_live DB_USERNAME=navluniq_user DB_PASSWORD='...' \
ADMIN_INIT_EMAIL=admin@site.com ADMIN_INIT_PASSWORD='...' ADMIN_INIT_PHONE=05xxxxxxxxx \
DOMAIN=navluniq.com LETSENCRYPT_EMAIL=info@site.com \
bash /root/install.sh
```

**Güncelleme** (`deploy/update.sh`): bakım moduna alır, `main` dalını çeker, bağımlılıkları ve
derlemeyi yeniler, migration'ları uygular, önbellekleri tazeler.

```bash
bash /var/www/navluniq/deploy/update.sh
```

Kurulumdan sonra `.env` içinde `MAIL_*` (OTP e-postaları için şart) ve `COMPANY_*` alanları
doldurulup `php artisan config:cache` çalıştırılır. PayTR, NetGSM ve yapay zeka anahtarları
hazır olduğunda aynı dosyaya eklenir.

Kuyruk kullanılmaz; e-postalar eşzamanlı gönderilir. Uygulama bir yük dengeleyici veya CDN arkasındaysa `bootstrap/app.php` içinde `trustProxies` tanımlanmalıdır; aksi halde güvenlik duvarı ve hız sınırlayıcı proxy IP'sini görür.

## Git akışı

Geliştirme yerelde yapılır, `main` dalına push edilir; sunucuda `git pull` ile alınır.
