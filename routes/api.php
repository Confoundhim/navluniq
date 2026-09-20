<?php

use App\Http\Controllers\Api\NotificationWebhookController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Rotaları (NavlunIQ SaaS)
|--------------------------------------------------------------------------
| Bu rotalar "api" ön ekiyle otomatik olarak sarmalanır.
| Örneğin: http://navluniq.test/api/v1/webhook/whatsapp-scraper
*/

Route::prefix('v1')->group(function () {
    // Node.js Baileys mikro-servisimizden gelen ham mesajları karşılayan webhook ucu
    Route::post('/webhook/whatsapp-scraper', [WhatsappWebhookController::class, 'handle'])
        ->middleware('throttle:30,1');

    // Android bildirim iletici (MacroDroid vb.): WhatsApp bildirim başlığı + metni
    Route::post('/webhook/notification', [NotificationWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');
    // Telefonun tarayıcısından açılan bağlantı sınaması (GET); Canlı akışa "Bağlantı sınaması" düşer.
    Route::get('/webhook/notification/ping', [NotificationWebhookController::class, 'ping'])
        ->middleware('throttle:30,1')->name('api.notification.ping');
});
