<?php

namespace Tests\Feature;

use App\Livewire\PausePollWhileInteracting;
use App\Models\DriverProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Volt\Volt;
use Tests\TestCase;

use function Livewire\store;

/** Kullanıcı ekranla uğraşırken wire:poll yenilemesi ekranı yeniden çizmez; kendi tıklamaları etkilenmez. */
class PollPauseWhileInteractingTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_skips_render_while_user_is_interacting(): void
    {
        // Tarayıcıdaki wire:poll "$refresh" çağrısı gönderir; test yardımcısı bunu taklit edemediğinden kanca doğrudan çağrılır.
        $component = new class extends Component {};
        $hook = new PausePollWhileInteracting;
        $hook->setComponent($component);
        $noop = fn () => null;

        request()->headers->set('X-User-Idle-Ms', '1200'); // 1,2 sn önce dokundu
        $hook->call('$refresh', [], $noop);
        $this->assertTrue((bool) store($component)->get('skipRender', false), 'Etkileşim sürerken yenileme çizilmez');

        $component2 = new class extends Component {};
        $hook->setComponent($component2);
        $hook->call('setTab', ['offers'], $noop);
        $this->assertFalse((bool) store($component2)->get('skipRender', false), 'Kullanıcının kendi eylemi her zaman çizilir');

        request()->headers->set('X-User-Idle-Ms', '45000');
        $hook->call('$refresh', [], $noop);
        $this->assertFalse((bool) store($component2)->get('skipRender', false), 'Boşta kalınca yenileme çizilir');

        request()->headers->remove('X-User-Idle-Ms'); // başlık yoksa (eski önbellekli JS) eski davranış
        $hook->call('$refresh', [], $noop);
        $this->assertFalse((bool) store($component2)->get('skipRender', false));
    }

    public function test_district_box_state_is_kept_on_server(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        $this->actingAs($user);

        Volt::test('driver.loads.index')->call('toggleProvince', 'pickup', 6)
            ->assertSee('Ankara ilçeleri (tümü)')->assertSeeHtml('aria-expanded="false"')
            ->call('toggleDistrictBox', 'pickup', 6)->assertSeeHtml('aria-expanded="true"')
            ->call('$refresh')->assertSeeHtml('aria-expanded="true"')
            ->call('toggleDistrictBox', 'pickup', 6)->assertSeeHtml('aria-expanded="false"');
    }
}
