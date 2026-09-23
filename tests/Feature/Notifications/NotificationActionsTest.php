<?php

namespace Tests\Feature\Notifications;

use App\Models\DriverProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Bildirimler sayfası: okundu işaretle, tek tek sil, okunanları sil; zil anında güncellenir. */
class NotificationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_read_delete_and_clear_read(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        $svc = app(NotificationService::class);
        $a = $svc->notify($user, 'Birinci bildirim', ['a'], null, null, 'general', false);
        $b = $svc->notify($user, 'İkinci bildirim', ['b'], null, null, 'return_load', false);
        $c = $svc->notify($user, 'Üçüncü bildirim', ['c'], null, null, 'general', false);
        $this->actingAs($user);

        $page = Volt::test('driver.notifications.index')->assertSee('Birinci bildirim')->assertSee('Dönüş yükü')->assertDontSee('return_load');
        $page->call('markRead', $a->id)->assertDispatched('notifications-changed');
        $this->assertNotNull($a->fresh()->read_at);
        $this->assertSame(2, $user->fresh()->unreadNotificationCount());

        $page->call('delete', $b->id)->assertDontSee('İkinci bildirim');
        $this->assertNull(UserNotification::find($b->id));

        $page->call('deleteRead')->assertDontSee('Birinci bildirim')->assertSee('Üçüncü bildirim');
        $this->assertSame(1, UserNotification::query()->where('user_id', $user->id)->count());

        // Başkasının bildirimi silinemez
        $other = User::factory()->driver()->create();
        $o = $svc->notify($other, 'Yabancı', ['x'], null, null, 'general', false);
        $page->call('delete', $o->id);
        $this->assertNotNull(UserNotification::find($o->id));

        Volt::test('notifications.bell')->assertSee('1')->call('refreshBell');
    }
}
