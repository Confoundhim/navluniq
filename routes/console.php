<?php

use App\Jobs\QueueHeartbeat;
use App\Models\ScrapedLoad;
use App\Services\AccountService;
use App\Services\DriverLocationService;
use App\Services\DriverTripService;
use App\Services\LoadReleaseService;
use App\Services\LoadService;
use App\Services\LoadStandardizer;
use App\Services\LocalClassifier;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\RuleFeedbackService;
use App\Services\ScrapedLoadService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use App\Support\IntakeBenchmark;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('offers:expire', function (OfferService $offers) {
    $this->info('Süresi dolan teklif sayısı: '.$offers->expireStale());
})->purpose('Süresi geçmiş bekleyen teklifleri kapatır');

Artisan::command('loads:expire', function (LoadService $loads) {
    $this->info('Yükleme tarihi geçtiği için kapanan ilan: '.$loads->expireStale());
})->purpose('Yükleme tarihi geçmiş, teklif bekleyen ilanları kapatır');

Artisan::command('loads:expire-unpaid', function (OfferService $offers) {
    $r = $offers->expireUnpaid();
    $this->info("Ödeme süresi dolup havuza dönen ilan: {$r['released']} · hatırlatma: {$r['reminded']}");
})->purpose('Teklif kabulünden sonra ödenmeyen ilanları yeniden havuza alır, süresi yaklaşan yük sahibine hatırlatır');

Artisan::command('shipments:auto-approve', function (ShipmentService $shipments) {
    $this->info('Otomatik onaylanan teslimat sayısı: '.$shipments->autoApproveDue());
})->purpose('Onay süresi dolan teslimatları otomatik onaylar');

Artisan::command('privacy:purge', function (DriverLocationService $locations) {
    $this->info('Silinen eski konum kaydı: '.$locations->purgeOld(90));
})->purpose('KVKK saklama süresi dolan kişisel verileri siler (konum izleri 90 gün)');

Artisan::command('accounts:purge-drafts', function (AccountService $accounts) {
    $this->info('Silinen taslak hesap sayısı: '.$accounts->purgeUnverifiedDrafts());
})->purpose('E-posta doğrulaması yapılmamış 2 saatten eski taslak kayıtları siler');

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

Artisan::command('scraped-loads:ai-audit {--limit= : Denetlenecek ilan sayısı (boş: panel ayarı)}', function (RuleFeedbackService $feedback) {
    $limit = $this->option('limit') !== null ? (int) $this->option('limit') : Settings::int('ai_audit_daily_count');
    $r = $feedback->audit($limit);
    $this->info($r['skipped'] ? 'Denetim kapalı ya da yapay zeka ayarlı değil.' : "Denetlenen: {$r['checked']}, uyuşmazlık: {$r['mismatched']} (öneri olarak sözlük ekranına düştü).");
})->purpose('Kuralla çözülen ilanlardan günlük örneklemi yapay zekaya denetletir; uyuşmazlıklar sözlük ekranına öneri olur');

Artisan::command('ilan:dogruluk {--hatalar : Yalnız bozuk örnekleri yaz}', function () {
    $r = IntakeBenchmark::run();
    $this->info("Altın ölçüm seti: {$r['passed']} / {$r['total']} örnek doğru (".($r['total'] > 0 ? round($r['passed'] * 100 / $r['total'], 1) : 0).'%)');
    foreach ($r['failed'] as $f) {
        $this->line('  - '.$f['id'].': '.implode(' | ', $f['errors']));
        if (! $this->option('hatalar')) {
            $this->line('      '.str_replace("\n", ' ⏎ ', mb_substr($f['message'], 0, 160)));
        }
    }

    return $r['failed'] === [] ? self::SUCCESS : self::FAILURE;
})->purpose('Altın ölçüm setini kural katmanından geçirir ve doğruluk oranını yazar (yapay zeka çağrılmaz)');

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
Schedule::command('loads:expire')->hourly()->withoutOverlapping(180);
Schedule::command('loads:expire-unpaid')->everyThirtyMinutes()->withoutOverlapping(60);
Schedule::command('trips:scan-return-loads')->everyTenMinutes()->withoutOverlapping(60);
Schedule::command('trips:auto-close')->dailyAt('04:10');
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('subscriptions:remind')->dailyAt('09:00');
Schedule::command('notifications:retry-mail')->everyTenMinutes()->withoutOverlapping(60);
Schedule::command('scraped-loads:purge-expired')->daily();
Schedule::command('scraped-loads:ai-enrich')->everyFiveMinutes()->withoutOverlapping(10);

