<?php

namespace Tests\Feature\Ops;

use App\Http\Middleware\FirewallMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** I14: içerik güvenliği politikası önce rapor kipinde; ihlal raporu ucu CSRF'siz, sınırlı ve tekrarları eler. */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_report_only_policy_by_default(): void
    {
        $response = $this->withoutMiddleware(FirewallMiddleware::class)->get('/');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy);
        foreach (["default-src 'self'", "script-src 'self' 'unsafe-inline' 'unsafe-eval'", 'https://*.tile.openstreetmap.org', 'frame-src https://*.iyzipay.com', "object-src 'none'", 'report-uri /csp-rapor'] as $part) {
            $this->assertStringContainsString($part, $policy);
        }
        $this->assertSame(SecurityHeaders::policy(), $policy);
    }

    public function test_panel_setting_switches_policy_to_enforcing(): void
    {
        Settings::set('csp_enforce', '1');

        $response = $this->withoutMiddleware(FirewallMiddleware::class)->get('/');
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::policy());
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_api_responses_are_not_affected(): void
    {
        $this->withoutMiddleware(FirewallMiddleware::class)->getJson('/api/v1/toplayici/version')
            ->assertOk()->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_violation_report_is_logged_once_per_hour_without_csrf(): void
    {
        Log::spy();
        $report = json_encode(['csp-report' => [
            'document-uri' => 'https://navluniq.com/giris', 'violated-directive' => 'script-src', 'blocked-uri' => 'https://kotu.example/x.js',
            'source-file' => 'https://navluniq.com/giris', 'line-number' => 12,
        ]]);

        $this->withoutMiddleware(FirewallMiddleware::class)
            ->call('POST', '/csp-rapor', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $report)
            ->assertNoContent();
        $this->withoutMiddleware(FirewallMiddleware::class)
            ->call('POST', '/csp-rapor', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $report)
            ->assertNoContent();

        Log::shouldHaveReceived('notice')->once()->withArgs(fn ($msg, $ctx) => $msg === 'CSP ihlali bildirildi.' && $ctx['directive'] === 'script-src' && $ctx['blocked'] === 'https://kotu.example/x.js');

        // Başka bir ihlal ayrı sayılır.
        Cache::flush();
        $this->withoutMiddleware(FirewallMiddleware::class)
            ->call('POST', '/csp-rapor', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['body' => ['documentURL' => 'https://navluniq.com/', 'effectiveDirective' => 'img-src', 'blockedURL' => 'https://baska.example/a.png']]]))
            ->assertNoContent();
        Log::shouldHaveReceived('notice')->twice();
    }

    public function test_garbage_report_body_is_ignored(): void
    {
        Log::spy();
        $this->withoutMiddleware(FirewallMiddleware::class)
            ->call('POST', '/csp-rapor', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'bu json değil')
            ->assertNoContent();
        Log::shouldNotHaveReceived('notice');
    }

    public function test_report_endpoint_has_its_own_rate_limiter(): void
    {
        $this->assertNotNull(app(RateLimiter::class)->limiter('csp-report'));
        $this->assertContains('throttle:csp-report', app('router')->getRoutes()->getByName('csp.report')->middleware());
    }
}
