<?php

namespace Tests\Feature\CargoOwner;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\KycDocument;
use App\Models\Load;
use App\Models\Offer;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\KycService;
use App\Services\LoadService;
use App\Services\NviService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Yük sahibi doğrulama paketi (karar 3, 2026-10-05): bireysel NVİ, kurumsal yönetici teyidi; doğrulanmamış hesap teklif kabul edemez ve
 * sınırlı ilan açar; şoför kartında rozet ve "Ad S."; belge fotoğrafı zorunlu değil. Uydurma kimlik ve numaralar.
 */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Settings::set('cargo_owner_verification_required', '1');
    }

    private function owner(array $profile = []): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(array_merge(['user_id' => $user->id, 'type' => 'individual'], $profile));

        return $user->fresh();
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34ABC123', 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function load(User $owner): Load
    {
        return Load::create([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir', 'pickup_date' => now()->addDay(), 'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0], 'price' => 15000, 'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ]);
    }

    public function test_documents_are_optional_and_verification_comes_from_identity_checks(): void
    {
        $owner = $this->owner();
        $this->assertSame([], app(KycService::class)->requiredTypes($owner, 'cargo_owner'));
        $this->assertSame([], KycDocument::CARGO_OWNER_CORPORATE_REQUIRED);
        $this->assertFalse($owner->cargoOwnerProfile->isVerified());
        $this->assertSame('Kimlik doğrulanmadı', $owner->cargoOwnerProfile->verificationLabel());
        $owner->cargoOwnerProfile->update(['nvi_verified' => true]);
        $this->assertTrue($owner->cargoOwnerProfile->fresh()->isVerified());
        $corp = $this->owner(['type' => 'corporate', 'company_title' => 'Deneme Lojistik A.Ş.', 'tax_no' => '1234567890']);
        $this->assertSame('Şirket doğrulaması bekleniyor', $corp->cargoOwnerProfile->verificationLabel());
        $this->assertSame('Deneme Lojistik A.Ş.', $corp->cargoOwnerProfile->publicName());
        $this->assertSame('Ayşe Y.', $owner->cargoOwnerProfile->publicName());
    }

    public function test_registration_saves_birth_year_and_the_nvi_result_without_blocking_signup(): void
    {
        Mail::fake();
        $this->mock(NviService::class)->shouldReceive('verify')->once()->with('12345678901', 'Ayşe', 'Yılmaz', '1990')
            ->andReturn(['success' => true, 'is_match' => false, 'message' => 'Kimlik bilgileri hatalı veya eşleşmiyor.', 'source' => 'NVİ']);
        Volt::test('frontend.register-cargo-owner')
            ->set('type', 'individual')->set('firstName', 'Ayşe')->set('lastName', 'Yılmaz')->set('email', 'ayse@example.test')
            ->set('phone', '0532 111 22 33')->set('password', 'Sifre1234567!')->set('password_confirmation', 'Sifre1234567!')
            ->set('tcNo', '12345678901')->set('birthYear', '1990')->set('acceptTerms', true)
            ->call('register')->assertHasNoErrors();
        $profile = CargoOwnerProfile::query()->firstOrFail();
        $this->assertSame([1990, false], [(int) $profile->birth_year, (bool) $profile->nvi_verified]);
        $this->assertNotNull($profile->nvi_checked_at);
        $this->assertStringContainsString('eşleşmiyor', (string) $profile->nvi_message);
    }

    public function test_unverified_owner_cannot_accept_offers_until_identity_is_verified(): void
    {
        $owner = $this->owner();
        $load = $this->load($owner);
        $driver = $this->driver();
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDays(2)]);
        $this->actingAs($owner);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->call('acceptOffer', $offer->id);
        $this->assertSame('pending', $offer->fresh()->status);
        $this->assertSame(Load::STATUS_ACTIVE, $load->fresh()->status);

        // Kimlik doğrulanınca kabul edilir
        $owner->cargoOwnerProfile->update(['nvi_verified' => true]);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->call('acceptOffer', $offer->id)->assertRedirect(route('cargo-owner.finance.payment', $load->id));
        $this->assertSame('accepted', $offer->fresh()->status);

        // Ayar kapalıyken doğrulanmamış hesap da kabul edebilir
        Settings::set('cargo_owner_verification_required', '0');
        $this->assertNull($this->owner()->cargoOwnerProfile->verificationBlocker());
    }

    public function test_unverified_owner_is_limited_in_open_loads(): void
    {
        $owner = $this->owner();
        $svc = app(LoadService::class);
        $data = ['pickup_location' => 'Ankara', 'delivery_location' => 'İzmir', 'pickup_date' => now()->addDay()->format('Y-m-d H:i'), 'vehicle_type' => 'tir', 'goods_type' => Load::GOODS_TYPES[0], 'price' => 15000];
        for ($i = 0; $i < 3; $i++) {
            $svc->publish($owner->cargoOwnerProfile, $data);
        }
        try {
            $svc->publish($owner->cargoOwnerProfile, $data);
            $this->fail('Dördüncü ilan açılmamalıydı');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('en çok 3 açık ilan', $e->getMessage());
        }
        $owner->cargoOwnerProfile->update(['nvi_verified' => true]);
        $svc->publish($owner->cargoOwnerProfile->fresh(), $data);
        $this->assertSame(4, Load::query()->count());
    }

    public function test_driver_sees_public_name_and_verification_badge(): void
    {
        $verified = $this->owner(['nvi_verified' => true]);
        $unverified = $this->owner();
        $this->load($verified);
        $this->load($unverified);
        $this->actingAs($this->driver());
        $html = Volt::test('driver.loads.index')->html();
        $this->assertStringContainsString('Doğrulanmış yük sahibi', $html);
        $this->assertStringContainsString('Doğrulanmamış yük sahibi', $html);
        $this->assertStringContainsString('Ayşe Y.', $html);
        $this->assertStringNotContainsString('Ayşe Yılmaz', $html);
    }

    public function test_owner_can_reverify_identity_from_the_profile_page(): void
    {
        $owner = $this->owner(['tc_no' => '12345678901', 'birth_year' => 1990, 'nvi_message' => 'Kimlik bilgileri hatalı veya eşleşmiyor.']);
        $this->mock(NviService::class)->shouldReceive('verify')->once()->with('12345678902', 'Ayşe', 'Yılmaz', '1991')
            ->andReturn(['success' => true, 'is_match' => true, 'message' => 'Eşleşti', 'source' => 'NVİ']);
        $this->actingAs($owner);
        Volt::test('cargo-owner.profile.index')->assertSee('Kimliğimi doğrula')->assertSee('eşleşmiyor')
            ->set('verify_tc', '12345678902')->set('verify_birth_year', '1991')->call('reverifyIdentity')->assertHasNoErrors();
        $profile = $owner->cargoOwnerProfile->fresh();
        $this->assertTrue((bool) $profile->nvi_verified);
        $this->assertSame(['12345678902', 1991], [$profile->tc_no, (int) $profile->birth_year]);
        $this->assertNull($profile->nvi_message);
    }

    public function test_admin_verifies_a_company_and_the_owner_is_notified(): void
    {
        $corp = $this->owner(['type' => 'corporate', 'company_title' => 'Deneme Lojistik A.Ş.', 'tax_no' => '1234567890', 'tax_office' => 'Kızılbey']); // VKN mod-10 geçerli
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.kyc-center')->set('role', 'cargo_owner')->set('statusFilter', '')->call('select', $corp->id)
            ->assertSee('Şirketi doğrula')->call('setCompanyVerified', true)->assertSee('Doğrulamayı kaldır');
        $profile = $corp->cargoOwnerProfile->fresh();
        $this->assertTrue((bool) $profile->gib_verified);
        $this->assertSame($admin->id, $profile->gib_verified_by);
        $this->assertTrue($profile->isVerified());
        $this->assertSame(1, UserNotification::query()->where('user_id', $corp->id)->where('title', 'Şirketiniz doğrulandı')->count());
    }
}
