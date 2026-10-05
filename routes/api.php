<?php

use App\Http\Controllers\Api\NotificationWebhookController;
use App\Support\Toplayici;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Rotaları (NavlunIQ SaaS)
|--------------------------------------------------------------------------
| Bu rotalar "api" ön ekiyle otomatik olarak sarmalanır.
| Örneğin: http://navluniq.test/api/v1/webhook/notification
| Eski Baileys (Node) ucu /webhook/whatsapp-scraper 2026-10-05'te kaldırıldı (I19): WhatsApp botu kullanılmaz,
| mesajlar telefondaki iletici/Toplayıcı uygulamasıyla gelir.
*/

Route::prefix('v1')->group(function () {
    // Android bildirim iletici (MacroDroid vb.): WhatsApp bildirim başlığı + metni
    // Her uç kendi sayacını tutar (bkz. AppServiceProvider): mesaj yoğunluğu sınama ve sürüm uçlarını kilitlemez.
    Route::post('/webhook/notification', [NotificationWebhookController::class, 'handle'])
        ->middleware('throttle:intake');
    // Telefonun tarayıcısından açılan bağlantı sınaması (GET); Canlı akışa "Bağlantı sınaması" düşer.
    Route::get('/webhook/notification/ping', [NotificationWebhookController::class, 'ping'])
        ->middleware('throttle:intake-ping')->name('api.notification.ping');
    // NavlunIQ Toplayıcı (Android) sürüm denetimi: uygulama açılışta bakar, yenisi varsa indirme bağlantısı gösterir.
    Route::get('/toplayici/version', fn () => response()->json(Toplayici::version()))
        ->middleware('throttle:intake-version')->name('api.toplayici.version');
});
