<?php

namespace Tests\Feature\Payments;

use App\Jobs\SendAnnouncementJob;
use App\Jobs\SendNotificationMail;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\StoredCard;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\Data\ChargeResult;
use App\Payments\GatewayManager;
use App\Payments\Gateways\PaytrGateway;
use App\Services\AccountService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Yayın öncesi ödeme/abonelik/bildirim denetimi (2026-10-10): ödendi ama etkinleşmedi (H2), kuruluşa ulaşılamayan çekim (H3),
 * çift tahsilat şüphesi (H4), kuruluş uyuşmazlığı (D), yenileme döngüsü dayanıklılığı (M1), hesap kapatmada kart/abonelik (M2),
 * e-posta işi düşmesi (M4), duyuru işi tekrar güvenliği (M5), bildirim ucu sınırı (M6), bitişten sonra 24 saat deneme (M7),
 * hatırlatma tekrar anahtarı (M8), kart anahtarı şifreleme (L2), kuruluş kartı silmezse yerel kayıt durur (L8).
 */
class PaymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    private StoredCardFakeGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Settings::set('premium_monthly_price', '900');
        config(['services.payment.provider' => 'fake', 'services.payment.vat_rate' => 20]);
        $this->gateway = new StoredCardFakeGateway;
        app(GatewayManager::class)->swap('fake', $this->gateway);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
    }

    // ---- H2: ödendi ama etkinleşmedi ----

    public function test_activation_failure_after_payment_keeps_webhook_ok_alerts_admins_and_reconcile_activates_later(): void
    {
        $driver = $this->driver();
        $order = app(SubscriptionService::class)->startCheckout($driver, 1);
        $this->partialMock(SubscriptionService::class, function ($mock): void {
            $mock->shouldReceive('activate')->once()->andThrow(new RuntimeException('veritabanı koptu'));
        });

        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk()->assertSee('OK');
        $this->assertSame('paid', $order->fresh()->status, 'Para alındı: emir ödendi kalır, bildirim yinelenmez');
        $this->assertSame(0, Subscription::query()->where('user_id', $driver->id)->count());
        $this->assertStringContainsString('Ödeme alındı ama abonelik etkinleştirilemedi', $this->lastNotification($this->admin));
        $this->assertStringContainsString('#'.$order->id, $this->lastNotification($this->admin));

        // Saatlik mutabakat emri yakalar ve etkinleştirir
        $this->app->forgetInstance(SubscriptionService::class);
        \Mockery::close();
        $this->assertSame(1, app(SubscriptionService::class)->reconcilePaidOrders());
        $this->assertSame(1, SubscriptionCycle::query()->where('payment_order_id', $order->id)->count());
        $this->assertTrue($driver->driverProfile->fresh()->isPremium());
        $this->assertSame(0, app(SubscriptionService::class)->reconcilePaidOrders(), 'İkinci çalıştırma tekrar etkinleştirmez');
        $this->artisan('subscriptions:reconcile')->expectsOutputToContain('Mutabakatla etkinleştirilen abonelik emri: 0')->assertExitCode(0);
    }

    public function test_renewal_does_not_charge_again_when_a_paid_renewal_order_was_never_activated(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 1, now()->addHours(60));
        $before = $subscription->current_period_ends_at->copy();
        $orphan = PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'subscription_months' => 1, 'auto_renew' => true, 'stored_card_id' => $card->id,
            'provider' => 'fake', 'merchant_oid' => 'NQS-ORPHAN', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'paid', 'paid_at' => now()->subMinutes(30)]);

        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        $this->assertCount(0, $this->gateway->charges, 'Ödenmiş emir dururken ikinci çekim yapılmaz');
        $this->assertSame(1, SubscriptionCycle::query()->where('payment_order_id', $orphan->id)->count());
        $this->assertEqualsWithDelta($before->copy()->addMonthsNoOverflow(1)->timestamp, $subscription->fresh()->current_period_ends_at->timestamp, 5);
    }

    // ---- H3: kuruluşa ulaşılamadı ----

    public function test_transport_error_does_not_count_as_failure_keeps_order_pending_and_retrieves_before_recharging(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 1, now()->addHours(60));
        $this->gateway->transportError = true;

        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $subscription->refresh();
        $this->assertSame(0, $subscription->renewal_failures, 'Belirsiz sonuç deneme sayılmaz');
        $this->assertTrue($subscription->auto_renew);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $subscription->next_renewal_attempt_at->timestamp, 5);
        $order = PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status, 'Emir açık kalır ki akıbet sorulsun');
        $this->assertSame(1, PaymentEvent::query()->where('payment_order_id', $order->id)->where('status', PaymentService::EVENT_STATUS_UNKNOWN)->count());
        $this->assertStringContainsString('işlem durumu belirsiz', $this->lastNotification($this->admin));
        $this->assertStringContainsString($order->merchant_oid, $this->lastNotification($this->admin));
        $this->assertSame(0, UserNotification::query()->where('user_id', $driver->id)->count(), 'Şoföre "kart reddedildi" denmez');

        // 2 saat sonra: kuruluş "ödeme başarılı" diyor → yeniden çekilmez, emir ödendi olur, dönem uzar
        $this->travel(3)->hours();
        $this->gateway->transportError = false;
        $this->gateway->retrieveResult = new ChargeResult(true, 'fake-charge:'.$order->merchant_oid, ['found' => 1], 900.0, 'fake-renew-found');
        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        $this->assertSame(1, $this->gateway->retrieves);
        $this->assertCount(1, $this->gateway->charges, 'İlk (belirsiz) denemeden başka çekim yok');
        $this->assertSame(['paid', 'fake-renew-found'], [$order->fresh()->status, $order->fresh()->provider_reference]);
        $this->assertSame(1, SubscriptionCycle::query()->where('payment_order_id', $order->id)->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $this->admin->id)->count(), 'Yönetici uyarısı emir başına bir kez');
    }

    public function test_transport_error_then_no_record_at_provider_charges_normally(): void
    {
        $driver = $this->driver();
        $this->renewingSubscription($driver, 1, now()->addHours(60));
        $this->gateway->transportError = true;
        app(SubscriptionService::class)->renewDue();

        $this->travel(3)->hours();
        $this->gateway->transportError = false;
        $this->gateway->retrieveResult = null; // kuruluşta kayıt yok
        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        $this->assertSame(1, $this->gateway->retrieves);
        $this->assertCount(2, $this->gateway->charges);
        $this->assertSame('paid', PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->value('status'));
    }

    // ---- H4 + D: bildirim güvenliği ----

    public function test_second_success_with_a_different_payment_id_on_a_paid_order_alerts_and_refunds_the_duplicate(): void
    {
        $refundable = new RefundableFakeGateway;
        app(GatewayManager::class)->swap('fake', $refundable);
        $driver = $this->driver();
        $order = app(SubscriptionService::class)->startCheckout($driver, 1);
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        $this->assertSame(['paid', 'fake-ref'], [$order->fresh()->status, $order->fresh()->provider_reference]);

        // Aynı bildirimin tekrarı: sessiz, iade yok
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        $this->assertSame(0, $refundable->refunds);

        // Farklı ödeme kimliğiyle ikinci "başarılı": çift tahsilat şüphesi → olay, yönetici, iade denemesi; emir dokunulmaz
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'ref' => 'fake-ref-2'])->assertOk();
        $this->assertSame(['paid', 'fake-ref'], [$order->fresh()->status, $order->fresh()->provider_reference], 'İlk ödeme kaydı değişmez');
        $this->assertSame(1, $refundable->refunds, 'İkinci çekim iade edilir');
        $this->assertSame(1, PaymentEvent::query()->where('payment_order_id', $order->id)->where('event_type', 'duplicate_refund')->where('status', 'success')->count());
        $text = $this->lastNotification($this->admin);
        $this->assertStringContainsString('Çift tahsilat şüphesi', $text);
        $this->assertStringContainsString('emir #'.$order->id, $text);
        $this->assertStringContainsString('fake-ref-2', $text);
        $this->assertSame(1, SubscriptionCycle::query()->where('payment_order_id', $order->id)->count(), 'Abonelik ikinci kez uzatılmaz');
    }

    public function test_webhook_from_another_gateway_cannot_settle_an_order_opened_elsewhere(): void
    {
        $driver = $this->driver();
        $order = app(SubscriptionService::class)->startCheckout($driver, 1);
        $order->update(['provider' => 'iyzico', 'status' => 'pending']);

        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk()->assertSee('FAILED')->assertDontSee('OK');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('payment_order_id', $order->id)->count(), 'Olay bile yazılmaz');
        $this->assertSame(0, Subscription::query()->where('user_id', $driver->id)->count());
    }

    public function test_paytr_webhook_is_invalid_while_the_gateway_is_not_fully_configured(): void
    {
        config(['services.paytr.merchant_key' => 'key', 'services.paytr.merchant_salt' => 'salt', 'services.paytr.merchant_id' => '']);
        $post = ['merchant_oid' => 'NQ1', 'status' => 'success', 'total_amount' => '100000'];
        $post['hash'] = base64_encode(hash_hmac('sha256', 'NQ1salt'.'success'.'100000', 'key', true));
        $request = Request::create('/odeme/bildirim/paytr', 'POST', $post);

        $this->assertFalse(app(PaytrGateway::class)->parseWebhook($request)->valid, 'Üye işyeri numarası yokken imza doğru olsa da geçersiz');
        config(['services.paytr.merchant_id' => '123']);
        $this->assertTrue(app(PaytrGateway::class)->parseWebhook($request)->valid);
    }

    public function test_payment_webhook_route_has_its_own_named_rate_limiter(): void
    {
        $route = Route::getRoutes()->getByName('payment.webhook');
        $this->assertContains('throttle:payment-webhook', $route->middleware());
        $this->assertNotNull(RateLimiter::limiter('payment-webhook'));
    }

    // ---- M1 / M7 / M8: yenileme döngüsü ----

    public function test_renew_loop_survives_an_exception_counts_it_as_a_failure_and_alerts_admins_once_a_day(): void
    {
        $driver = $this->driver();
        [$broken] = $this->renewingSubscription($driver, 1, now()->addHours(50));
        $other = $this->driver();
        [$healthy] = $this->renewingSubscription($other, 1, now()->addHours(60));
        Settings::set('premium_monthly_price', '0'); // priceFor → orderForSubscription "Abonelik ücreti tanımlı değil" (RuntimeException)

        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $this->assertCount(0, $this->gateway->charges);
        foreach ([$broken, $healthy] as $subscription) {
            $subscription->refresh();
            $this->assertSame(1, $subscription->renewal_failures, 'Çekimsiz hata deneme sayılır');
            $this->assertStringContainsString('Sistem hatası', (string) $subscription->last_renewal_error);
            $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $subscription->next_renewal_attempt_at->timestamp, 5);
            $this->assertTrue($subscription->auto_renew);
        }
        $this->assertSame(2, UserNotification::query()->where('user_id', $this->admin->id)->where('title', 'Premium yenilemesi hata verdi')->count(), 'Abonelik başına bir uyarı');

        // Aynı gün tekrar: yeni yönetici uyarısı yok
        Subscription::query()->update(['next_renewal_attempt_at' => null]);
        app(SubscriptionService::class)->renewDue();
        $this->assertSame(2, UserNotification::query()->where('user_id', $this->admin->id)->where('title', 'Premium yenilemesi hata verdi')->count());

        // Fiyat düzelince sağlıklı abonelik yenilenir
        Settings::set('premium_monthly_price', '900');
        Subscription::query()->update(['next_renewal_attempt_at' => null]);
        $this->assertSame(2, app(SubscriptionService::class)->renewDue());
    }

    public function test_renewal_keeps_trying_for_a_day_after_the_period_ended_and_expiry_waits_for_it(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 1, now()->subHours(5));
        $this->gateway->chargeSucceeds = false;

        $this->assertSame(0, app(SubscriptionService::class)->expireDue(), 'Deneme hakkı olan abonelik 24 saat kapanmaz');
        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $this->assertSame(1, $subscription->fresh()->renewal_failures, 'Dönem bitmiş olsa da çekim denenir');

        $this->gateway->chargeSucceeds = true;
        Subscription::query()->update(['next_renewal_attempt_at' => null]);
        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        $this->assertTrue($subscription->fresh()->current_period_ends_at->isFuture(), 'Yetişen çekim dönemi uzatır');
        $this->assertTrue($driver->driverProfile->fresh()->isPremium());
    }

    public function test_expiring_a_subscription_with_auto_renew_still_on_turns_it_off_and_says_so(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 1, now()->subHours(30));
        $driver->driverProfile->update(['premium_until' => now()->subHours(30)]);

        $this->assertSame(1, app(SubscriptionService::class)->expireDue());
        $subscription->refresh();
        $this->assertSame(['expired', false], [$subscription->status, $subscription->auto_renew]);
        $this->assertStringContainsString('Otomatik yenileme kapatıldı', $this->lastNotification($driver));
        $this->assertSame(0, app(SubscriptionService::class)->renewDue(), 'Kapanmış aboneliğe çekim yok');
    }

    public function test_reminder_dedupe_distinguishes_renewing_from_non_renewing(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 1, now()->addDays(2)->setTime(12, 0));
        $subscription->update(['auto_renew' => false]);

        $this->assertSame(1, app(SubscriptionService::class)->remindExpiring(3));
        $this->assertSame('Premium üyeliğiniz yakında sona eriyor', UserNotification::query()->where('user_id', $driver->id)->latest('id')->value('title'));
        $this->assertSame(0, app(SubscriptionService::class)->remindExpiring(3), 'Aynı uyarı tekrar gitmez');

        // Şoför yenilemeyi açtı: çekim tutarını söyleyen bildirim yine gider (bedel önceden bildirilir)
        $subscription->update(['auto_renew' => true]);
        $this->assertSame(1, app(SubscriptionService::class)->remindExpiring(3));
        $text = $this->lastNotification($driver);
        $this->assertStringContainsString('yakında yenilenecek', $text);
        $this->assertStringContainsString('900,00 ₺', $text);
        $this->assertSame(0, app(SubscriptionService::class)->remindExpiring(3));
    }

    // ---- M2 / L2 / L8: kart ve hesap ----

    public function test_account_deletion_removes_stored_cards_at_the_gateway_and_cancels_subscriptions(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 1, now()->addDays(20));

        app(AccountService::class)->deleteAccount($driver, 'test');

        $this->assertSame([$card->id], $this->gateway->deletedCards, 'Kart kuruluştan silinir');
        $this->assertSame(0, StoredCard::query()->where('user_id', $driver->id)->count());
        $subscription->refresh();
        $this->assertSame(['cancelled', false, null], [$subscription->status, $subscription->auto_renew, $subscription->stored_card_id]);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
    }

    public function test_card_user_key_is_stored_encrypted_and_old_plaintext_rows_still_read(): void
    {
        $driver = $this->driver();
        $card = StoredCard::create(['user_id' => $driver->id, 'provider' => 'fake', 'card_user_key' => 'cuk-plain', 'card_token' => 'tok', 'last_four' => '0001']);
        $this->assertSame('cuk-plain', $card->fresh()->card_user_key);
        $this->assertNotSame('cuk-plain', DB::table('stored_cards')->where('id', $card->id)->value('card_user_key'), 'Veritabanında şifreli durur');

        // Eski satır (düz metin) olduğu gibi okunur
        DB::table('stored_cards')->where('id', $card->id)->update(['card_user_key' => 'legacy-key']);
        $this->assertSame('legacy-key', StoredCard::find($card->id)->card_user_key);
    }

    public function test_card_row_is_kept_when_the_gateway_refuses_to_delete_it_but_renewal_stops(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 1, now()->addDays(20));
        $this->gateway->deleteSucceeds = false;

        try {
            app(SubscriptionService::class)->deleteStoredCard($driver, $card);
            $this->fail('kuruluş reddedince hata beklenir');
        } catch (RuntimeException $e) {
            $this->assertSame('Kart silinemedi, tekrar deneyin.', $e->getMessage());
        }
        $this->assertNotNull(StoredCard::find($card->id), 'Yerel kayıt durur: kuruluşta çekim yapabilen kart görünmez kalmaz');
        $this->assertFalse($subscription->fresh()->auto_renew, 'Yenileme yine de kapanır');

        $this->gateway->deleteSucceeds = true;
        app(SubscriptionService::class)->deleteStoredCard($driver, $card);
        $this->assertNull(StoredCard::find($card->id));
    }

    // ---- M4 / M5: bildirim teslimi ----

    public function test_failed_mail_job_marks_the_notification_failed_and_stale_pending_rows_are_retried(): void
    {
        $driver = $this->driver();
        $pending = UserNotification::create(['user_id' => $driver->id, 'type' => 'general', 'title' => 'Deneme', 'lines' => ['x'], 'mail_status' => UserNotification::MAIL_PENDING]);

        (new SendNotificationMail($pending->id))->failed(new RuntimeException('işçi zaman aşımı'));
        $pending->refresh();
        $this->assertSame(UserNotification::MAIL_FAILED, $pending->mail_status);
        $this->assertStringContainsString('işçi zaman aşımı', (string) $pending->mail_error);

        // 15 dakikadan uzun "bekliyor" kalan kayıt (kaybolan kuyruk işi) yeniden denenir; taze bekleyen dokunulmaz
        $stale = UserNotification::create(['user_id' => $driver->id, 'type' => 'general', 'title' => 'Eski', 'lines' => ['x'], 'mail_status' => UserNotification::MAIL_PENDING]);
        $fresh = UserNotification::create(['user_id' => $driver->id, 'type' => 'general', 'title' => 'Yeni', 'lines' => ['x'], 'mail_status' => UserNotification::MAIL_PENDING]);
        UserNotification::query()->whereKey($stale->id)->update(['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);
        UserNotification::query()->whereKey($pending->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->assertSame(2, app(NotificationService::class)->retryFailedMail());
        $this->assertSame(UserNotification::MAIL_SENT, $stale->fresh()->mail_status);
        $this->assertSame(UserNotification::MAIL_SENT, $pending->fresh()->mail_status);
        $this->assertSame(UserNotification::MAIL_PENDING, $fresh->fresh()->mail_status);
    }

    public function test_announcement_job_passes_type_and_skips_recipients_already_notified_on_retry(): void
    {
        $a = $this->driver();
        $b = $this->driver();
        $ids = [$a->id, $b->id];
        UserNotification::create(['user_id' => $a->id, 'type' => 'marketing', 'title' => 'Kampanya', 'lines' => ['ilk deneme'], 'mail_status' => UserNotification::MAIL_SKIPPED]);

        (new SendAnnouncementJob($ids, 'Kampanya', ['%10 indirim'], 'marketing'))->handle(app(NotificationService::class));
        $this->assertSame(1, UserNotification::query()->where('user_id', $a->id)->where('title', 'Kampanya')->count(), 'Son bir saatte almış olan atlanır');
        $created = UserNotification::query()->where('user_id', $b->id)->where('title', 'Kampanya')->firstOrFail();
        $this->assertSame('marketing', $created->type);
        $this->assertSame(UserNotification::MAIL_SKIPPED, $created->mail_status, 'Rızası olmayana pazarlama e-postası gitmez');

        $this->assertSame('general', (new SendAnnouncementJob($ids, 'x', ['y']))->type, 'Varsayılan tür hizmet bildirimi');
    }

    /** Otomatik yenilemesi açık, kartı kayıtlı, dönemi verilen tarihte biten ücretli abonelik. */
    private function renewingSubscription(User $driver, int $months, Carbon $endsAt): array
    {
        $card = StoredCard::create(['user_id' => $driver->id, 'provider' => 'fake', 'card_user_key' => 'cuk-'.$driver->id, 'card_token' => 'tok-'.$driver->id, 'last_four' => '4242', 'card_association' => 'VISA']);
        $subscription = Subscription::create([
            'user_id' => $driver->id, 'plan_code' => SubscriptionService::PLAN_PREMIUM_MONTHLY, 'provider' => 'fake', 'status' => 'active',
            'amount' => 900 * $months, 'currency' => 'TRY', 'interval' => $months === 1 ? 'monthly' : $months.'_months',
            'auto_renew' => true, 'renew_months' => $months, 'stored_card_id' => $card->id,
            'current_period_starts_at' => $endsAt->copy()->subMonthsNoOverflow($months), 'current_period_ends_at' => $endsAt,
        ]);
        SubscriptionCycle::create(['subscription_id' => $subscription->id, 'period_start' => $subscription->current_period_starts_at, 'period_end' => $endsAt, 'amount' => 900 * $months, 'currency' => 'TRY', 'status' => 'paid', 'paid_at' => now()]);
        $driver->driverProfile->update(['premium_until' => $endsAt]);

        return [$subscription, $card];
    }

    private function lastNotification(User $user): string
    {
        return (string) json_encode(UserNotification::query()->where('user_id', $user->id)->latest('id')->first()?->toArray() ?? [], JSON_UNESCAPED_UNICODE);
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'identity_number' => '10000000146', 'trial_started_at' => now()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'AR'.random_int(10, 99), 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }
}
