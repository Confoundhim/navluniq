<?php

namespace Tests\Feature\Loads;

use App\Mail\SystemNoticeMail;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LoadReleaseService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PremiumReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $premium;

    private User $free;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Settings::set('scraper_free_delay_minutes', '20');
        Settings::set('min_load_price', '1000');

        $this->owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $this->owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $this->owner->id, 'type' => 'individual']);

        $this->premium = $this->driver(premium: true);
        $this->free = $this->driver(premium: false);
    }

    private function driver(bool $premium): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'XY'.random_int(10, 99), 'brand' => 'Ford', 'model' => 'Cargo', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function publish(array $extra = []): Load
    {
        return app(LoadService::class)->publish($this->owner->fresh()->cargoOwnerProfile, array_merge([
            'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir', 'pickup_date' => now()->addDay()->toDateString(),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 24000, 'price' => 45000,
        ], $extra));
    }

    public function test_new_system_load_is_premium_first_then_released_to_everyone_and_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        Settings::set('telegram_post_enabled', '1');
        Settings::set('telegram_bot_token', '123456:ABCDEF');
        Settings::set('telegram_channel_id', '@navluniq');

        $load = $this->publish();
        $this->assertTrue($load->isEarlyAccess());
        $this->assertEqualsWithDelta(20, now()->diffInMinutes($load->available_to_free_at), 1);

        // Premium şoför anında bildirim alır ve ilanı görür; ücretsiz şoför ne bildirim alır ne ilanı görür.
        $this->assertSame(1, UserNotification::where('user_id', $this->premium->id)->count());
        $this->assertStringContainsString('Erken erişim', UserNotification::where('user_id', $this->premium->id)->first()->title);
        $this->assertSame(0, UserNotification::where('user_id', $this->free->id)->count());
        $this->assertSame(1, Load::query()->openTo($this->premium->driverProfile)->count());
        $this->assertSame(0, Load::query()->openTo($this->free->driverProfile)->count());
        Http::assertNothingSent();

        $this->actingAs($this->free);
        Volt::test('driver.loads.index')->assertDontSee('Paletli Yük')->assertSee('önce premium üyelere açılır');
        try {
            app(OfferService::class)->submit($this->free->driverProfile, $load, 44000);
            $this->fail('Ücretsiz şoför erken erişimde teklif verememeli');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('erken erişimde', $e->getMessage());
        }

        $this->actingAs($this->premium);
        $pf = $this->premium->driverProfile;
        Volt::test('driver.loads.index')->assertSee('Paletli Yük')->assertSee('Erken erişim');

        // Süre dolunca: herkese açılır, ücretsiz şoföre bildirim, Telegram'a tek mesaj.
        $this->assertSame(0, app(LoadReleaseService::class)->releaseDue(), 'Süre dolmadan açılmamalı');
        $this->travel(21)->minutes();
        $this->assertSame(1, app(LoadReleaseService::class)->releaseDue());
        $this->assertSame(0, app(LoadReleaseService::class)->releaseDue(), 'İkinci kez işlenmemeli');

        $load->refresh();
        $this->assertNotNull($load->released_at);
        $this->assertNotNull($load->telegram_posted_at);
        $this->assertSame(1, UserNotification::where('user_id', $this->free->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->premium->id)->count(), 'Premium ikinci kez bildirilmez');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'bot123456:ABCDEF/sendMessage')
            && $r['chat_id'] === '@navluniq'
            && str_contains($r['text'], 'Ankara → İzmir')
            && str_contains($r['text'], '45.000 ₺')
            && str_contains($r['text'], 'ilan-havuzu?ilan='.$load->id));

        $this->actingAs($this->free);
        $this->assertSame(1, Load::query()->openTo($this->free->driverProfile)->count());
        app(OfferService::class)->submit($this->free->driverProfile, $load->fresh(), 44000);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_premium_driver_gets_mail_unless_switched_off_in_panel(): void
    {
        Mail::fake();
        Http::fake();

        $this->publish();
        $n = UserNotification::where('user_id', $this->premium->id)->latest('id')->first();
        $this->assertSame('sent', $n->mail_status, 'Premium şoföre e-posta gider');
        $this->assertContains(LoadReleaseService::MAIL_OPT_OUT_LINE, $n->lines);
        Mail::assertSent(SystemNoticeMail::class, 1);

        // Şoför Premium sayfasından e-postayı kapatır: yeni ilanda yalnız uygulama içi bildirim.
        $this->actingAs($this->premium);
        Volt::test('driver.premium.index')->assertSee('E-posta: açık')->call('toggleLoadMail')->assertSee('E-posta: kapalı');
        $this->assertFalse(LoadReleaseService::wantsLoadMail($this->premium->driverProfile->fresh()));

        $this->publish();
        $n2 = UserNotification::where('user_id', $this->premium->id)->latest('id')->first();
        $this->assertSame('skipped', $n2->mail_status);
        $this->assertNotContains(LoadReleaseService::MAIL_OPT_OUT_LINE, $n2->lines);
        Mail::assertSent(SystemNoticeMail::class, 1);

        // Ücretsiz şoföre herkese açılışta e-posta gitmez (yalnız uygulama içi).
        Settings::set('scraper_free_delay_minutes', '0');
        $this->publish();
        $this->assertSame('skipped', UserNotification::where('user_id', $this->free->id)->latest('id')->first()->mail_status);
    }

    public function test_zero_delay_releases_immediately_without_telegram_when_disabled(): void
    {
        Settings::set('scraper_free_delay_minutes', '0');
        Http::fake();
        $load = $this->publish();
        $this->assertNotNull($load->fresh()->released_at);
        $this->assertSame(1, UserNotification::where('user_id', $this->free->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->premium->id)->count(), 'Gecikme yoksa yalnız herkese açılış bildirimi (ücretsiz)');
        Http::assertNothingSent();
    }

    public function test_driver_with_too_small_vehicle_is_not_notified(): void
    {
        $small = User::factory()->driver()->create();
        $small->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $small->id, 'kyc_status' => 'approved', 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34KUC01', 'brand' => 'Fiat', 'model' => 'Doblo', 'vehicle_type' => 'minivan', 'is_active' => true]);

        $this->publish();
        $this->assertSame(0, UserNotification::where('user_id', $small->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->premium->id)->count());
    }

    public function test_driver_whose_body_does_not_match_is_not_notified(): void
    {
        $damper = User::factory()->driver()->create();
        $damper->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $damper->id, 'kyc_status' => 'approved', 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34DMP01', 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'body_type' => 'damperli', 'is_active' => true]);

        $this->publish(['body_types' => ['tenteli', 'kapali'], 'load_kind' => 'komple']);
        $this->assertSame(0, UserNotification::where('user_id', $damper->id)->count(), 'Damperli araç tenteli yükü almaz');
        $n = UserNotification::where('user_id', $this->premium->id)->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Tenteli / Kapalı', implode(' ', (array) $n->lines));
        $this->assertStringContainsString('Komple yük', implode(' ', (array) $n->lines));
    }

    public function test_external_loads_are_visible_only_to_premium_drivers(): void
    {
        $scraper = Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'content_hash' => hash('sha256', 'x'), 'raw_message' => 'Bursa Konya 10 ton 0532 123 45 67',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Bursa', 'delivery_location' => 'Konya', 'pickup_province_code' => 16, 'delivery_province_code' => 42,
            'weight' => 10000, 'status' => 'parsed_success', 'visibility' => 'public', 'parsed_by_llm' => 'regex_verified',
        ]);

        $this->actingAs($this->free);
        Volt::test('driver.loads.index')->set('tab', 'external')
            ->assertSee('premium üyelere özeldir')->assertDontSee('0532 123 45 67')->assertDontSee('Gruptan derlendi');

        $this->actingAs($this->premium);
        Volt::test('driver.loads.index')->set('tab', 'external')
            ->assertSee('Gruptan derlendi')->assertSee('0532 123 45 67')->assertDontSee('***')
            ->assertSee('https://wa.me/905321234567?text=', false)->assertSee(rawurlencode('NavlunIQ platformunda belgeleri onaylanmış'), false);

        // Hazır mesaj panelden düzenlenir; yer tutucular ilan ve şoför bilgisiyle dolar, boş şablon mesajsız açar.
        $load = ScrapedLoad::first();
        Settings::set('scraper_contact_message', '{ad} - {arac} - {rota} - {yuk}');
        $msg = $load->contactMessage($this->premium);
        $this->assertStringContainsString($this->premium->full_name, $msg);
        $this->assertStringContainsString('TIR 06', $msg);
        $this->assertStringContainsString('Bursa → Konya', $msg);
        $this->assertStringContainsString('10 ton', $msg);
        Settings::set('scraper_contact_message', '-');
        $this->assertNull($load->contactMessage($this->premium));
        $this->assertSame('https://wa.me/905321234567', $load->whatsappUrl('5321234567', $this->premium));
    }
}