// Konum sözlüğü temizliği sonrası (0001_01_32) son 14 günün ilanları ham mesajdan yeniden konumlanır; parça parça, 60 sn/çalıştırma,
// imleç ayarda; bitince ya da süre dolunca ayar silinir. Yönetici düzenlemesi korunur; yapay zeka/şablon çözümü de yeniden bakılır.
Artisan::command('scraped-loads:relocate-force {--seconds=60 : Bu çalıştırmada en çok kaç saniye}', function (LoadStandardizer $standardizer) {
    $until = Settings::string('scraper_relocate_force_until');
    if ($until === '' || now()->gt(Carbon::parse($until))) {
        $this->info('Yeniden konumlama planlı değil ya da süresi doldu.');

        return;
    }
    $budget = max(5, (int) $this->option('seconds'));
    $cursor = (int) Settings::string('scraper_relocate_force_cursor');
    $started = microtime(true);
    $done = 0;
    $changed = 0;
    $q = ScrapedLoad::query()->where('status', '!=', 'rejected')->where('created_at', '>=', now()->subDays(14))->orderByDesc('id');
    if ($cursor > 0) {
        $q->where('id', '<', $cursor);
    }
    $finished = true;
    foreach ($q->limit(3000)->cursor() as $load) {
        if (microtime(true) - $started > $budget) {
            $finished = false;
            break;
        }
        $done++;
        if ($standardizer->relocateFromRaw($load, force: true)) {
            $changed++;
        }
        $cursor = $load->id;
    }
    // İlerleme sağlık ekranında görünür: toplam bakılan/değişen, son parça zamanı, imleç
    $progress = json_decode(Settings::string('scraper_relocate_force_progress') ?: '{}', true) ?: [];
    $progress = ['done' => (int) ($progress['done'] ?? 0) + $done, 'changed' => (int) ($progress['changed'] ?? 0) + $changed, 'at' => now()->toDateTimeString(), 'cursor' => $cursor, 'finished' => false];
    if ($finished && $done < 3000) {
        Settings::set('scraper_relocate_force_until', '');
        Settings::set('scraper_relocate_force_cursor', '0');
        $progress['finished'] = true;
        $this->info("Yeniden konumlama bitti: {$changed} / {$done} (son parça)");
    } else {
        Settings::set('scraper_relocate_force_cursor', (string) $cursor);
        $this->info("Yeniden konumlama sürüyor: {$changed} / {$done}, imleç #{$cursor}");
    }
    Settings::set('scraper_relocate_force_progress', json_encode($progress));
})->purpose('Konum sözlüğü düzeltmesi sonrası ilanları ham mesajdan yeniden konumlar');
Schedule::command('scraped-loads:relocate-force')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('scraped-loads:ai-audit')->dailyAt('05:20')->withoutOverlapping(120);
Schedule::command('queue:prune-failed', ['--hours' => 72])->dailyAt('04:40'); // 3 günden eski başarısız işler kendiliğinden silinir (sağlık ekranında takılı kalmasın)
// Zamanlayıcı nabzı: yönetici ekranı "zamanlayıcı çalışıyor mu" sorusunu buradan cevaplar.
Schedule::call(fn () => Cache::put('scheduler.heartbeat', now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
// Kuyruk nabzı: işçi bu işi çalıştırınca zaman damgası yazar; tazeyse telefon mesajları kuyruğa verilir (bkz. NotificationWebhookController).
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
Schedule::command('scraped-loads:auto-approve')->everyMinute()->withoutOverlapping(10);
Schedule::command('loads:release-to-free')->everyMinute()->withoutOverlapping(10);
Schedule::command('shipments:auto-approve')->hourly();
Schedule::command('accounts:purge-drafts')->hourly();
Schedule::command('privacy:purge')->dailyAt('04:20');
Schedule::command('system:backup')->dailyAt('03:30')->withoutOverlapping(180);

// ---------------------------------------------------------------------------------------------------------------
// İşletim ve uyarılar (2026-10-05)
// ---------------------------------------------------------------------------------------------------------------
// Zamanlayıcı çıktısı storage/logs/schedule.log'a akar (crontab, deploy/install.sh). Dosya haftada bir kırpılır ki
// yavaş diskte sınırsız büyümesin; son 2 MB saklanır.
Schedule::call(function (): void {
    $file = storage_path('logs/schedule.log');
    if (! is_file($file) || filesize($file) < 2 * 1024 * 1024) {
        return;
    }
    $tail = (string) file_get_contents($file, false, null, max(0, filesize($file) - 2 * 1024 * 1024));
    file_put_contents($file, '[kırpıldı '.now()->toDateTimeString()."]\n".$tail);
})->weeklyOn(1, '04:50')->name('schedule-log-trim');

// Tek yedek yolu (I5): gece 03:30 tam yedek (son 7, yukarıda) + 6 saatte bir yalnız veritabanı dökümü (son 12 = 3 gün),
// tam yedekten 15 dk sonra ki ikisi çakışmasın. En kötü veri kaybı 24 saatten 6 saate iner. Hata → yöneticilere bildirim.
Schedule::command('system:backup', ['--type' => 'database', '--keep' => 12])->cron('45 3,9,15,21 * * *')->withoutOverlapping(60);

// Sistem bekçisi (I1): kuyruk, başarısız iş, yedek, disk, telefon sessizliği, e-posta, Redis, SSL, yeniden konumlama.
// Sorun → Telegram (alert_telegram_chat_id) + yönetici bildirimi; 6 saatte bir tekrar; düzelince "düzeldi". Sağlık ekranı
// SystemWatchdog::lastStatus() ile son çalışmayı gösterir.
Schedule::command('system:watchdog')->everyFiveMinutes()->withoutOverlapping(10);

// Para ve sevkiyat (2026-10-05): şoför gelmedi uyarısı, hakediş mutabakatı, açık ödeme emri süresi.
Artisan::command('loads:no-show', function (LoadService $loads) {
    $this->info('"Şoför gelmedi" uyarısı gönderilen ilan: '.$loads->notifyNoShows());
})->purpose('Ödenmiş, yükleme tarihi geçmiş ve yola çıkılmamış ilanlarda yük sahibi, şoför ve operasyonu bir kez uyarır');

Artisan::command('payouts:reconcile', function (PayoutService $payouts) {
    $r = $payouts->reconcile();
    $this->info("Hakediş mutabakatı: açılan {$r['created']} · işlemde takılıp bekleyene dönen {$r['reset']} · yeniden aktarılan {$r['retried']}");
})->purpose('Hakedişsiz onaylı ilanları, işlemde takılan ve bekleyen hakedişleri toparlar; pazaryeri aktarımını artan beklemeyle yeniden dener');

Artisan::command('payments:expire-stale', function (PaymentService $payments) {
    $this->info('Süresi dolan açık ödeme emri: '.$payments->expireStale());
})->purpose('Ayarlı saatten (payment_order_stale_hours) eski açık ödeme emirlerini kapatır; geç gelen ödeme iade edilir');

Schedule::command('loads:no-show')->hourly()->withoutOverlapping(10);
Schedule::command('payouts:reconcile')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('payments:expire-stale')->dailyAt('04:50');
