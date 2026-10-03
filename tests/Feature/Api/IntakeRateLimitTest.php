<?php

namespace Tests\Feature\Api;

use App\Support\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Telefon alım uçlarının istek sınırları birbirinden bağımsız: çok grubu olan bir telefon dakikada onlarca mesaj yollasa da
 * sınama ucu 429 vermez (2026-10-03: Engin Abi'nin telefonunda sınama "sunucu 429 döndü" diyordu, Facebook dökümleri gidemiyordu).
 */
class IntakeRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_flood_does_not_block_the_ping_endpoint(): void
    {
        Cache::flush();
        Settings::set('scraper_api_token', 'sinama-anahtari-123456');
        for ($i = 0; $i < 40; $i++) {
            $this->assertNotSame(429, $this->withHeaders(['X-Scraper-Token' => 'sinama-anahtari-123456'])->postJson('/api/v1/webhook/notification', [])->status());
        }
        $this->withHeaders(['X-Scraper-Token' => 'sinama-anahtari-123456'])->get('/api/v1/webhook/notification/ping')->assertOk()->assertJsonPath('ok', true);
        $this->get('/api/v1/toplayici/version')->assertOk();

        // Mesaj ucu bir telefonun en yoğun dakikasına yeter (600/dk); sınama ucu kendi sayacında kalır.
        $limiter = app(RateLimiter::class);
        $request = Request::create('/api/v1/webhook/notification', 'POST');
        $this->assertSame(600, $limiter->limiter('intake')($request)->maxAttempts);
        $this->assertSame(30, $limiter->limiter('intake-ping')($request)->maxAttempts);
        $this->assertNotSame($limiter->limiter('intake')($request)->key, $limiter->limiter('intake-ping')($request)->key);
    }
}
