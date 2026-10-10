<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\StoredCard;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use Illuminate\Http\Request;

/**
 * İadesi, pazaryeri desteği, canlı/test kipi ve aktarım sonucu ayarlanabilir sahte ödeme kuruluşu.
 * Para testleri (MoneySafetyTest, MarketplaceOnlyTest, CancelRefundPolicyTest, InTransitDisputeTest) bunu paylaşır.
 * Bildirim: merchant_oid + status + sig=ok (+ amount) alanlı POST; imza "ok" değilse geçersiz.
 */
final class RefundableFakeGateway implements PaymentGateway
{
    public bool $refundSucceeds = true;

    public int $refunds = 0;

    /** @var list<float> iade edilen tutarlar */
    public array $refundedAmounts = [];

    public bool $marketplace = false;

    public bool $sandbox = true;

    public bool $transferSucceeds = true;

    /** @var list<array> alt üye işyeri kayıt istekleri */
    public array $registrations = [];

    /** @var list<int> aktarım denemesi yapılan hakedişler */
    public array $transfers = [];

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Sahte Kuruluş';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        $order->update(['status' => 'pending', 'request_snapshot' => $context]);

        return new Checkout('iframe', 'https://fake.test/pay/'.$order->merchant_oid, 'tok', 600);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $oid = (string) $request->input('merchant_oid');
        $status = (string) $request->input('status', 'success');
        // "ref" verilirse kuruluşun farklı bir ödeme kimliği bildirdiği (ikinci çekim) canlandırılır; olay kimliği de değişir.
        $ref = (string) $request->input('ref', 'fake-ref');

        return new WebhookResult($request->input('sig') === 'ok', $oid, $status, $request->input('amount') !== null ? (float) $request->input('amount') : null, $oid.':'.$status.($ref !== 'fake-ref' ? ':'.$ref : ''), $request->all(), $ref);
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        $this->refunds++;
        $this->refundedAmounts[] = $amount;

        return $this->refundSucceeds ? new RefundResult(true, '{"ok":1}') : new RefundResult(false, '{"ok":0}', 'Kuruluş reddetti');
    }

    public function supportsSubMerchants(): bool
    {
        return $this->marketplace;
    }

    public function registerSubMerchant(array $data): ?string
    {
        $this->registrations[] = $data;
        // Gerçek kuruluş gibi: kimlik yoksa kayıt yok (sahte TC gönderilmez)
        $identity = ($data['legal_type'] ?? 'individual') === 'company' ? ($data['tax_no'] ?? '') : ($data['identity'] ?? '');

        return $identity !== '' ? 'SUB-'.$data['external_id'] : null;
    }

    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        $this->transfers[] = $payout->id;

        return $this->transferSucceeds ? new TransferResult(true, 'TRF-'.$payout->id) : new TransferResult(false, null, 'Kuruluş aktarımı reddetti');
    }

    public function supportsStoredCards(): bool
    {
        return false;
    }

    public function chargeStoredCard(PaymentOrder $order, StoredCard $card, array $context): ChargeResult
    {
        return new ChargeResult(false, 'fake:'.$order->merchant_oid, [], null, null, 'Kart saklama kapalı');
    }

    public function deleteStoredCard(StoredCard $card): bool
    {
        return true;
    }
}
