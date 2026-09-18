<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Ödeme kuruluşu sunucu bildirimi (CSRF dışı; imza adaptörde doğrulanır). */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $payments, string $provider): Response
    {
        [$body, $status] = $payments->handleWebhook($provider, $request);

        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
