<?php

use App\Services\AccountService;
use App\Services\OfferService;
use App\Services\ShipmentService;
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

Schedule::command('offers:expire')->hourly();
Schedule::command('shipments:auto-approve')->hourly();
Schedule::command('accounts:purge-drafts')->daily();
