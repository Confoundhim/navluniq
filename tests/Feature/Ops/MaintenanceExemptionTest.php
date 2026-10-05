<?php

namespace Tests\Feature\Ops;

use App\Http\Middleware\FirewallMiddleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Tests\TestCase;

/** I3: ödeme kuruluşunun geri çağrıları bakım modunda da karşılanır (para çekilip ilan "ödenmedi" kalmaz). */
class MaintenanceExemptionTest extends TestCase
{
    public function test_payment_callbacks_are_exempt_from_maintenance_mode(): void
    {
        $excluded = app(PreventRequestsDuringMaintenance::class)->getExcludedPaths();

        $this->assertContains('odeme/bildirim/*', $excluded);
        $this->assertContains('odeme/paytr/bildirim', $excluded);
        $this->assertContains('adminsystem/health/update-status', $excluded);
    }

    public function test_maintenance_mode_blocks_pages_but_not_payment_callbacks(): void
    {
        $this->withoutMiddleware(FirewallMiddleware::class);
        $this->app->maintenanceMode()->activate(['retry' => 15, 'status' => 503]);

        try {
            $this->get('/')->assertStatus(503);
            $this->assertNotSame(503, $this->post('/odeme/paytr/bildirim', [])->status());
            $this->assertNotSame(503, $this->post('/odeme/bildirim/iyzico', [])->status());
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_update_script_opens_site_before_background_classification_and_keeps_previous_build(): void
    {
        $script = file_get_contents(base_path('deploy/update.sh'));

        $this->assertStringContainsString('--secret="$BYPASS_SECRET"', $script);
        $this->assertStringContainsString('public/build.prev', $script);
        $this->assertStringContainsString('nohup php artisan scraped-loads:classify', $script);
        // Sınıflandırma ancak "php artisan up" satırından sonra gelir (bakım süresini uzatmaz).
        $this->assertGreaterThan(strpos($script, 'php artisan up --quiet || true'), strpos($script, 'nohup php artisan scraped-loads:classify'));
    }
}
