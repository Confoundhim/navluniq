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
 * Kart saklama destekli sahte kuruluş (otomatik yenilenen premium testleri). Bildirim: merchant_oid + status + sig=ok;
 * card_token verilirse "kullanıcı kartını kaydetti" sayılır. Kayıtlı kart çekimi $chargeSucceeds / $cardInvalid ile yönetilir.
 */
final class StoredCardFakeGateway implements PaymentGateway
{
    public bool $storedCards = true;

    public bool $chargeSucceeds = true;

    public bool $cardInvalid = false;

    /** @var list<array{order:int, card:int, amount:float}> */
    public array $charges = [];

    /** @var list<int> */
    public array $deletedCards = [];

    /** @var array<string, mixed> son ödeme formu bağlamı (save_card / card_user_key denetimi) */
    public array $lastContext = [];

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Sahte Kuruluş (kart saklama)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        $this->lastContext = $context;
        $order->update(['status' => 'pending']);

        return new Checkout('iframe', 'https://fake.test/pay/'.$order->merchant_oid, 'tok', 600);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $oid = (string) $request->input('merchant_oid');
        $status = (string) $request->input('status', 'success');
        $card = $request->input('card_token') ? ['card_user_key' => 'cuk-'.$oid, 'card_token' => (string) $request->input('card_token'), 'last_four' => (string) $request->input('last_four', '1234'), 'association' => 'VISA', 'family' => 'Bonus', 'bank' => null] : null;

        return new WebhookResult($request->input('sig') === 'ok', $oid, $status, $request->input('amount') !== null ? (float) $request->input('amount') : null, $oid.':'.$status, $request->all(), 'fake-ref', card: $card);
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        return new RefundResult(true, '{"ok":1}');
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
        return new TransferResult(false, null, 'Pazaryeri yok');
    }

    public function supportsStoredCards(): bool
    {
        return $this->storedCards;
    }

    public function chargeStoredCard(PaymentOrder $order, StoredCard $card, array $context): ChargeResult
    {
        $this->charges[] = ['order' => $order->id, 'card' => $card->id, 'amount' => (float) $order->amount];
        $n = count($this->charges);

        return $this->chargeSucceeds
            ? new ChargeResult(true, 'fake-charge:'.$order->merchant_oid, ['ok' => 1], (float) $order->amount, 'fake-renew-'.$order->id)
            : new ChargeResult(false, 'fake-charge:'.$order->merchant_oid.':'.$n, ['ok' => 0], null, null, $this->cardInvalid ? '10054 Kartın süresi dolmuş' : '10051 Yetersiz bakiye', $this->cardInvalid);
    }

    public function deleteStoredCard(StoredCard $card): bool
    {
        $this->deletedCards[] = $card->id;

        return true;
    }
}
