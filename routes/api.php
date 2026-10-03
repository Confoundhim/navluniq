<?php

use App\Http\Controllers\Api\NotificationWebhookController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Support\Toplayici;
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
        ->middleware('throttle:scraper-webhook');

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
