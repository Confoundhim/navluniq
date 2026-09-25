<?php

use App\Jobs\QueueHeartbeat;
use App\Services\AccountService;
use App\Services\DriverTripService;
use App\Services\LoadReleaseService;
use App\Services\LocalClassifier;
use App\Services\OfferService;
use App\Services\ScrapedLoadService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

Artisan::command('loads:release-to-free', function (LoadReleaseService $release) {
    $this->info('Herkese açılan sistem ilanı sayısı: '.$release->releaseDue());
    $this->info('Telegram yeniden deneme: '.$release->retryTelegram());
})->purpose('Premium bekleme süresi dolan sistem ilanlarını herkese açar, ücretsiz şoförlere bildirir ve Telegram kanalına gönderir');

Artisan::command('subscriptions:remind', function (SubscriptionService $subscriptions) {
    $this->info('Premium bitiş hatırlatması gönderilen: '.$subscriptions->remindExpiring(3));
})->purpose('Bitişine 3 gün kalan premium üyelere hatırlatma gönderir');

Artisan::command('subscriptions:expire', function (SubscriptionService $subscriptions) {
    $this->info('Süresi dolan abonelik sayısı: '.$subscriptions->expireDue());
})->purpose('Dönemi biten premium abonelikleri kapatır');

Artisan::command('scraped-loads:ai-enrich', function (ScrapedLoadService $loads) {
    $this->info('Yapay zeka ile zenginleştirilen aday: '.$loads->aiEnrichPending());
})->purpose('Yapay zeka sırası bekleyen dış kaynak adaylarını çözümler (kota/ağ hatası sonrası yeniden deneme)');

Artisan::command('ai:learn {--rebuild : Sayaçları sıfırlayıp geçmiş kararlardan yeniden öğren}', function (LocalClassifier $classifier) {
    if ($this->option('rebuild')) {
        $r = $classifier->rebuild();
        $this->info("Yeniden öğrenildi: {$r['load']} ilan, {$r['other']} ilan-değil örneği.");
    }
    $s = $classifier->stats();
    $this->info("Yerel sınıflandırıcı: {$s['docs_load']} ilan / {$s['docs_other']} ilan-değil örneği, {$s['tokens']} sözcük; ".($s['ready'] ? 'karar veriyor' : 'henüz yeterli örnek yok'));
})->purpose('Yerel öğrenen sınıflandırıcının durumu / yeniden eğitimi');

Artisan::command('scraped-loads:purge-expired', function (ScrapedLoadService $loads) {
    $this->info('Saklama süresi dolan dış kaynak ilanı sayısı: '.$loads->purgeExpired());
    $this->info('Saklama süresi dolan reddedilmiş aday sayısı: '.$loads->purgeRejected());
    $this->info('Silinen eski canlı akış kaydı: '.$loads->purgeIntakeEvents());
})->purpose('Saklama süresi dolan dış kaynak ilanlarını havuzdan kaldırır; eski reddedilmiş adayları kalıcı siler');

Artisan::command('trips:scan-return-loads', function (DriverTripService $trips) {
    $r = $trips->scanReturnLoads();
    $this->info("Taranan sefer: {$r['trips']}, bildirim gönderilen: {$r['notified']}");
})->purpose('Açık seferlerin varış yeri çevresinden çıkan yeni ilanları (dönüş yükü) şoföre bildirir');

Artisan::command('trips:auto-close', function (DriverTripService $trips) {
    $this->info('Kapatılan sefer sayısı: '.$trips->autoClose());
})->purpose('Teslimden sonra süresi dolan seferleri kapatır');

Schedule::command('offers:expire')->hourly();
Schedule::command('trips:scan-return-loads')->everyTenMinutes()->withoutOverlapping();
Schedule::command('trips:auto-close')->dailyAt('04:10');
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('subscriptions:remind')->dailyAt('09:00');
Schedule::command('notifications:retry-mail')->everyTenMinutes()->withoutOverlapping();
Schedule::command('scraped-loads:purge-expired')->daily();
Schedule::command('scraped-loads:ai-enrich')->everyFiveMinutes()->withoutOverlapping();
// Zamanlayıcı nabzı: yönetici ekranı "zamanlayıcı çalışıyor mu" sorusunu buradan cevaplar.
Schedule::call(fn () => Cache::put('scheduler.heartbeat', now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
// Kuyruk nabzı: işçi bu işi çalıştırınca zaman damgası yazar; tazeyse telefon mesajları kuyruğa verilir (bkz. NotificationWebhookController).
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
Schedule::command('scraped-loads:auto-approve')->everyMinute()->withoutOverlapping();
Schedule::command('loads:release-to-free')->everyMinute()->withoutOverlapping();
Schedule::command('shipments:auto-approve')->hourly();
Schedule::command('accounts:purge-drafts')->daily();
Schedule::command('system:backup')->dailyAt('03:30')->withoutOverlapping();
