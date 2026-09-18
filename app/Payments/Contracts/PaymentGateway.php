<?php

namespace App\Payments\Contracts;

use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use Illuminate\Http\Request;

/**
 * Ödeme kuruluşu sözleşmesi. Uygulama yalnız bu arayüzü bilir; sağlayıcı değiştiğinde
 * yeni bir adaptör yazılır, sipariş/fatura/defter/bildirim akışı değişmez.
 *
 * Zorunlu: createCheckout, parseWebhook, refund.
 * Pazaryeri (alt üye işyeri) yetenekleri isteğe bağlıdır; supportsSubMerchants() false ise
 * şoför ödemeleri finans ekibince banka transferiyle yapılır.
 */
interface PaymentGateway
{
    /** Kısa tanımlayıcı: paytr, iyzico, param… (payment_orders.provider) */
    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    public function isSandbox(): bool;

    /**
     * Sipariş için ödeme ekranı oluşturur.
     *
     * @param  array{ip:string, ok_url:string, fail_url:string, description:string}  $context
     */
    public function createCheckout(PaymentOrder $order, array $context): Checkout;

    /** Sunucu bildirimini doğrular ve özetler; imza hatalıysa valid=false döner. */
    public function parseWebhook(Request $request): WebhookResult;

    public function refund(PaymentOrder $order, float $amount): RefundResult;

    public function supportsSubMerchants(): bool;

    /**
     * Şoförü alt üye işyeri olarak kaydeder; sağlayıcı referansını döndürür.
     *
     * @param  array{name:string, email:string, phone:string, iban:string, identity_no?:string, tax_no?:string, address?:string}  $data
     */
    public function registerSubMerchant(array $data): ?string;

    /** Onaylanmış hakedişi alt üye işyerine aktarır. */
    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult;
}
