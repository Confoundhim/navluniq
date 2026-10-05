<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\User;
use App\Services\NviService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y26: NVİ sorgusu kullanıcı başına günde 5; her sonuç işlem kaydına yazılır (TC yazılmaz). */
class NviRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_nvi_query_is_limited_to_five_per_day_and_logged(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());

        $owner = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Osman', 'last_name' => 'Deneme']);
        CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'kyc_status' => 'pending', 'tc_no' => '10000000146', 'birth_year' => 1980]);

        $this->mock(NviService::class)->shouldReceive('verify')->times(5)
            ->andReturn(['success' => true, 'is_match' => false, 'message' => 'Kayıt eşleşmedi', 'source' => 'NVİ (deneme)']);

        $c = Volt::test('admin.kyc-center')->set('role', 'cargo_owner')->call('select', $owner->id);
        for ($i = 0; $i < 5; $i++) {
            $c->call('verifyNvi')->assertSee('Kayıt eşleşmedi');
        }
        $c->call('verifyNvi')->assertSee('günlük NVİ sorgu sınırı');

        $this->assertSame(5, ActivityLog::query()->where('action', 'kyc.nvi_checked')->count());
        $log = ActivityLog::query()->where('action', 'kyc.nvi_checked')->first();
        $this->assertFalse($log->metadata['is_match']);
        $this->assertStringNotContainsString('10000000146', json_encode($log->toArray()));
    }
}
