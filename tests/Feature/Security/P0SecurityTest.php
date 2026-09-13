<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\FirewallMiddleware;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class P0SecurityTest extends TestCase
{
    public function test_passwordless_test_login_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('test.login.cargo-owner'));
        $this->assertFalse(Route::has('test.login.driver'));
    }

    public function test_scraper_webhook_fails_closed_without_server_secret(): void
    {
        $this->withoutMiddleware(FirewallMiddleware::class);
        config()->set('services.scraper.token', null);

        $this->postJson('/api/v1/webhook/whatsapp-scraper', [
            'group_name' => 'synthetic-test-group',
            'raw_message' => 'synthetic test message',
        ])->assertStatus(503);
    }

    public function test_simulated_financial_mutation_methods_are_removed(): void
    {
        $payment = file_get_contents(resource_path('views/livewire/cargo-owner/finance/payment.blade.php'));
        $premium = file_get_contents(resource_path('views/livewire/driver/premium/index.blade.php'));

        $this->assertStringNotContainsString('processPayment', $payment);
        $this->assertStringNotContainsString('card_cvv', $payment);
        $this->assertStringNotContainsString('processSubscription', $premium);
        $this->assertStringNotContainsString('card_cvv', $premium);
    }
}
