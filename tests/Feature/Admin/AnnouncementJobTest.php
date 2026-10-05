<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendAnnouncementJob;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MarketingConsentService;
use App\Services\NotificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y12 / Y21: duyuru yalnız süper yönetici, kuyruk işleriyle parça parça; kupon sekmesi yok; pazarlama rızası yoksa ticari ileti gitmez. */
class AnnouncementJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $driver = User::factory()->driver()->create();
            $driver->syncRoles(['driver']);
            DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        }
    }

    private function staff(string $role, array $extra = []): User
    {
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles([$role]);
        foreach ($extra as $p) {
            $user->givePermissionTo($p);
        }

        return $user->fresh();
    }

    public function test_announcement_is_super_admin_only_and_dispatched_in_chunks(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('support_agent', ['manage marketing']));
        $this->get('/adminsystem/crm')->assertOk()->assertSee('Duyuru (hizmet bildirimi)')->assertDontSee('Kuponlar')->assertSee('yalnız süper yöneticiye açıktır');
        Volt::test('admin.crm-center')->call('countTargets')->assertSet('targetCount', 3)
            ->set('subject', 'Bakım duyurusu')->set('message', 'Pazar gecesi 02:00-03:00 arasında kısa bir bakım yapılacaktır. Anlayışınız için teşekkürler.')
            ->set('confirmSend', true)->call('sendAnnouncement')->assertSee('yalnız süper yönetici');
        Queue::assertNothingPushed();

        $this->actingAs($this->staff('super_admin'));
        Volt::test('admin.crm-center')->call('countTargets')->assertSet('targetCount', 3)
            ->set('subject', 'Bakım duyurusu')->set('message', 'Pazar gecesi 02:00-03:00 arasında kısa bir bakım yapılacaktır. Anlayışınız için teşekkürler.')
            ->set('confirmSend', true)->call('sendAnnouncement')->assertHasNoErrors()->assertSee('3 alıcı için kuyruğa alındı');
        Queue::assertPushed(SendAnnouncementJob::class, 1);
        Queue::assertPushed(SendAnnouncementJob::class, fn (SendAnnouncementJob $job) => count($job->userIds) === 3 && $job->subject === 'Bakım duyurusu');
        $this->assertSame(0, UserNotification::count(), 'Web isteği içinde gönderim yapılmaz');
    }

    public function test_job_notifies_each_active_recipient_and_marketing_targets_only_consenting_users(): void
    {
        $ids = User::role('driver')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $banned = User::find($ids[0]);
        $banned->update(['banned_at' => now()]);

        (new SendAnnouncementJob($ids, 'Yeni özellik', ['Dönüş yükü taraması açıldı.']))->handle(app(NotificationService::class));
        $this->assertSame(2, UserNotification::query()->where('title', 'Yeni özellik')->count(), 'Kuyrukta beklerken engellenen almaz');

        // Ticari ileti: yalnız açık rıza vermiş (ve geri almamış) kullanıcılar hedeflenir
        $this->actingAs($this->staff('super_admin'));
        Volt::test('admin.crm-center')->set('category', 'marketing')->call('countTargets')->assertSet('targetCount', 0);
        $consenting = User::find($ids[1]);
        app(MarketingConsentService::class)->grant($consenting);
        Volt::test('admin.crm-center')->set('category', 'marketing')->call('countTargets')->assertSet('targetCount', 1);
        app(MarketingConsentService::class)->revoke($consenting->fresh(), 'test');
        Volt::test('admin.crm-center')->set('category', 'marketing')->call('countTargets')->assertSet('targetCount', 0);
    }
}
