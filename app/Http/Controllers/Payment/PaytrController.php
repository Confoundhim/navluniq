<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Load;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaytrController extends Controller
{
    /** PayTR sunucu bildirimi (CSRF dışı, imza doğrulamalı). */
    public function callback(Request $request, PaymentService $payments): Response
    {
        return response($payments->handleCallback($request->post()), 200)->header('Content-Type', 'text/plain');
    }

    /** Kullanıcı ödeme ekranından döndüğünde; nihai durum yalnız callback ile belirlenir. */
    public function success(Load $load): RedirectResponse
    {
        return redirect()->route('cargo-owner.shipments.show', $load->id)
            ->with('success_message', 'Ödemeniz alındı. Banka onayı birkaç saniye içinde yansıyacaktır.');
    }

    public function fail(Load $load): RedirectResponse
    {
        return redirect()->route('cargo-owner.finance.payment', $load->id)
            ->with('error_message', 'Ödeme tamamlanamadı. Lütfen tekrar deneyin veya farklı bir kart kullanın.');
    }
}
