<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\WebhookResult;
use App\Payments\GatewayManager;
use App\Support\PaymentReadiness;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sağlayıcıdan bağımsız ödeme akışı: sipariş oluşturma, ödeme ekranı, sunucu bildirimi, iade.
 * Sağlayıcıya özgü her şey PaymentGateway adaptöründedir. Ödeme yalnız imzası doğrulanmış
 * sunucu bildirimiyle "alındı" sayılır; kullanıcı dönüş sayfası hiçbir zaman durum belirlemez.
 *
 * Sipariş amaçları: escrow (navlun bedeli, teslimat onaylı), subscription (premium üyelik).
 * Canlı ortamda navlun tahsilatı yalnız pazaryeri (alt üye işyeri) modeliyle açılır (PaymentReadiness::escrowBlocker).
 */
class PaymentService
{
    public const PURPOSE_ESCROW = 'escrow';

    public const PURPOSE_SUBSCRIPTION = 'subscription';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LedgerService $ledger,
        private readonly GatewayManager $gateways,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateways->active();
    }

    public function isConfigured(): bool
    {
        return $this->gateway()->isConfigured();
    }

    public function isSandbox(): bool
    {
        return $this->gateway()->isSandbox();
    }

    /** Navlun bedeli + yük sahibi hizmet bedeli + şoför komisyonu anlık görüntüsü (ödeme emrinde dondurulur). */
    public function calculateAmounts(Load $load): array
    {
        return $this->amountsFor((float) $load->price, $load->driverProfile);
    }

    /**
     * Verilen navlun bedeli için tutarlar (teklif kartında "kabul edersen ödeyeceğin toplam": teklif tutarı + hizmet bedeli).
     * Kabul edilince ilan bedeli teklif tutarı olur; bu yüzden ödeme sayfasındaki toplamla aynı formül.
     */
    public function amountsFor(float $price, ?DriverProfile $driver = null): array
    {
        $price = round($price, 2);
        $feeRate = Settings::float('commission_cargo_owner');
        $fee = round($price * $feeRate / 100, 2);
        $commissionRate = $driver?->commissionRate() ?? Settings::float('commission_standard_driver');
        $commission = round($price * $commissionRate / 100, 2);

        return [
            'price' => $price,
            'service_fee' => $fee,
            'total' => round($price + $fee, 2),
            'commission_rate' => $commissionRate,
            'commission_amount' => $commission,
            'driver_net' => round($price - $commission, 2),
        ];
    }

    /** Ödenmemiş ilan için açık bir ödeme emri döner (varsa yeniden kullanır). */
    public function orderFor(Load $load, User $payer): PaymentOrder
    {
        if ($load->cargoOwnerProfile?->user_id !== $payer->id) {
            throw new RuntimeException('Bu ilan size ait değil.');
        }

        if ($load->status !== Load::STATUS_ASSIGNED || $load->escrow_status !== Load::ESCROW_PENDING) {
            throw new RuntimeException('Bu ilan ödeme adımında değil.');
        }

        // Canlıda pazaryeri kapalıysa ödeme emri bile açılmaz: NavlunIQ para tutmaz.
        if ($blocker = PaymentReadiness::escrowBlocker($this->gateway())) {
            throw new RuntimeException($blocker);
        }

        $amounts = $this->calculateAmounts($load);

        $existing = PaymentOrder::query()->where('load_id', $load->id)->where('purpose', self::PURPOSE_ESCROW)
            ->whereIn('status', ['created', 'pending'])->latest()->first();

        if ($existing && abs((float) $existing->amount - $amounts['total']) < 0.005 && abs((float) $existing->commission_rate - $amounts['commission_rate']) < 0.001) {
            return $existing;
        }
        // Tutar değişti (ör. hizmet bedeli ayarı): eski açık emirler kapanır ki iki ödeme bağlantısı aynı anda yaşamasın.
        PaymentOrder::query()->where('load_id', $load->id)->where('purpose', self::PURPOSE_ESCROW)
            ->whereIn('status', ['created', 'pending'])->update(['status' => 'cancelled', 'failed_at' => now()]);

        return PaymentOrder::create([
            'load_id' => $load->id,
            'user_id' => $payer->id,
            'purpose' => self::PURPOSE_ESCROW,
            'provider' => $this->gateway()->id(),
            'merchant_oid' => $this->merchantOid('NQ'.$load->id),
            'amount' => $amounts['total'],
            'currency' => 'TRY',
            'service_fee_amount' => $amounts['service_fee'],
            'commission_rate' => $amounts['commission_rate'],
            'commission_amount' => $amounts['commission_amount'],
            'driver_net_amount' => $amounts['driver_net'],
            'status' => 'created',
        ]);
    }

    /** Premium abonelik için açık bir ödeme emri döner (varsa yeniden kullanır). */
    public function orderForSubscription(User $payer, float $amount): PaymentOrder
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Abonelik ücreti tanımlı değil.');
        }

        $existing = PaymentOrder::query()->where('user_id', $payer->id)->where('purpose', self::PURPOSE_SUBSCRIPTION)
            ->whereIn('status', ['created', 'pending'])->where('created_at', '>=', now()->subHours(6))->latest()->first();
        if ($existing && abs((float) $existing->amount - $amount) < 0.01) {
            return $existing;
        }

        return PaymentOrder::create([
            'load_id' => null,
            'user_id' => $payer->id,
            'purpose' => self::PURPOSE_SUBSCRIPTION,
            'provider' => $this->gateway()->id(),
            'merchant_oid' => $this->merchantOid('NQS'.$payer->id),
            'amount' => $amount,
            'currency' => 'TRY',
            'service_fee_amount' => 0,
            'status' => 'created',
        ]);
    }

    /** Sağlayıcıya özgü ödeme ekranını (iframe/yönlendirme) hazırlar. */
    public function checkout(PaymentOrder $order, Request $request): Checkout
    {
        $gateway = $this->gateway();
        if (! $gateway->isConfigured()) {
            throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
        }
        if (! in_array($order->status, ['created', 'pending'], true)) {
            throw new RuntimeException('Bu sipariş ödeme adımında değil.');
        }

        $load = $order->cargoLoad;
        $description = $order->purpose === self::PURPOSE_SUBSCRIPTION
            ? 'NavlunIQ Premium şoför üyeliği (1 ay)'
            : 'Navlun bedeli #'.$order->load_id.' '.($load?->pickup_location).' - '.($load?->delivery_location);

        $context = [
            'ip' => (string) $request->ip(),
            'ok_url' => route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']),
            'fail_url' => route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarisiz']),
            'description' => $description,
            'address' => $load?->cargoOwnerProfile?->company_title ?: ($load?->pickup_location ?: 'Türkiye'),
            'city' => $load?->pickup_location ? trim((string) explode('/', (string) $load->pickup_location)[0]) : 'İstanbul',
            'identity_number' => $this->buyerIdentity($order),
        ];

        if ($order->purpose === self::PURPOSE_ESCROW) {
            if ($blocker = PaymentReadiness::escrowBlocker($gateway)) {
                throw new RuntimeException($blocker);
            }
            // Pazaryeri: navlun kalemi şoförün alt üye işyerine bağlanır; platform payı (komisyon + hizmet bedeli) NavlunIQ'da kalır.
            // Şoför kaydı teklif kabulünde yapılır; burada yalnız doğrulanır (eksikse ödeme açılmaz, para NavlunIQ'da birikmez).
            if ($gateway->supportsSubMerchants() && $load?->driverProfile) {
                $driver = $load->driverProfile;
                if (! $driver->payout_provider_ref || $driver->payout_provider !== $gateway->id()) {
                    app(PayoutService::class)->ensureSubMerchant($driver);
                    $driver->refresh();
                }
                if (! $driver->payout_provider_ref || $driver->payout_provider !== $gateway->id()) {
                    throw new RuntimeException('Şoförün ödeme kuruluşu (alt üye işyeri) kaydı tamamlanmadığı için ödeme açılamıyor. Şoför Ödemelerim sayfasından kimlik numarasını ve IBAN\'ını girince ödeme açılır.');
                }
                $context['sub_merchant_ref'] = $driver->payout_provider_ref;
                $context['sub_merchant_price'] = $this->driverNetFor($order, $load);
            }
        }

        return $gateway->createCheckout($order, $context);
    }

    /** Ödeme emrindeki şoför payı (anlık görüntü); eski emirlerde ilan fiyatından hesaplanır. */
    public function driverNetFor(PaymentOrder $order, ?Load $load = null): float
    {
        if ($order->driver_net_amount !== null) {
            return round((float) $order->driver_net_amount, 2);
        }
        $load ??= $order->cargoLoad;
        $rate = $order->commission_rate !== null ? (float) $order->commission_rate : ($load?->driverProfile?->commissionRate() ?? Settings::float('commission_standard_driver'));

        return round((float) ($load?->price ?? 0) * (1 - $rate / 100), 2);
    }

    /**
     * Ödeme kuruluşuna gidecek alıcı kimliği: bireysel yük sahibinde TC, kurumsalda VKN. Sahte numara hiç gönderilmez;
     * eksikse adaptör ödemeyi açmaz. Abonelikte (şoför) profil TC'si varsa o kullanılır.
     */
    private function buyerIdentity(PaymentOrder $order): string
    {
        $user = $order->user;
        if ($order->purpose === self::PURPOSE_SUBSCRIPTION) {
            return (string) ($user?->driverProfile?->payoutIdentityNumber() ?? '');
        }
        $owner = $user?->cargoOwnerProfile;
        if (! $owner) {
            return '';
        }

        return (string) ($owner->type === 'corporate' ? ($owner->tax_no ?: $owner->tc_no) : ($owner->tc_no ?: $owner->tax_no));
    }

    /**
     * Sunucu bildirimi. Sağlayıcıya dönülecek [gövde, HTTP kodu, yönlendirme] üçlüsü döndürür;
     * yönlendirme yalnız bildirim kullanıcının tarayıcısından geliyorsa (iyzico callback) doludur.
     * Aynı bildirim ikinci kez gelirse çift işlem yapılmaz (provider_event_id benzersiz).
     */
    public function handleWebhook(string $providerId, Request $request): array
    {
        $gateway = $this->gateways->gateway($providerId);
        $result = $gateway->parseWebhook($request);

        if (! $result->valid) {
            Log::warning('Ödeme bildirimi imza doğrulaması başarısız.', ['provider' => $providerId, 'merchant_oid' => $result->merchantOid]);

            // Sağlayıcılar "OK" dışındaki her yanıtta bildirimi yineler; 200 ile açık bir ret gövdesi dönülür.
            $order = $result->merchantOid !== '' ? PaymentOrder::query()->where('merchant_oid', $result->merchantOid)->first() : null;

            return [$result->rejectBody, 200, $this->resultRedirect($result, $order, false)];
        }

        $order = PaymentOrder::query()->where('merchant_oid', $result->merchantOid)->first();
        if (! $order) {
            Log::warning('Ödeme bildirimi bilinmeyen sipariş.', ['provider' => $providerId, 'merchant_oid' => $result->merchantOid]);

            return [$result->ackBody, 200, $result->redirectUser ? route('home') : null];
        }

        $payloadJson = json_encode($result->payload, JSON_UNESCAPED_UNICODE) ?: '{}';

        // Sonuç: 'paid' (olağan), 'mismatch' (tutar uyuşmadı; para çekildi, havuza girmez, iade edilir), 'orphan' (ilan bu arada
        // iptal edilmiş / emir süresi dolmuş; para geldi, iade edilecek) ya da null (tekrar/başarısız bildirim).
        $outcome = DB::transaction(function () use ($order, $result, $payloadJson): ?string {
            $locked = PaymentOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (PaymentEvent::query()->where('payment_order_id', $locked->id)->where('provider_event_id', $result->eventId)->exists()) {
                return null;
            }

            PaymentEvent::create([
                'payment_order_id' => $locked->id,
                'provider_event_id' => $result->eventId,
                'event_type' => 'callback',
                'status' => $result->status,
                'payload_hash' => hash('sha256', $payloadJson),
                'payload_encrypted' => Crypt::encryptString($payloadJson),
                'processed_at' => now(),
                'failure_message' => $result->failureMessage,
            ]);

            if (in_array($locked->status, ['paid', 'refunded', 'refund_pending'], true)) {
                return null;
            }

            $wasClosed = in_array($locked->status, ['cancelled', 'expired'], true);

            if ($result->status !== 'success') {
                if (! $wasClosed) {
                    $locked->update(['status' => 'failed', 'failed_at' => now()]);
                }

                return null;
            }

            // Tutar uyuşmazlığı (eksik tahsilat, kur/yuvarlama, kurcalanmış tutar): ödeme havuza ALINMAZ. Para çekildiği için
            // sipariş "paid" olarak kilitlenir, muhasebeye yazılır ve hemen iade yoluna sokulur; sonuçta "failed" (iade edildi) ya da
            // "refund_pending" (kuruluş reddetti, finans tamamlar) olur. Eksik tahsilatla şoföre tam ödeme yapılmaz.
            if ($result->paidAmount !== null && abs($result->paidAmount - (float) $locked->amount) > 0.01) {
                Log::critical('Ödeme tutar uyuşmazlığı.', ['order' => $locked->id, 'expected' => $locked->amount, 'paid' => $result->paidAmount]);
                $locked->update(['status' => 'paid', 'paid_at' => now(), 'provider_reference' => $result->providerReference,
                    'failure_message' => mb_substr('Tutar uyuşmazlığı: beklenen '.number_format((float) $locked->amount, 2, ',', '.').' ₺, bildirilen '.number_format($result->paidAmount, 2, ',', '.').' ₺', 0, 500)]);

                return 'mismatch';
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_reference' => $result->providerReference,
            ]);

            if ($locked->purpose === self::PURPOSE_ESCROW) {
                $load = Load::query()->lockForUpdate()->find($locked->load_id);
                // İlan bu arada iptal edilmiş ya da sipariş kapanmışsa para havuza girmez; iade yoluna gider.
                if (! $load || $load->status === Load::STATUS_CANCELLED || $wasClosed) {
                    return 'orphan';
                }
                // Havuz zaten dolu (başka bir emirle ödenmiş) ya da ilan ödeme aşamasında değil: ikinci tahsilat yetimdir, iade edilir.
                if ($load->escrow_status !== Load::ESCROW_PENDING || $load->status !== Load::STATUS_ASSIGNED) {
                    return 'orphan';
                }
                $load->update(['escrow_status' => Load::ESCROW_PAID]);
                // Aynı ilanın diğer açık emirleri kapanır (ikinci ödeme bağlantısı kalmasın).
                PaymentOrder::query()->where('load_id', $load->id)->where('purpose', self::PURPOSE_ESCROW)->whereKeyNot($locked->id)
                    ->whereIn('status', ['created', 'pending'])->update(['status' => 'cancelled', 'failed_at' => now()]);
            }

            return 'paid';
        }, 3);

        match ($outcome) {
            'paid' => $this->afterPaid($order->fresh()),
            'mismatch' => $this->afterMismatch($order->fresh(), $result),
            'orphan' => $this->afterOrphanPayment($order->fresh()),
            default => null,
        };

        return [$result->ackBody, 200, $this->resultRedirect($result, $order, $outcome === 'paid')];
    }

    /**
     * Tutar uyuşmayan bildirim: çekilen para muhasebeye yazılır ve iade edilir; başarılıysa sipariş "failed" (yeniden ödeme
     * yapılabilir), reddedilirse "refund_pending". Yönetici ve yük sahibi her iki durumda haberdar edilir; para havuza alınmaz.
     */
    private function afterMismatch(PaymentOrder $order, WebhookResult $result): void
    {
        $paid = round((float) $result->paidAmount, 2);
        $mismatchNote = (string) $order->failure_message;
        $this->bookEscrowIn($order, $paid, 'Navlun tahsilatı (tutar uyuşmazlığı) #'.$order->load_id);
        $refunded = $paid > 0 ? $this->refund($order, $paid, 'Tutar uyuşmazlığı #'.$order->load_id) : false;
        if ($refunded) {
            $order->update(['status' => 'failed', 'failed_at' => now(), 'failure_message' => mb_substr($mismatchNote.' · çekilen tutar iade edildi', 0, 500)]);
        } else {
            $order->update(['failure_message' => mb_substr($mismatchNote.' · iade finans ekibince tamamlanacak', 0, 500)]);
        }

        $this->notifications->notifyAdmins('manage payouts', 'Ödeme tutarı uyuşmuyor',
            ["Sipariş {$order->merchant_oid}: beklenen ".number_format((float) $order->amount, 2, ',', '.').' ₺, sağlayıcı '.number_format($paid, 2, ',', '.').' ₺ bildirdi. Ödeme kabul edilmedi.',
                $refunded ? 'Çekilen tutar ödeme kuruluşuna iade talimatıyla geri gönderildi.' : 'İade ödeme kuruluşunda yapılamadı; Finans → Ödeme emirleri sekmesinden elle tamamlayın.'],
            route('admin.finance'), 'Finans ekranı', 'admin');
        if ($payer = $order->user) {
            $this->notifications->notify($payer, 'Ödemeniz doğrulanamadı',
                ['Ödeme sağlayıcısından gelen tutar sipariş tutarıyla uyuşmadı; ödeme kabul edilmedi.',
                    $refunded ? 'Kartınızdan çekilen tutar iade edildi; bankanıza göre 1-10 iş günü içinde hesabınızda görünür. Ödemeyi yeniden deneyebilirsiniz.'
                        : 'Kartınızdan çekim olduysa tutar iade edilecek; finans ekibi süreci tamamlayınca size bildirilecek.'],
                $order->load_id ? route('cargo-owner.shipments.show', $order->load_id) : route('home'), 'Sevkiyatı görüntüle', 'payment');
        }
    }

    /** İptal edilmiş ilana ödeme geldi: para geldiği için muhasebeye yazılır, hemen iade yoluna sokulur. */
    private function afterOrphanPayment(PaymentOrder $order): void
    {
        $this->bookEscrowIn($order, (float) $order->amount, 'Navlun tahsilatı (iptal edilmiş ilan) #'.$order->load_id);
        $refunded = $this->refund($order, (float) $order->amount, 'İptal edilmiş ilana gelen ödeme');
        if ($payer = $order->user) {
            $this->notifications->notify($payer, $refunded ? 'Ödemeniz iade edildi' : 'Ödemeniz iade sürecinde',
                [$refunded
                    ? 'İlan ödeme tamamlanmadan iptal edildiği için navlun bedeli kartınıza iade edildi; bankanıza göre 1-10 iş günü içinde hesabınızda görünür.'
                    : 'İlan ödeme tamamlanmadan iptal edildi. Navlun bedelinin iadesi finans ekibi tarafından tamamlanacak; sonuç size bildirilecek.'],
                route('cargo-owner.loads.index'), 'İlanlarım', 'payment');
        }
    }

    private function bookEscrowIn(PaymentOrder $order, float $amount, string $description): void
    {
        try {
            $this->ledger->post('escrow_in', $description, [
                ['account_code' => 'escrow_cash', 'direction' => 'debit', 'amount' => $amount],
                ['account_code' => 'escrow_liability', 'direction' => 'credit', 'amount' => $amount],
            ], PaymentOrder::class, $order->id);
        } catch (\Throwable $e) {
            Log::error('Navlun tahsilatı muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function resultRedirect(WebhookResult $result, ?PaymentOrder $order, bool $ok): ?string
    {
        if (! $result->redirectUser) {
            return null;
        }
        if (! $order) {
            return route('home');
        }

        return route('payment.result', ['order' => $order->public_id, 'outcome' => $ok ? 'basarili' : 'basarisiz']);
    }

    private function afterPaid(PaymentOrder $order): void
    {
        if ($order->purpose === self::PURPOSE_SUBSCRIPTION) {
            app(SubscriptionService::class)->activate($order);

            return;
        }

        $load = $order->cargoLoad;
        $this->bookEscrowIn($order, (float) $order->amount, 'Navlun tahsilatı #'.$load?->id);

        if ($load) {
            if ($driverUser = $load->driverProfile?->user) {
                $this->notifications->notify($driverUser, 'Navlun ödemesi yapıldı',
                    ['Yük sahibi navlun ödemesini yaptı. Artık sevkiyatı başlatabilirsiniz.'],
                    route('driver.jobs.show', $load->id), 'İşe git', 'payment');
            }
            if ($ownerUser = $load->cargoOwnerProfile?->user) {
                $this->notifications->notify($ownerUser, 'Ödemeniz alındı',
                    ['Navlun ödemesi teslimat onayınızla şoföre tamamlanacaktır.'],
                    route('cargo-owner.shipments.show', $load->id), 'Sevkiyatı takip et', 'payment');
            }
        }
    }

    /** Uyuşmazlık kararı sonrası iade. Sağlayıcı iadeyi kabul etmezse manuel iade kaydı bırakır. */
    public function refund(PaymentOrder $order, float $amount, string $reason): bool
    {
        if ($order->status !== 'paid') {
            throw new RuntimeException('Yalnız ödenmiş siparişler iade edilebilir.');
        }

        $amount = round(min($amount, (float) $order->amount), 2);
        $gateway = $this->gateways->gateway((string) $order->provider);
        $result = $gateway->isConfigured() ? $gateway->refund($order, $amount) : null;
        $succeeded = $result?->succeeded ?? false;

        if ($result !== null) {
            PaymentEvent::create([
                'payment_order_id' => $order->id,
                'provider_event_id' => 'refund:'.$order->id.':'.uniqid('', true), // aynı saniyede iki iade çakışmasın
                'event_type' => 'refund',
                'status' => $succeeded ? 'success' : 'failed',
                'payload_hash' => hash('sha256', $result->rawResponse),
                'payload_encrypted' => Crypt::encryptString($result->rawResponse),
                'processed_at' => now(),
                'failure_message' => $succeeded ? null : $result->failureMessage,
            ]);
        }

        if ($succeeded) {
            $order->update(['status' => 'refunded', 'refunded_at' => now()]);
            try {
                $this->ledger->post('refund', 'İade #'.$order->load_id.' '.$reason, [
                    ['account_code' => 'escrow_liability', 'direction' => 'debit', 'amount' => $amount],
                    ['account_code' => 'escrow_cash', 'direction' => 'credit', 'amount' => $amount],
                ], PaymentOrder::class, $order->id);
            } catch (\Throwable $e) {
                Log::error('İade muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        } else {
            $order->update(['status' => 'refund_pending', 'failure_message' => mb_substr((string) ($result?->failureMessage ?: 'Ödeme kuruluşu iadeyi yapamadı ya da yapılandırılmamış.'), 0, 500)]);
            Log::warning('İade manuel olarak tamamlanmalı.', ['order' => $order->id, 'amount' => $amount, 'reason' => $reason]);
            // Sessiz kalmaz: finans ekibi iadeyi sağlayıcı panelinden yapıp Finans ekranında "İade yapıldı" der.
            $this->notifications->notifyAdmins('manage payouts', 'İade tamamlanamadı, elle yapılmalı',
                ["Sipariş {$order->merchant_oid} (".number_format($amount, 2, ',', '.').' ₺) için iade ödeme kuruluşunda yapılamadı: '.($result?->failureMessage ?: 'kuruluş yapılandırılmamış').'. Gerekçe: '.$reason,
                    'İadeyi sağlayıcı panelinden yapın, sonra Finans → Ödeme emirleri sekmesinde "İade yapıldı" deyin.'],
                route('admin.finance'), 'Finans ekranı', 'admin');
        }

        return $succeeded;
    }

    /**
     * İlanın ödenmiş navlun emrini tam tutarla iade eder ve ilanın havuz durumunu yazar: başarılıysa "iade edildi", kuruluş
     * reddettiyse "iade bekleniyor" (finans "İade yapıldı" deyince kapanır). Ödenmiş emir yoksa (ör. elle işaretlenmiş test
     * verisi) false döner ve havuz "iade bekleniyor" olur. Kilit DIŞINDA çağrılır (sağlayıcı yavaşsa satırlar beklemesin).
     * $reopenLoad: ilan havuza dönüyorsa (şoför ödeme sonrası vazgeçti) havuz durumu "ödeme bekleniyor" kalır, iade yalnız emirde izlenir.
     */
    public function refundLoad(Load $load, string $reason, bool $reopenLoad = false): bool
    {
        $order = $load->paymentOrders()->where('purpose', self::PURPOSE_ESCROW)->where('status', 'paid')->latest('id')->first();
        $refunded = $order ? $this->refund($order, (float) $order->amount, $reason) : false;
        if (! $reopenLoad) {
            $load->update(['escrow_status' => $refunded ? Load::ESCROW_REFUNDED : Load::ESCROW_REFUND_PENDING]);
        }

        return $refunded;
    }

    /**
     * Finans ekibi iadeyi sağlayıcı panelinden elle yaptı: sipariş "refunded" olur, defter ve ilan havuz durumu düzelir,
     * yük sahibine haber verilir. Yalnız "refund_pending" siparişler için.
     */
    public function markRefundedManually(PaymentOrder $order, User $admin, string $reference): void
    {
        if ($order->status !== 'refund_pending') {
            throw new RuntimeException('Yalnız iadesi bekleyen siparişler işaretlenebilir.');
        }
        $order->update(['status' => 'refunded', 'refunded_at' => now(), 'failure_message' => null, 'provider_reference' => mb_substr('iade:'.trim($reference), 0, 120)]);
        try {
            $this->ledger->post('refund', 'Elle iade #'.$order->load_id.' '.$reference, [
                ['account_code' => 'escrow_liability', 'direction' => 'debit', 'amount' => $order->amount],
                ['account_code' => 'escrow_cash', 'direction' => 'credit', 'amount' => $order->amount],
            ], PaymentOrder::class, $order->id);
        } catch (\Throwable $e) {
            Log::error('Elle iade muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
        if ($load = $order->cargoLoad) {
            // Havuza geri dönmüş (yeniden teklif alan) ilanın havuz durumu değişmez; yalnız kapanmış sevkiyatın durumu düzelir.
            if (in_array($load->escrow_status, [Load::ESCROW_PAID, Load::ESCROW_ON_HOLD, Load::ESCROW_REFUND_PENDING], true) && $load->status !== Load::STATUS_ACTIVE) {
                $load->update(['escrow_status' => Load::ESCROW_REFUNDED]);
            }
        }
        ActivityLog::record('payment.refunded_manually', "Sipariş {$order->merchant_oid} elle iade edildi ({$reference})", $admin->id, $order);
        if ($payer = $order->user) {
            $this->notifications->notify($payer, 'İadeniz tamamlandı',
                [number_format((float) $order->amount, 2, ',', '.').' ₺ tutarındaki navlun bedeli iade edildi; bankanıza göre 1-10 iş günü içinde hesabınızda görünür. Referans: '.trim($reference)],
                $order->load_id ? route('cargo-owner.shipments.show', $order->load_id) : route('cargo-owner.loads.index'), 'Görüntüle', 'payment');
        }
    }

    /**
     * Açık (created/pending) ödeme emirleri sonsuza kadar açık kalmaz: ayarlı saatten eski olanlar "expired" olur (zamanlanmış görev).
     * Sonradan yine de "başarılı" bildirimi gelirse handleWebhook bunu kapanmış emre gelen ödeme sayıp iade eder.
     */
    public function expireStale(?int $hours = null): int
    {
        $hours ??= max(1, Settings::int('payment_order_stale_hours'));

        return PaymentOrder::query()->whereIn('status', ['created', 'pending'])
            ->where('updated_at', '<', now()->subHours($hours))
            ->update(['status' => 'expired', 'failed_at' => now(), 'updated_at' => now()]);
    }

    private function merchantOid(string $prefix): string
    {
        return $prefix.'T'.now()->format('ymdHis').random_int(1000, 9999); // aynı saniyede iki sipariş çakışmasın
    }
}
