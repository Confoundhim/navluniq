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

## Dış servisler

Ödeme (PayTR), SMS ve WhatsApp OTP (NetGSM), e-fatura (ERP), kimlik (NVİ) ve vergi (GİB) doğrulamaları `.env` içindeki anahtarlar boşken güvenli biçimde devre dışı kalır; hiçbir işlem sahte olarak "başarılı" işaretlenmez.

`whatsapp-scraper-daemon/` klasörü, WhatsApp gruplarındaki yük ilanlarını `/api/v1/webhook/whatsapp-scraper` ucuna ileten ayrı bir Node.js servisidir; kendi `README` ve ortam değişkenleriyle sunucuda ayrı çalışır.

## Git akışı

Geliştirme yerelde yapılır, `main` dalına push edilir. Sunucu deploy'unda:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize
```
