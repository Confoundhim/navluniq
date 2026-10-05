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

    /** Ölü Baileys (WhatsApp botu) ucu kaldırıldı (I19): anahtarsız/anahtarlı hiçbir istek bu adrese ulaşmaz. */
    public function test_legacy_baileys_webhook_is_not_registered(): void
    {
        $this->withoutMiddleware(FirewallMiddleware::class);

        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'whatsapp-scraper')));
        $this->postJson('/api/v1/webhook/whatsapp-scraper', [
            'group_name' => 'synthetic-test-group',
            'raw_message' => 'synthetic test message',
        ], ['X-Scraper-Token' => 'anything'])->assertNotFound();
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/WhatsappWebhookController.php'));
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
