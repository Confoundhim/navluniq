<?php

namespace App\Payments\Gateways;

use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use Illuminate\Http\Request;
use RuntimeException;

/** Sağlayıcı seçilmemiş ya da anahtarlar girilmemişken kullanılan güvenli boş adaptör. */
final class NullGateway implements PaymentGateway
{
    public function id(): string
    {
        return 'none';
    }

    public function label(): string
    {
        return 'Tanımlı değil';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        return new WebhookResult(false, '', 'failed', null, '', [], null, 'Ödeme altyapısı tanımlı değil.');
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        return new RefundResult(false, '', 'Ödeme altyapısı tanımlı değil.');
    }

    public function supportsSubMerchants(): bool
    {
        return false;
    }

    public function registerSubMerchant(array $data): ?string
    {
        return null;
    }

    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        return new TransferResult(false, null, 'Ödeme altyapısı tanımlı değil.');
    }
}
