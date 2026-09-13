<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles([$role]);

        return $user->fresh();
    }

    public function test_kyc_validator_can_open_kyc_but_not_finance(): void
    {
        $user = $this->staff('kyc_validator');

        $this->actingAs($user)->get('/adminsystem/kyc')->assertOk();
        $this->actingAs($user)->get('/adminsystem/finance')->assertForbidden();
    }

    public function test_financial_officer_can_open_finance_but_not_kyc(): void
    {
        $user = $this->staff('financial_officer');

        $this->actingAs($user)->get('/adminsystem/finance')->assertOk();
        $this->actingAs($user)->get('/adminsystem/kyc')->assertForbidden();
    }

    public function test_support_agent_can_open_disputes_page_for_tickets(): void
    {
        $user = $this->staff('support_agent');

        $this->actingAs($user)->get('/adminsystem/disputes')->assertOk();
        $this->actingAs($user)->get('/adminsystem/settings')->assertForbidden();
    }

    public function test_staff_login_accepts_kyc_validator_and_completes_otp(): void
    {
        Mail::fake();
        $user = $this->staff('kyc_validator');

        $component = Volt::test('admin.login')
            ->set('identifier', $user->email)
            ->set('password', 'password')
            ->call('submitCredentials')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $user->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(5)])->save();

        $component->set('otp', '123456')
            ->call('verifyOtp')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->otp_code);
    }

    public function test_staff_login_rejects_non_panel_user(): void
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner']);
        $user->syncRoles(['cargo_owner']);

        Volt::test('admin.login')
            ->set('identifier', $user->email)
            ->set('password', 'password')
            ->call('submitCredentials')
            ->assertHasErrors(['identifier'])
            ->assertSet('step', 1);

        $this->assertGuest();
    }
}
