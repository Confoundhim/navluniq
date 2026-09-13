<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_route_is_registered(): void
    {
        $this->assertTrue(Route::has('home'));
        $this->assertSame('/', route('home', absolute: false));
    }
}
