<?php

use App\Services\AccountService;
use App\Services\OfferService;
use App\Services\ScrapedLoadService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use App\Services\TelegramPublisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('offers:expire', function (OfferService $offers) {
    $this->info('Süresi dolan teklif sayısı: '.$offers->expireStale());
})->purpose('Süresi geçmiş bekleyen teklifleri kapatır');

Artisan::command('shipments:auto-approve', function (ShipmentService $shipments) {
    $this->info('Otomatik onaylanan teslimat sayısı: '.$shipments->autoApproveDue());
})->purpose('Onay süresi dolan teslimatları otomatik onaylar');

Artisan::command('accounts:purge-drafts', function (AccountService $accounts) {
    $this->info('Silinen taslak hesap sayısı: '.$accounts->purgeUnverifiedDrafts());
})->purpose('E-posta doğrulaması yapılmamış 24 saatten eski kayıtları siler');

Artisan::command('scraped-loads:auto-approve', function (ScrapedLoadService $loads) {
    $this->info('Otomatik onaylanan dış kaynak ilanı sayısı: '.$loads->autoApproveDue());
})->purpose('Ayar açıksa kriterleri sağlayan dış kaynak ilan adaylarını yayınlar');

Artisan::command('scraped-loads:publish-telegram', function (TelegramPublisher $telegram) {
    $this->info('Telegram kanalına gönderilen ilan sayısı: '.$telegram->publishDue());
})->purpose('Ücretsiz üyelere açılan dış kaynak ilanlarını Telegram kanalına gönderir');

Artisan::command('subscriptions:remind', function (SubscriptionService $subscriptions) {
    $this->info('Premium bitiş hatırlatması gönderilen: '.$subscriptions->remindExpiring(3));
})->purpose('Bitişine 3 gün kalan premium üyelere hatırlatma gönderir');

Artisan::command('subscriptions:expire', function (SubscriptionService $subscriptions) {
    $this->info('Süresi dolan abonelik sayısı: '.$subscriptions->expireDue());
})->purpose('Dönemi biten premium abonelikleri kapatır');

Artisan::command('scraped-loads:purge-expired', function (ScrapedLoadService $loads) {
    $this->info('Saklama süresi dolan dış kaynak ilanı sayısı: '.$loads->purgeExpired());
})->purpose('Saklama süresi dolan dış kaynak ilanlarını havuzdan kaldırır ve arşivler');

Schedule::command('offers:expire')->hourly();
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('subscriptions:remind')->dailyAt('09:00');
Schedule::command('notifications:retry-mail')->everyTenMinutes()->withoutOverlapping();
Schedule::command('scraped-loads:purge-expired')->daily();
Schedule::command('scraped-loads:auto-approve')->everyMinute()->withoutOverlapping();
Schedule::command('scraped-loads:publish-telegram')->everyMinute()->withoutOverlapping();
Schedule::command('shipments:auto-approve')->hourly();
Schedule::command('accounts:purge-drafts')->daily();
