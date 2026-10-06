<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverFilterPreset;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LoadService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Paket 4 (2026-10-06): yeni ilan bildirimi şoförün varsayılan kayıtlı filtresine de bakar; ön ayarı olmayan eskisi gibi. */
class PresetNotificationTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private CargoOwnerProfile $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Http::fake();
        Settings::set('scraper_free_delay_minutes', '20');
        Settings::set('min_load_price', '1000');
        $ownerUser = User::factory()->create(['current_role' => 'cargo_owner']);
        $this->owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
    }

    private function driver(bool $premium = true): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06PRS'.(++self::$seq), 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'trailer_length' => 'uzun', 'is_active' => true]);

        return $user->fresh();
    }

    private function publish(string $pickup, array $extra = []): Load
    {
        return app(LoadService::class)->publish($this->owner->fresh(), array_merge([
            'pickup_location' => $pickup, 'delivery_location' => 'Konya', 'pickup_date' => now()->addDay()->toDateString(),
            'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli Yük', 'weight' => 24000, 'price' => 45000,
        ], $extra));
    }

    public function test_driver_with_ankara_preset_is_not_notified_for_izmir_load(): void
    {
        $ankara = $this->driver();
        DriverFilterPreset::create(['driver_profile_id' => $ankara->driverProfile->id, 'name' => 'Ankara çıkışlı', 'is_default' => true, 'filters' => ['pickup_provinces' => [6]]]);
        $anywhere = $this->driver();
        // Varsayılan olmayan ön ayar bildirimi etkilemez
        $other = $this->driver();
        DriverFilterPreset::create(['driver_profile_id' => $other->driverProfile->id, 'name' => 'Bursa çıkışlı', 'is_default' => false, 'filters' => ['pickup_provinces' => [16]]]);

        $this->publish('İzmir');
        $this->assertSame(0, UserNotification::where('user_id', $ankara->id)->count(), 'Ankara filtresi kayıtlı şoföre İzmir ilanı bildirilmez');
        $this->assertSame(1, UserNotification::where('user_id', $anywhere->id)->count(), 'Ön ayarı olmayan şoför eskisi gibi haber alır');
        $this->assertSame(1, UserNotification::where('user_id', $other->id)->count(), 'Varsayılan olmayan ön ayar süzmez');

        $this->publish('Ankara');
        $this->assertSame(1, UserNotification::where('user_id', $ankara->id)->count(), 'Ankara ilanı bildirilir');
        $this->assertSame(2, UserNotification::where('user_id', $anywhere->id)->count());
    }

    public function test_preset_price_weight_and_body_filters_apply_to_notification(): void
    {
        $picky = $this->driver();
        DriverFilterPreset::create(['driver_profile_id' => $picky->driverProfile->id, 'name' => 'Seçici', 'is_default' => true,
            'filters' => ['min_price' => 50000, 'max_weight' => 20000, 'body_types' => ['kapali']]]);

        $this->publish('Ankara'); // 45.000 ₺, 24 ton, tenteli: üçü de uymaz
        $this->assertSame(0, UserNotification::where('user_id', $picky->id)->count());

        $this->publish('Ankara', ['price' => 60000, 'weight' => 18000, 'body_types' => ['kapali', 'tenteli']]);
        $this->assertSame(1, UserNotification::where('user_id', $picky->id)->count());
    }

    public function test_notification_stays_premium_only(): void
    {
        $free = $this->driver(premium: false);
        DriverFilterPreset::create(['driver_profile_id' => $free->driverProfile->id, 'name' => 'Ankara', 'is_default' => true, 'filters' => ['pickup_provinces' => [6]]]);
        $premium = $this->driver();

        $this->publish('Ankara');
        $this->assertSame(1, UserNotification::where('user_id', $premium->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $free->id)->count(), 'Erken erişim penceresinde standart üyeye bildirim yok');
    }
}
