<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ödeme kuruluşu bildirimi (CSRF dışı; imza/doğrulama adaptörde). Sunucudan sunucuya gelen
 * bildirime düz metin yanıt; kullanıcının tarayıcısından gelen dönüşe (iyzico callback) sonuç
 * sayfasına yönlendirme döner.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $payments, string $provider): Response|RedirectResponse
    {
        [$body, $status, $redirect] = $payments->handleWebhook($provider, $request) + [2 => null];

        if ($redirect) {
            return new RedirectResponse($redirect);
        }

        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
