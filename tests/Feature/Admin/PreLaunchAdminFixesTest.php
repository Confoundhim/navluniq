<?php

namespace Tests\Feature\Admin;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\Load;
use App\Models\Offer;
use App\Models\PaymentOrder;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\SettingRevision;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\PaymentReadiness;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Canlıya geçiş denetimi (yönetici paneli, 2026-10-10): askıya alma şoförün işini kapatır, ödeme kipi geri alınamaz, engelleme oturumu düşürür… */
class PreLaunchAdminFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->admin = User::factory()->create(['current_role' => 'admin', 'password' => 'Sifre12345!']);
        $this->admin->syncRoles(['super_admin']);
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
    }

    public function test_reauth_keys_are_not_rollbackable_but_plain_keys_are(): void
    {
        $this->assertFalse(Settings::isRollbackable('freight_payment_mode'));
        $this->assertFalse(Settings::isRollbackable('payment_provider'));
        $this->assertFalse(Settings::isRollbackable('iyzico_marketplace'));
        $this->assertTrue(Settings::isRollbackable('scraper_list_days'));
        $this->assertTrue(Settings::isRollbackable('commission_standard_driver'));

        Settings::set('freight_payment_mode', 'direct');
        $rev = SettingRevision::create(['user_id' => $this->admin->id, 'key' => 'freight_payment_mode', 'setting_label' => 'Navlun ödeme yolu', 'old_value' => 'platform', 'new_value' => 'direct']);
        Volt::test('admin.rollback-center')->set('activeTab', 'revisions')->call('rollback', $rev->id)->assertSee('geri alınamaz');
        $this->assertSame('direct', Settings::string('freight_payment_mode'));
    }

    public function test_admin_suspend_closes_driver_trip_and_notifies_driver(): void
    {
        Settings::set('freight_payment_mode', 'direct');
        $driver = User::factory()->driver()->create();
        $driver->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        $ownerUser = User::factory()->create(['current_role' => 'cargo_owner']);
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual', 'kyc_status' => 'approved']);
        $load = Load::create([
            'cargo_owner_profile_id' => $owner->id, 'driver_profile_id' => $profile->id, 'source_type' => 'internal', 'visibility' => 'private',
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
            'pickup_date' => now()->addDays(3), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ASSIGNED, 'escrow_status' => Load::ESCROW_DIRECT, 'published_at' => now(),
        ]);
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $profile->id, 'amount' => 42000, 'currency' => 'TRY', 'status' => 'accepted']);
        $shipment = Shipment::create(['load_id' => $load->id, 'accepted_offer_id' => $offer->id, 'driver_profile_id' => $profile->id, 'status' => Shipment::STATUS_AWAITING_PICKUP]);
        $trip = DriverTrip::create(['driver_profile_id' => $profile->id, 'source' => 'system', 'load_id' => $load->id, 'shipment_id' => $shipment->id,
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
            'pickup_date' => now()->addDays(3), 'delivery_date' => now()->addDays(4), 'status' => DriverTrip::STATUS_PLANNED]);

        Volt::test('admin.operations-center')->call('select', $load->id)->set('suspendReason', 'Kural dışı ilan metni')->call('suspend')
            ->assertSee('İlan iptal edildi');

        $this->assertSame(Load::STATUS_CANCELLED, $load->fresh()->status);
        $this->assertSame(Shipment::STATUS_CANCELLED, $shipment->fresh()->status);
        $this->assertSame(DriverTrip::STATUS_CLOSED, $trip->fresh()->status, 'Şoförün açık işi kapanmalı');
        $this->assertSame('rejected', $offer->fresh()->status);
        $this->assertTrue(UserNotification::query()->where('user_id', $driver->id)->where('title', 'İlan iptal edildi')->exists(), 'Şoföre bildirim gitmeli');
        $this->assertTrue(UserNotification::query()->where('user_id', $ownerUser->id)->where('title', 'İlanınız yönetici tarafından kaldırıldı')->exists());
        $this->assertDatabaseHas('activity_logs', ['action' => 'load.suspended', 'subject_id' => $load->id]);
    }

    public function test_ban_ends_sessions_and_rotates_remember_token(): void
    {
        $owner = User::factory()->create(['current_role' => 'cargo_owner', 'remember_token' => 'eski-token']);
        $owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'kyc_status' => 'approved']);
        DB::table('sessions')->insert(['id' => 'oturum-1', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'oturum-2', 'user_id' => $this->admin->id, 'payload' => 'x', 'last_activity' => time()]);

        Volt::test('admin.users-center')->set('banReason', 'Sahte ilan')->call('prepareBan', $owner->id);

        $this->assertNotNull($owner->fresh()->banned_at);
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'oturum-2']);
        $this->assertNotSame('eski-token', $owner->fresh()->remember_token);
    }

    public function test_staff_deactivation_ends_sessions(): void
    {
        $staff = User::factory()->create(['current_role' => 'admin', 'is_active' => true, 'remember_token' => 'eski-token']);
        $staff->syncRoles(['support_agent']);
        DB::table('sessions')->insert(['id' => 'oturum-p', 'user_id' => $staff->id, 'payload' => 'x', 'last_activity' => time()]);

        Volt::test('admin.staff-center')->call('toggleActive', $staff->id);

        $this->assertFalse((bool) $staff->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);
        $this->assertNotSame('eski-token', $staff->fresh()->remember_token);
    }

    public function test_select_page_only_selects_visible_page(): void
    {
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        for ($i = 1; $i <= 25; $i++) {
            ScrapedLoad::create([
                'scraper_id' => 1, 'content_hash' => 'h'.$i, 'raw_message' => 'm'.$i, 'sender_phone' => '0555111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
                'status' => 'parsed_success', 'visibility' => 'private', 'retention_expires_at' => now()->addDays(3),
            ]);
        }

        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'queue')->set('selectPage', true);
        $this->assertCount(20, $c->get('selected'), 'Yalnız görünen sayfa seçilmeli');

        $c->call('selectAllMatching');
        $this->assertCount(25, $c->get('selected'));
    }

    public function test_ops_settings_saved_only_by_manage_system(): void
    {
        Volt::test('admin.settings-center')->set('activeTab', 'ops')
            ->assertSee('İşletim ve uyarılar')
            ->set('ops.alert_telegram_chat_id', 'abc')->call('saveOps')->assertHasErrors(['ops.alert_telegram_chat_id'])
            ->set('ops.alert_telegram_chat_id', '-1001234')->set('ops.csp_enforce', '1')->set('ops.intake_silence_alert_hours', '5')->call('saveOps')->assertHasNoErrors();
        $this->assertSame('-1001234', Settings::string('alert_telegram_chat_id'));
        $this->assertTrue(Settings::bool('csp_enforce'));
        $this->assertSame(5, Settings::int('intake_silence_alert_hours'));

        $staff = User::factory()->create(['current_role' => 'admin']);
        $staff->syncRoles(['financial_officer']);
        $staff->givePermissionTo('manage settings');
        $this->actingAs($staff->fresh());
        Volt::test('admin.settings-center')->set('activeTab', 'ops')->set('ops.intake_silence_alert_hours', '9')->call('saveOps')->assertSee('yalnız sistem yönetimi');
        $this->assertSame(5, Settings::int('intake_silence_alert_hours'));
    }

    public function test_direct_mode_readiness_does_not_warn_about_marketplace(): void
    {
        Settings::set('freight_payment_mode', 'direct');
        $byLabel = collect(PaymentReadiness::checks())->keyBy('label');
        $this->assertTrue($byLabel['Pazaryeri (alt üye işyeri) aktarımı']['ok']);
        $this->assertTrue($byLabel['Alt üye işyeri kaydı']['ok']);

        Settings::set('freight_payment_mode', 'platform');
        $this->assertStringContainsString('Pazaryeri ürünü olan', (string) collect(PaymentReadiness::checks())->keyBy('label')['Pazaryeri (alt üye işyeri) aktarımı']['fix']);
    }

    public function test_status_labels_are_turkish(): void
    {
        $order = new PaymentOrder(['status' => 'refund_pending', 'purpose' => 'subscription']);
        $this->assertSame('İade bekliyor', $order->statusLabel());
        $this->assertSame('Premium üyelik', $order->purposeLabel());
        $this->assertSame('Yolda', (new Shipment(['status' => Shipment::STATUS_IN_TRANSIT]))->statusLabel());
    }
}
