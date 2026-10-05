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
use App\Services\OfferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Yük sahibi doğrulama paketi (karar 3, 2026-10-05; aynı gün sadeleştirme "sorma, para anında bir kez doğrula"): kayıtta kimlik istenmez,
 * bireysel yük sahibi ilk teklif kabulünde TC + doğum yılını bir kez NVİ ile doğrular; kurumsalda vergi numarası ön doğrulama sayılır,
 * yönetici teyidi yalnız rozet verir; ilan açmak serbest; şoför kartında yalnız olumlu rozet ve "Ad S.". Uydurma kimlik ve numaralar.
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
        $this->assertSame('Şirket · ön doğrulandı', $corp->cargoOwnerProfile->verificationLabel());
        $this->assertNull($corp->cargoOwnerProfile->verificationBlocker(), 'Vergi numarası yazılı kurumsal hesap teklif kabul edebilir');
        $this->assertFalse($corp->cargoOwnerProfile->isVerified(), 'Rozet yalnız yönetici teyidiyle gelir');
        $this->assertSame('Deneme Lojistik A.Ş.', $corp->cargoOwnerProfile->publicName());
        $this->assertSame('Ayşe Y.', $owner->cargoOwnerProfile->publicName());
    }

    public function test_registration_does_not_ask_for_identity_and_creates_an_unverified_profile(): void
    {
        Mail::fake();
        $this->mock(NviService::class)->shouldNotReceive('verify');
        $c = Volt::test('frontend.register-cargo-owner')->set('type', 'individual');
        $c->assertDontSee('T.C. Kimlik Numarası')->assertDontSee('Doğum Yılı');
        $c->set('firstName', 'Ayşe')->set('lastName', 'Yılmaz')->set('email', 'ayse@example.test')
            ->set('phone', '0532 111 22 33')->set('password', 'Sifre1234567!')->set('password_confirmation', 'Sifre1234567!')
            ->set('acceptTerms', true)->call('register')->assertHasNoErrors();
        $profile = CargoOwnerProfile::query()->firstOrFail();
        $this->assertNull($profile->tc_no);
        $this->assertFalse((bool) $profile->nvi_verified);
        $this->assertNull($profile->nvi_checked_at);
    }

    public function test_identity_is_asked_once_at_the_first_offer_acceptance_and_then_accepted_in_the_same_step(): void
    {
        $owner = $this->owner();
        $load = $this->load($owner);
        $driver = $this->driver();
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDays(2)]);
        $this->mock(NviService::class)->shouldReceive('verify')->once()->with('12345678901', 'Ayşe', 'Yılmaz', '1990')
            ->andReturn(['success' => true, 'is_match' => true, 'message' => 'Eşleşti', 'source' => 'NVİ']);
        $this->actingAs($owner);

        // Kabul düğmesi teklif kabul etmez, kimlik adımını açar
        $c = Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->call('acceptOffer', $offer->id);
        $this->assertSame('pending', $offer->fresh()->status);
        $c->assertSet('verifyOfferId', $offer->id)->assertSee('Teklifi kabul etmeden önce kimliğinizi doğrulayın')->assertSee('14.000,00');

        // Eksik bilgi → hata, teklif duruyor
        $c->set('verify_tc', '123')->set('verify_birth_year', '1990')->call('verifyAndAccept')->assertHasErrors(['verify_tc']);
        $this->assertSame('pending', $offer->fresh()->status);

        // Doğru bilgi → NVİ eşleşir, teklif aynı adımda kabul edilir
        $c->set('verify_tc', '12345678901')->call('verifyAndAccept')->assertHasNoErrors()->assertRedirect(route('cargo-owner.finance.payment', $load->id));
        $this->assertSame('accepted', $offer->fresh()->status);
        $profile = $owner->cargoOwnerProfile->fresh();
        $this->assertTrue((bool) $profile->nvi_verified);
        $this->assertSame(['12345678901', 1990], [$profile->tc_no, (int) $profile->birth_year]);
        $this->assertFalse($profile->needsIdentityStep(), 'Bir daha sorulmaz');
    }

    public function test_identity_mismatch_keeps_the_offer_pending_and_shows_the_reason(): void
    {
        $owner = $this->owner();
        $load = $this->load($owner);
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $this->driver()->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDays(2)]);
        $this->mock(NviService::class)->shouldReceive('verify')->once()
            ->andReturn(['success' => true, 'is_match' => false, 'message' => 'Kimlik bilgileri hatalı veya eşleşmiyor.', 'source' => 'NVİ']);
        $this->actingAs($owner);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->call('acceptOffer', $offer->id)
            ->set('verify_tc', '12345678901')->set('verify_birth_year', '1990')->call('verifyAndAccept')->assertHasErrors(['verify_tc'])->assertSee('eşleşmiyor');
        $this->assertSame('pending', $offer->fresh()->status);
        $this->assertFalse((bool) $owner->cargoOwnerProfile->fresh()->nvi_verified);

        // Servis katmanı da doğrulanmamış kabulü reddeder (ekran dışı çağrılar için)
        $this->expectException(\RuntimeException::class);
        app(OfferService::class)->accept($load->fresh(), $offer->fresh(), $owner->id);
    }

    public function test_corporate_owner_with_a_tax_number_accepts_offers_without_waiting_for_admin(): void
    {
        $corp = $this->owner(['type' => 'corporate', 'company_title' => 'Deneme Lojistik A.Ş.', 'tax_no' => '1234567890']);
        $load = $this->load($corp);
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $this->driver()->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDays(2)]);
        $this->actingAs($corp);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->call('acceptOffer', $offer->id)->assertRedirect(route('cargo-owner.finance.payment', $load->id));
        $this->assertSame('accepted', $offer->fresh()->status);

        // Ayar kapalıyken bireysel doğrulanmamış hesap da kabul edebilir
        Settings::set('cargo_owner_verification_required', '0');
        $this->assertNull($this->owner()->cargoOwnerProfile->verificationBlocker());
    }

    public function test_unverified_owner_can_publish_loads_freely(): void
    {
        $owner = $this->owner();
        $svc = app(LoadService::class);
        $data = ['pickup_location' => 'Ankara', 'delivery_location' => 'İzmir', 'pickup_date' => now()->addDay()->format('Y-m-d H:i'), 'vehicle_type' => 'tir', 'goods_type' => Load::GOODS_TYPES[0], 'price' => 15000];
        for ($i = 0; $i < 5; $i++) {
            $svc->publish($owner->cargoOwnerProfile, $data);
        }
        $this->assertSame(5, Load::query()->count());
        $this->actingAs($owner);
        Volt::test('cargo-owner.loads.create')->assertDontSee('Hesabınız henüz doğrulanmadı');
    }

    public function test_driver_sees_public_name_and_only_a_positive_badge(): void
    {
        $verified = $this->owner(['nvi_verified' => true]);
        $unverified = $this->owner();
        $this->load($verified);
        $this->load($unverified);
        $this->actingAs($this->driver());
        $html = Volt::test('driver.loads.index')->html();
        $this->assertStringContainsString('Doğrulanmış yük sahibi', $html);
        $this->assertStringNotContainsString('Doğrulanmamış', $html, 'Doğrulanmamış hesaba olumsuz damga vurulmaz');
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
