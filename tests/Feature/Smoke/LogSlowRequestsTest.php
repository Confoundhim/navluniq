<?php

namespace Tests\Feature\Smoke;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LogSlowRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_over_the_threshold_are_logged_with_path_and_query_count(): void
    {
        Route::middleware('web')->get('/yavas-deneme', function () {
            usleep(30_000);
            DB::select('select 1');

            return 'ok';
        });

        config()->set('app.slow_request_seconds', 0.01);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $ctx): bool {
            return $message === 'Yavaş istek' && $ctx['path'] === '/yavas-deneme' && $ctx['queries'] >= 1 && $ctx['seconds'] >= 0.03;
        });
        $this->get('/yavas-deneme')->assertOk();

        // Eşik altı istek yazılmaz; eşik 0 ise özellik kapalıdır.
        config()->set('app.slow_request_seconds', 30);
        Log::shouldReceive('warning')->never();
        $this->get('/yavas-deneme')->assertOk();
        config()->set('app.slow_request_seconds', 0);
        $this->get('/yavas-deneme')->assertOk();
    }
}
