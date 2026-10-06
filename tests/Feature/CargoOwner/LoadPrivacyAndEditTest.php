<?php

namespace Tests\Feature\CargoOwner;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\SavedAddress;
use App\Models\User;
use App\Services\LoadService;
use App\Services\TelegramPublisher;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Paket 3 (2026-10-06, plan E6/E7): il/ilçe seçici, gizli açık adres ve şoföre not; ilan düzenleme; tarihli "Tekrar yayınla".
 */
class LoadPrivacyAndEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Settings::set('min_load_price', '1000');
        $this->owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $this->owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $this->owner->id, 'type' => 'individual']);
        $this->owner = $this->owner->fresh();
    }

    public function test_place_picker_publish_fills_codes_and_private_address_stays_hidden_from_the_pool(): void
    {
        $load = app(LoadService::class)->publish($this->owner->cargoOwnerProfile, $this->payload([
            'pickup_address_private' => 'Ostim OSB 1234. Cadde No:12 Kapı 3',
            'delivery_address_private' => 'Aliağa OSB 4. Sokak No:5',
            'pickup_contact_name' => 'Depo Sorumlusu',
            'pickup_contact_phone' => '0312 123 45 67',
            'notes' => 'Forklift var, sabah 08:00 yükleme.',
        ]));

        $this->assertSame('Ankara Yenimahalle', $load->pickup_location);
        $this->assertSame('İzmir Aliağa', $load->delivery_location);
        $this->assertSame(6, (int) $load->pickup_province_code);
        $this->assertSame('Yenimahalle', $load->pickup_district);
        $this->assertSame(35, (int) $load->delivery_province_code);
        $this->assertSame('Aliağa', $load->delivery_district);
        $this->assertNotNull($load->pickup_lat);
        $this->assertNotNull($load->delivery_lng);
        $this->assertSame('3121234567', $load->pickup_contact_phone, 'Sabit hat ilan iletişim numarası olarak saklanır (sıfırsız)');
        $this->assertSame('Ankara Yenimahalle → İzmir Aliağa', $load->publicRoute());

        // Havuzdaki (premium) şoför yalnız il/ilçe görür; açık adres, not ve yetkili HTML'de geçmez.
        $driver = $this->driver(premium: true);
        $this->actingAs($driver);
        Volt::test('driver.loads.index')
            ->assertSee('Ankara Yenimahalle')
            ->assertDontSee('Ostim OSB')
            ->assertDontSee('Aliağa OSB')
            ->assertDontSee('Forklift var')
            ->assertDontSee('Depo Sorumlusu');
        $this->assertNull($load->privateAddressFor($driver));
        $this->assertNull($load->notesFor($driver));
        $this->assertNull($load->pickupContactFor($driver));
        $this->assertNull($load->privateAddressFor(null));

        // Telegram mesajında da yalnız il/ilçe
        $message = app(TelegramPublisher::class)->messageForLoad($load);
        $this->assertStringContainsString('Ankara Yenimahalle → İzmir Aliağa', $message);
        $this->assertStringNotContainsString('Ostim', $message);
        $this->assertStringNotContainsString('Forklift', $message);

        // Yük sahibi her zaman görür
        $this->assertSame('Ostim OSB 1234. Cadde No:12 Kapı 3', $load->privateAddressFor($this->owner)['pickup']);
        $this->assertSame('Forklift var, sabah 08:00 yükleme.', $load->notesFor($this->owner));

        // Atanan şoför ödeme alınmadan görmez, ödeme alınınca görür
        $load->update(['driver_profile_id' => $driver->driverProfile->id, 'status' => Load::STATUS_ASSIGNED, 'escrow_status' => Load::ESCROW_PENDING]);
        $this->assertNull($load->fresh()->privateAddressFor($driver));
        $load->update(['escrow_status' => Load::ESCROW_PAID]);
        $paid = $load->fresh();
        $this->assertSame(['pickup' => 'Ostim OSB 1234. Cadde No:12 Kapı 3', 'delivery' => 'Aliağa OSB 4. Sokak No:5'], $paid->privateAddressFor($driver));
        $this->assertSame('Forklift var, sabah 08:00 yükleme.', $paid->notesFor($driver));
        $this->assertSame(['name' => 'Depo Sorumlusu', 'phone' => '3121234567'], $paid->pickupContactFor($driver));

        // Başka şoför ödeme sonrasında da görmez; yönetici görür
        $other = $this->driver(premium: false);
        $this->assertNull($paid->privateAddressFor($other));
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->assertNotNull($paid->privateAddressFor($admin->fresh()));

        // Yük sahibinin sevkiyat sayfasında açık adres ve not görünür
        $this->actingAs($this->owner);
        $this->get(route('cargo-owner.shipments.show', $load->id))->assertOk()->assertSee('Ostim OSB 1234')->assertSee('Forklift var');
    }

    public function test_legacy_free_text_route_still_resolves_without_picker_codes(): void
    {
        $load = app(LoadService::class)->publish($this->owner->cargoOwnerProfile, [
            'pickup_location' => 'Ostim OSB 1234. Cadde Yenimahalle / Ankara', 'delivery_location' => 'Aliağa / İzmir',
            'pickup_date' => now()->addDay()->toDateString(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 24000, 'price' => 45000,
        ]);

        $this->assertSame(6, (int) $load->pickup_province_code);
        $this->assertSame(35, (int) $load->delivery_province_code);
        $this->assertSame('Ankara Yenimahalle', $load->publicPickup(), 'Eski ilanın herkese açık etiketi de yalnız il/ilçe');
        $this->assertNull($load->pickup_address_private);
    }

    public function test_wizard_validates_picker_and_saved_address_fills_it(): void
    {
        $this->actingAs($this->owner);
        SavedAddress::create([
            'user_id' => $this->owner->id, 'title' => 'Merkez depo', 'contact_person' => 'Depo Sorumlusu', 'contact_phone' => '5321002030',
            'city' => 'Ankara', 'district' => 'Yenimahalle', 'address_detail' => 'Ostim OSB 1234. Cadde No:12', 'type' => 'both', 'is_default' => true,
        ]);

        $c = Volt::test('cargo-owner.loads.create')
            ->set('pickup_date', now()->addDay()->format('Y-m-d'))
            ->call('nextStep')->assertHasErrors(['pickup_province_code', 'delivery_province_code'])->assertSet('currentStep', 1)
            ->set('selected_saved_pickup', (string) SavedAddress::first()->id)
            ->assertSet('pickup_province_code', '6')
            ->assertSet('pickup_district', 'Yenimahalle')
            ->assertSet('pickup_address_private', 'Ostim OSB 1234. Cadde No:12')
            ->assertSet('pickup_contact_name', 'Depo Sorumlusu')
            ->set('delivery_province_code', '35')->set('delivery_district', 'Aliağa')
            ->set('pickup_contact_phone', 'abc')
            ->call('nextStep')->assertHasErrors(['pickup_contact_phone'])
            ->set('pickup_contact_phone', '0532 100 20 30')
            ->call('nextStep')->assertHasNoErrors()->assertSet('currentStep', 2)
            ->set('weight', '24000')->set('notes', str_repeat('a', Load::NOTES_MAX + 1))
            ->call('nextStep')->assertHasErrors(['notes'])
            ->set('notes', 'Palet 20 adet')
            ->call('nextStep')->assertHasNoErrors()->assertSet('currentStep', 3)
            ->assertSee('Ankara Yenimahalle')->assertSee('İzmir Aliağa')
            ->set('price', '18500')->set('terms_accepted', true)
            ->call('submitLoad')->assertHasNoErrors();

        $load = Load::query()->firstOrFail();
        $this->assertSame('Ankara Yenimahalle', $load->pickup_location);
        $this->assertSame('Palet 20 adet', $load->notes);
        $this->assertSame('5321002030', $load->pickup_contact_phone);
        // İl değişince ilçe sıfırlanır
        Volt::test('cargo-owner.loads.create')->set('pickup_province_code', '6')->set('pickup_district', 'Yenimahalle')
            ->set('pickup_province_code', '35')->assertSet('pickup_district', '');
    }

    public function test_owner_edits_everything_without_offers_but_only_private_fields_with_a_pending_offer(): void
    {
        $this->actingAs($this->owner);
        $load = app(LoadService::class)->publish($this->owner->cargoOwnerProfile, $this->payload());
        $service = app(LoadService::class);

        $changed = $service->update($load, $this->owner->cargoOwnerProfile, $this->payload([
            'delivery_province_code' => '16', 'delivery_district' => 'Gemlik', 'price' => 50000, 'notes' => 'Rampa var',
        ]));
        $load->refresh();
        $this->assertSame('Bursa Gemlik', $load->delivery_location);
        $this->assertSame(16, (int) $load->delivery_province_code);
        $this->assertSame(50000.0, (float) $load->price);
        $this->assertSame('Rampa var', $load->notes);
        $this->assertSame(Load::STATUS_ACTIVE, $load->status);
        $this->assertSame('public', $load->visibility);
        $this->assertContains('price', $changed);
        $this->assertTrue(ActivityLog::query()->where('action', 'load.updated')->exists());

        // Teklif gelince rota/araç/bedel kilitlenir, tarih / adres / not değişir
        $driver = $this->driver(premium: true);
        Offer::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 48000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $service->update($load, $this->owner->cargoOwnerProfile, $this->payload([
            'delivery_province_code' => '35', 'price' => 60000, 'vehicle_type' => 'kamyonet',
            'notes' => 'Saat 09:00', 'pickup_address_private' => 'Yeni kapı no 7', 'pickup_date' => now()->addDays(3)->startOfDay(),
        ]));
        $load->refresh();
        $this->assertSame('Bursa Gemlik', $load->delivery_location, 'Teklif varken rota değişmez');
        $this->assertSame(50000.0, (float) $load->price, 'Teklif varken bedel değişmez');
        $this->assertSame('tir', $load->vehicle_type);
        $this->assertSame('Saat 09:00', $load->notes);
        $this->assertSame('Yeni kapı no 7', $load->pickup_address_private);
        $this->assertTrue($load->pickup_date->isSameDay(now()->addDays(3)));

        // Düzenleme ekranı: teklif varken sınırlı
        Volt::test('cargo-owner.loads.edit', ['loadId' => $load->id])
            ->assertSet('restricted', true)->assertSee('teklif geldi')
            ->assertSet('delivery_province_code', '16')->assertSet('notes', 'Saat 09:00')
            ->set('notes', 'Saat 10:00')->call('save')->assertHasNoErrors()->assertRedirect(route('cargo-owner.loads.index'));
        $this->assertSame('Saat 10:00', $load->fresh()->notes);

        // İlanlarım'da Düzenle düğmesi
        Volt::test('cargo-owner.loads.index')->assertSee('Düzenle')->assertSee(route('cargo-owner.loads.edit', $load->id));

        // Şoför atanmış ilan düzenlenemez
        $load->update(['status' => Load::STATUS_ASSIGNED, 'driver_profile_id' => $driver->driverProfile->id]);
        $this->expectException(\RuntimeException::class);
        $service->update($load->fresh(), $this->owner->cargoOwnerProfile, ['notes' => 'x']);
    }

    public function test_edit_page_refuses_foreign_or_assigned_load(): void
    {
        $this->actingAs($this->owner);
        $load = app(LoadService::class)->publish($this->owner->cargoOwnerProfile, $this->payload());
        $load->update(['status' => Load::STATUS_COMPLETED]);
        Volt::test('cargo-owner.loads.edit', ['loadId' => $load->id])->assertRedirect(route('cargo-owner.loads.index'));

        $stranger = User::factory()->create(['current_role' => 'cargo_owner']);
        $stranger->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $stranger->id, 'type' => 'individual']);
        $this->actingAs($stranger->fresh());
        $load->update(['status' => Load::STATUS_ACTIVE]);
        Volt::test('cargo-owner.loads.edit', ['loadId' => $load->id])->assertRedirect(route('cargo-owner.loads.index'));
    }

    public function test_repeat_asks_for_a_date_and_carries_private_fields(): void
    {
        $this->actingAs($this->owner);
        $load = app(LoadService::class)->publish($this->owner->cargoOwnerProfile, $this->payload([
            'pickup_address_private' => 'Ostim OSB No:12', 'notes' => 'Forklift var', 'pickup_contact_name' => 'Depo', 'pickup_contact_phone' => '05321002030',
        ]));
        $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASED]);

        $date = now()->addDays(5)->format('Y-m-d');
        Volt::test('cargo-owner.loads.index')->set('activeTab', 'past')
            ->call('openRepeat', $load->id)->assertSet('repeatLoadId', $load->id)->assertSet('repeat_pickup_date', now()->addDay()->format('Y-m-d'))
            ->set('repeat_pickup_date', now()->subDay()->format('Y-m-d'))->call('repeatLoad')->assertHasErrors(['repeat_pickup_date'])
            ->set('repeat_pickup_date', $date)->call('repeatLoad')->assertHasNoErrors()->assertSet('repeatLoadId', null);

        $new = Load::query()->latest('id')->first();
        $this->assertNotSame($load->id, $new->id);
        $this->assertSame($date, $new->pickup_date->format('Y-m-d'));
        $this->assertSame(Load::STATUS_ACTIVE, $new->status);
        $this->assertSame('Ankara Yenimahalle', $new->pickup_location);
        $this->assertSame(6, (int) $new->pickup_province_code);
        $this->assertSame('Ostim OSB No:12', $new->pickup_address_private);
        $this->assertSame('Forklift var', $new->notes);
        $this->assertSame('5321002030', $new->pickup_contact_phone);
    }

    public function test_address_book_uses_the_picker_and_stores_province_name(): void
    {
        $this->actingAs($this->owner);
        Volt::test('cargo-owner.address-book.index')->call('openCreate')
            ->set('title', 'Gemlik depo')->set('contact_person', 'Sorumlu Kişi')->set('contact_phone', '0532 100 20 30')
            ->set('province_code', '16')->set('district', 'Yok Böyle İlçe')->set('address_detail', 'Liman yolu No:1 depo 4')
            ->call('save')->assertHasErrors(['district'])
            ->set('district', 'Gemlik')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('saved_addresses', ['title' => 'Gemlik depo', 'city' => 'Bursa', 'district' => 'Gemlik']);
        Volt::test('cargo-owner.address-book.index')->call('openEdit', SavedAddress::first()->id)
            ->assertSet('province_code', '16')->assertSet('district', 'Gemlik');
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'pickup_province_code' => '6', 'pickup_district' => 'Yenimahalle',
            'delivery_province_code' => '35', 'delivery_district' => 'Aliağa',
            'pickup_date' => now()->addDay()->startOfDay(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 24000, 'price' => 45000,
        ], $extra);
    }

    private function driver(bool $premium): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'XY'.random_int(10, 99), 'brand' => 'Ford', 'model' => 'Cargo', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }
}
