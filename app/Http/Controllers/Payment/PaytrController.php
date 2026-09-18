<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Eski PayTR adresleri: genel bildirim ve sonuç sayfalarına yönlendirir (PayTR panelinde tanımlı adresler bozulmasın). */
class PaytrController extends Controller
{
    public function callback(Request $request, PaymentService $payments): Response
    {
        [$body, $status] = $payments->handleWebhook('paytr', $request);

        return response($body, $status)->header('Content-Type', 'text/plain');
    }

    public function success(Load $load): RedirectResponse
    {
        return $this->redirectToResult($load, 'basarili');
    }

    public function fail(Load $load): RedirectResponse
    {
        return $this->redirectToResult($load, 'basarisiz');
    }

    private function redirectToResult(Load $load, string $outcome): RedirectResponse
    {
        $order = PaymentOrder::query()->where('load_id', $load->id)->where('purpose', PaymentService::PURPOSE_ESCROW)->latest('id')->first();
        // Livewire, oturum içinde redirect() yardımcısını kendi yönlendiricisiyle değiştirebildiği için doğrudan yanıt üretilir.
        if ($order) {
            return new RedirectResponse(route('payment.result', ['order' => $order->public_id, 'outcome' => $outcome]));
        }

        return new RedirectResponse(route('cargo-owner.shipments.show', $load->id));
    }
}
