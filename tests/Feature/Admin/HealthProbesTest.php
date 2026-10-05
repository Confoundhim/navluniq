<?php

namespace Tests\Feature\Admin;

use App\Models\Backup;
use App\Models\CargoOwnerProfile;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y15: sağlık ekranındaki işletim probları ve üstteki kırmızı/sarı özet satırı. */
class HealthProbesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
    }

    /** @return array<string, array{ok:bool, warn:bool, detail:string}> */
    private function checks(): array
    {
        $out = [];
        foreach (Volt::test('admin.health-center')->get('checks') as $c) {
            $out[$c['name']] = $c;
        }

        return $out;
    }

    public function test_probes_turn_red_on_missing_scheduler_old_backup_refunds_and_stuck_payouts(): void
    {
        Cache::forget('scheduler.heartbeat');
        Backup::create(['filename' => 'navluniq-eski.zip', 'backup_type' => 'full', 'status' => 'completed', 'completed_at' => now()->subHours(30)]);
        $owner = User::factory()->create();
        PaymentOrder::create(['user_id' => $owner->id, 'purpose' => PaymentService::PURPOSE_ESCROW, 'provider' => 'iyzico', 'amount' => 1500, 'currency' => 'TRY', 'status' => 'refund_pending']);
        $profile = CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'kyc_status' => 'approved']);
        $load = Load::create([
            'cargo_owner_profile_id' => $profile->id, 'source_type' => 'internal', 'visibility' => 'public', 'pickup_location' => 'İzmir', 'pickup_province_code' => 35,
            'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'pickup_date' => now(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli yük', 'weight' => 1, 'price' => 1000,
            'status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASED,
        ]);
        $payout = Payout::create(['load_id' => $load->id, 'user_id' => $owner->id, 'total_amount' => 1000, 'commission_amount' => 50, 'net_amount' => 950, 'status' => 'processing']);
        Payout::query()->whereKey($payout->id)->update(['updated_at' => now()->subDays(3)]);

        $checks = $this->checks();
        foreach (['Zamanlayıcı', 'Son yedek', 'İade bekleyen emir', 'Takılı hakediş'] as $name) {
            $this->assertArrayHasKey($name, $checks);
            $this->assertFalse($checks[$name]['ok'], $name.': '.$checks[$name]['detail']);
        }
        $this->assertStringContainsString('30 saat', $checks['Son yedek']['detail']);
        $this->assertStringContainsString('1 emir iade bekliyor', $checks['İade bekleyen emir']['detail']);
        $this->assertStringContainsString('1 hakediş', $checks['Takılı hakediş']['detail']);
        $this->assertTrue($checks['Ödeme kuruluşu']['ok'] === false || $checks['Ödeme kuruluşu']['warn'], 'Anahtar yok ya da sandbox: yeşil olmamalı');

        Volt::test('admin.health-center')->assertSee('kontrol kırmızı')->assertSee('Zamanlayıcı');
    }

    public function test_probes_are_green_or_amber_when_everything_is_fresh(): void
    {
        Cache::put('scheduler.heartbeat', now()->timestamp, now()->addDay());
        Backup::create(['filename' => 'navluniq-yeni.zip', 'backup_type' => 'full', 'size_mb' => 12.5, 'status' => 'completed', 'completed_at' => now()->subHours(2)]);

        $checks = $this->checks();
        $this->assertTrue($checks['Zamanlayıcı']['ok']);
        $this->assertStringContainsString('çalışıyor', $checks['Zamanlayıcı']['detail']);
        $this->assertTrue($checks['Son yedek']['ok']);
        $this->assertFalse($checks['Son yedek']['warn']);
        $this->assertTrue($checks['İade bekleyen emir']['ok']);
        $this->assertTrue($checks['Takılı hakediş']['ok']);
        $this->assertTrue($checks['E-posta (24 saat)']['ok']);
        $this->assertTrue($checks['Telefon akışı']['ok']);
        $this->assertTrue($checks['Telefon akışı']['warn'], 'Hiç mesaj gelmemişse sarı');
        $this->assertArrayHasKey('Disk doluluğu', $checks);
        $this->assertArrayHasKey('Yapay zeka sağlayıcıları', $checks);
        $this->assertStringContainsString('% boş', $checks['Disk doluluğu']['detail']);
    }
}
