<?php

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
});
