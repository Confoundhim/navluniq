<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\DriverTripService;
use App\Services\LoadFilterService;
use App\Support\Geo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Paket 4 (2026-10-06, S2): tek sistem ilanı kartı üç ekranda aynı rozetleri basar; mesafe ve ₺/km; paylaşım metni;
 * benzer ilan listesi.
 */
class SystemLoadCardTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private const ANKARA = [39.9334, 32.8597];

    private const IZMIR = [38.4237, 27.1428];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
    }

    private function driver(bool $premium = true): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06KRT'.(++self::$seq), 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'trailer_length' => 'uzun', 'is_active' => true]);

        return $user->fresh();
    }

    private function owner(bool $verified = true): CargoOwnerProfile
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Ayşe', 'last_name' => 'Yılmazkaya']);

        return CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual', 'kyc_status' => 'approved', 'nvi_verified' => $verified]);
    }

    /** Ankara → İzmir, koordinatlı, premium erken erişim penceresinde. */
    private function load(CargoOwnerProfile $owner, array $o = []): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara Sincan', 'pickup_province_code' => 6, 'pickup_lat' => self::ANKARA[0], 'pickup_lng' => self::ANKARA[1],
            'delivery_location' => 'İzmir Aliağa', 'delivery_province_code' => 35, 'delivery_lat' => self::IZMIR[0], 'delivery_lng' => self::IZMIR[1],
            'pickup_date' => now()->addDays(2)->setTime(9, 30), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli yük',
            'weight' => 24000, 'price' => 45000, 'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING,
            'published_at' => now(), 'available_to_free_at' => now()->addMinutes(20),
        ], $o));
    }

    private function scraped(array $o = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => 1, 'content_hash' => 'h'.(++self::$seq), 'raw_message' => 'Ankara İzmir tenteli tır 0532 111 22 33 Mehmet',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'pickup_lat' => self::ANKARA[0], 'pickup_lng' => self::ANKARA[1],
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'delivery_lat' => self::IZMIR[0], 'delivery_lng' => self::IZMIR[1],
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli', 'uzun_dorse'], 'goods_type' => 'Mermer',
            'price' => 45000, 'status' => 'parsed_success', 'visibility' => 'public', 'retention_expires_at' => now()->addDays(30),
        ], $o));
    }

    /** Aynı kartın parmak izi: rozetler, tarih biçimi, yük sahibi adı, mesafe satırı ve eylemler. */
    private function cardMarks(Load $load): array
    {
        return [
            'NavlunIQ ilanı', '✓ Doğrulanmış yük sahibi', '⭐ Erken erişim', 'Ayşe Y.',
            'Yükleme: '.$load->pickup_date->format('d.m.Y H:i'), $load->distanceLabel(), '45.000 ₺', 'Paylaş', 'Teklif ver',
            'TIR · Tenteli', '24 ton',
        ];
    }

    public function test_dashboard_pool_and_return_load_list_render_the_same_system_card(): void
    {
        $driver = $this->driver();
        $load = $this->load($this->owner());
        $this->assertNotNull($load->distanceLabel());

        // Dönüş yükü listesi için: varışı Ankara olan açık bir gruptan iş (ilanın kalkışı Ankara)
        $trip = app(DriverTripService::class)->takeExternal($driver->driverProfile, $this->scraped([
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
        ]), now(), now()->addDay());
        $this->assertCount(1, app(DriverTripService::class)->returnLoadsFor($trip->fresh())['system']);

        $this->actingAs($driver);
        $marks = $this->cardMarks($load);
        Volt::test('driver.dashboard')->assertSee('Size uygun ilanlar')->assertSee($marks)->assertDontSee('Sistem ilanı');
        Volt::test('driver.loads.index')->assertSee($marks)->assertDontSee('Sistem ilanı');
        Volt::test('driver.jobs.index')->call('toggleReturnLoads', $trip->id)->assertSee($marks)->assertSee('Dönüş yükü');

        // Kaydedilenler sekmesi de aynı kartı basar ve "Kaydedildi" notu eklenir
        Volt::test('driver.loads.index')->call('toggleSave', 'system', $load->id)->call('setTab', 'saved')->assertSee($marks)->assertSee('Kaydedildi:');

        // Doğrulanmamış yük sahibine olumsuz damga yok; yalnız rozet düşer
        $plain = $this->load($this->owner(verified: false), ['pickup_location' => 'Ankara Polatlı', 'price' => 30000]);
        Volt::test('driver.loads.index')->assertSee('Ankara Polatlı')->assertDontSee('doğrulanmadı')->assertDontSee('Doğrulanmamış');
        $this->assertFalse($plain->cargoOwnerProfile->isVerified());
    }

    public function test_distance_and_price_per_km_are_computed_from_coordinates(): void
    {
        // Ankara–İzmir kuş uçuşu ≈ 520 km; kara yolu tahmini × 1,25
        $bird = Geo::haversineKm(self::ANKARA[0], self::ANKARA[1], self::IZMIR[0], self::IZMIR[1]);
        $this->assertGreaterThan(500, $bird);
        $this->assertLessThan(540, $bird);

        $load = $this->load($this->owner());
        $km = $load->distanceKm();
        $this->assertEqualsWithDelta($bird * Geo::ROAD_FACTOR, $km, 0.01);
        $this->assertGreaterThan(600, $km);
        $this->assertLessThan(700, $km);
        $this->assertEqualsWithDelta(45000 / $km, $load->pricePerKm(), 0.01);
        $this->assertMatchesRegularExpression('/^≈ \d{3} km · \d{2} ₺\/km$/u', $load->distanceLabel());

        // Koordinat yoksa satır yok; fiyat yoksa yalnız mesafe
        $this->assertNull($this->load($this->owner(), ['pickup_lat' => null, 'pickup_lng' => null])->distanceLabel());
        $this->assertNull(Geo::roadKm(39.9, 32.8, null, 27.1));
        $this->assertMatchesRegularExpression('/^≈ \d{3} km$/u', $this->load($this->owner(), ['price' => 0])->distanceLabel());

        // Dış kaynak: ton başına fiyatta ₺/km gösterilmez, mesafe gösterilir
        $perTon = $this->scraped(['price' => 1500, 'price_unit' => 'per_ton']);
        $this->assertNull($perTon->pricePerKm());
        $this->assertMatchesRegularExpression('/^≈ \d{3} km$/u', $perTon->distanceLabel());
        $this->assertNotNull($this->scraped()->pricePerKm());

        // "Yakınımda" süzgecinin SQL formülü aynı yerden gelir
        $this->assertSame(Geo::distanceSql('pickup_lat', 'pickup_lng'), LoadFilterService::distanceSql('pickup_lat', 'pickup_lng'));
        $this->assertStringContainsString('6371', LoadFilterService::distanceSql('pickup_lat', 'pickup_lng'));
    }

    public function test_share_text_never_contains_phone_or_owner_details(): void
    {
        $ext = $this->scraped(['parse_metadata' => ['extra_phones_enc' => [Crypt::encryptString('5329998877')], 'pickup_note' => 'Pazartesi sabah']]);
        $text = $ext->shareText();
        $this->assertStringContainsString('Ankara → İzmir', $text);
        $this->assertStringContainsString('Mermer', $text);
        $this->assertStringContainsString('45.000 ₺', $text);
        foreach (['532', '111 22 33', '5321112233', '999 88 77', 'Mehmet', 'wa.me'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text.' '.$ext->shareUrl(), "Paylaşım metninde $forbidden olmamalı");
        }

        // Kartın data-share-text özniteliğinde de numara yok (numara kartın kendisinde görünür, paylaşımda değil)
        $this->actingAs($this->driver());
        $html = Volt::test('driver.loads.index')->set('tab', 'external')->assertSee('0532 111 22 33')->assertSee('Paylaş')->html();
        preg_match_all('/data-share-text="([^"]*)"/u', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $attr) {
            $this->assertStringNotContainsString('532', $attr);
            $this->assertStringNotContainsString('Mehmet', $attr);
        }

        // Sistem ilanı: rota, araç, fiyat ve bağlantı; yük sahibinin adı yok
        $load = $this->load($this->owner());
        $sys = $load->shareText();
        $this->assertStringContainsString('Ankara Sincan → İzmir Aliağa', $sys);
        $this->assertStringContainsString('TIR · Tenteli', $sys);
        $this->assertStringContainsString('Navlun: 45.000 ₺', $sys);
        $this->assertStringNotContainsString('Ayşe', $sys);
        $this->assertStringNotContainsString('Yılmazkaya', $sys);
        $this->assertStringContainsString('ilan='.$load->id, $load->shareUrl());
    }

    public function test_similar_loads_open_under_the_card_with_prices_side_by_side(): void
    {
        $a = $this->scraped(['price' => 45000]);
        $b = $this->scraped(['price' => 42000, 'encrypted_sender_phone' => Crypt::encryptString('5334445566'), 'pickup_location' => 'Ankara Kazan']);
        $a->forceFill(['parse_metadata' => ['similar_with' => [$b->id]]])->save();
        $b->forceFill(['parse_metadata' => ['similar_to' => [$a->id]]])->save();

        $this->assertSame([$b->id], $a->fresh()->similarLoads()->pluck('id')->all());
        $this->assertSame([$a->id], $b->fresh()->similarLoads()->pluck('id')->all());

        $this->actingAs($this->driver());
        $html = Volt::test('driver.loads.index')->set('tab', 'external')
            ->assertSee('Benzer ilan · farklı numara')->assertSee('Bu ilanın fiyatı')
            ->assertSee('45.000 ₺')->assertSee('42.000 ₺')->assertSee('Ankara Kazan')->html();
        // B'nin numarası ve fiyatı hem kendi kartında hem A'nın benzer listesinde (yan yana): en az iki kez
        $this->assertGreaterThanOrEqual(2, substr_count($html, '0533 444 55 66'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, '42.000 ₺'));

        // Benzeri yayından kalkmışsa liste açılmaz, rozet kalır
        $b->forceFill(['visibility' => 'archived'])->save();
        $this->assertCount(0, $a->fresh()->similarLoads());
        Volt::test('driver.loads.index')->set('tab', 'external')->assertSee('Benzer ilan · farklı numara')->assertDontSee('Bu ilanın fiyatı')->assertDontSee('0533 444 55 66');
    }
}
