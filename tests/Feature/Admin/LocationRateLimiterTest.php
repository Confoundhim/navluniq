<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Denetim A22: şoför konum ucu adlı sınırlayıcıda, kullanıcı başına sayaç (paylaşımlı IP sayacı değil). */
class LocationRateLimiterTest extends TestCase
{
    public function test_location_route_uses_the_named_per_user_limiter(): void
    {
        $route = Route::getRoutes()->getByName('driver.location.store');
        $this->assertNotNull($route);
        $this->assertContains('throttle:driver-location', $route->middleware());
        $this->assertNotContains('throttle:60,1', $route->middleware());

        $limiter = RateLimiter::limiter('driver-location');
        $this->assertNotNull($limiter);

        $a = new User(['first_name' => 'A']);
        $a->id = 11;
        $b = new User(['first_name' => 'B']);
        $b->id = 22;
        $requestA = Request::create('/panel/sofor/konum', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $requestA->setUserResolver(fn () => $a);
        $requestB = Request::create('/panel/sofor/konum', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $requestB->setUserResolver(fn () => $b);

        /** @var Limit $limitA */
        $limitA = $limiter($requestA);
        /** @var Limit $limitB */
        $limitB = $limiter($requestB);
        $this->assertSame(60, $limitA->maxAttempts);
        $this->assertSame('loc|11', $limitA->key);
        $this->assertSame('loc|22', $limitB->key, 'Aynı IP, farklı kullanıcı: ayrı sayaç');
    }
}
