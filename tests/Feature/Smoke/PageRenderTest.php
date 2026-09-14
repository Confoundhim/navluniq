<?php

namespace Tests\Feature\Smoke;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\User;
use Database\Seeders\CmsContractSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Her sayfanın gerçekten render edildiğini doğrulayan duman testi.
 * Volt bileşenlerindeki mount/render hatalarını yakalar.
 */
class PageRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, FaqSeeder::class, CmsContractSeeder::class]);
    }

    public static function publicRoutes(): array
    {
        return array_map(fn ($r) => [$r], [
            '/', '/hakkimizda', '/yuk-sahipleri-icin', '/soforler-icin', '/nasil-calisir',
            '/abonelik', '/hizmetlerimiz', '/iletisim', '/sozlesmeler', '/sozlesmeler/kullanici-sozlesmesi',
            '/sozlesmeler/gizlilik-politikasi', '/sozlesmeler/mesafeli-satis', '/sozlesmeler/iade-politikasi',
            '/giris', '/kayit/yuk-sahibi', '/kayit/sofor', '/adminsystem', '/up',
        ]);
    }

    #[DataProvider('publicRoutes')]
    public function test_public_pages_render(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public function test_unknown_contract_slug_is_404(): void
    {
        $this->get('/sozlesmeler/olmayan')->assertNotFound();
    }

    public function test_guest_is_redirected_from_panels(): void
    {
        $this->get('/panel')->assertRedirect('/giris');
        $this->get('/panel/yuk-sahibi/dashboard')->assertRedirect('/giris');
        $this->get('/panel/sofor/dashboard')->assertRedirect('/giris');
        $this->get('/adminsystem/dashboard')->assertRedirect('/giris');
    }

    public function test_cargo_owner_pages_render(): void
    {
        $user = $this->cargoOwner();

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'cargo-owner.') || str_ends_with($name, '.') || str_contains($route->uri(), '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $this->actingAs($user)->get('/'.$route->uri())
                ->assertOk("Yük sahibi sayfası render edilemedi: {$name}");
        }
    }

    public function test_cargo_owner_parameterised_pages_render(): void
    {
        $user = $this->cargoOwner();
        $load = Load::create([
            'cargo_owner_profile_id' => $user->cargoOwnerProfile->id,
            'pickup_location' => 'İstanbul',
            'delivery_location' => 'Ankara',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => 'Genel kargo',
            'price' => 15000,
            'status' => 'active_seeking',
        ]);

        $this->actingAs($user)->get(route('cargo-owner.loads.offers', $load->id))->assertOk();
        $this->actingAs($user)->get(route('cargo-owner.finance.payment', $load->id))->assertOk();
    }

    public function test_driver_pages_render(): void
    {
        $user = $this->driver();

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'driver.') || str_ends_with($name, '.') || str_contains($route->uri(), '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $this->actingAs($user)->get('/'.$route->uri())
                ->assertOk("Şoför sayfası render edilemedi: {$name}");
        }
    }

    public function test_admin_pages_render(): void
    {
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.') || $name === 'admin.login' || $name === 'admin.logout' || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $this->actingAs($admin)->get('/'.$route->uri())
                ->assertOk("Admin sayfası render edilemedi: {$name}");
        }
    }

    public function test_panel_redirects_by_role(): void
    {
        $this->actingAs($this->cargoOwner())->get('/panel')->assertRedirect(route('cargo-owner.dashboard'));
        $this->actingAs($this->driver())->get('/panel')->assertRedirect(route('driver.dashboard'));
    }

    public function test_wrong_role_cannot_open_other_panel(): void
    {
        $this->actingAs($this->driver())->get('/panel/yuk-sahibi/dashboard')->assertRedirect();
        $this->actingAs($this->cargoOwner())->get('/panel/sofor/dashboard')->assertRedirect();
        $this->actingAs($this->cargoOwner())->get('/adminsystem/dashboard')->assertRedirect(route('panel'));
    }

    private function cargoOwner(): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);

        return $user->fresh();
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        DriverProfile::create(['user_id' => $user->id]);

        return $user->fresh();
    }
}
