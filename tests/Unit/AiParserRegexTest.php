<?php

namespace Tests\Unit;

use App\Services\AiParserService;
use Tests\TestCase;

class AiParserRegexTest extends TestCase
{
    public function test_turkish_numbers_phones_and_routes_are_parsed_without_ai(): void
    {
        $r = app(AiParserService::class)->parseCheap("Gebze'den Konya'ya 12,5 ton palet yük 45.000 TL 0 (544) 222-33-44 acil");

        $this->assertTrue($r['success']);
        $this->assertSame('5442223344', $r['sender_phone']);
        $this->assertSame('Gebze', $r['pickup_location']);
        $this->assertSame('Konya', $r['delivery_location']);
        $this->assertSame(12500, $r['weight']);
        $this->assertSame(45000.0, $r['price']);
        $this->assertSame('regex_verified', $r['parsed_by_llm']);
    }

    public function test_dash_route_with_district_and_kg_weight(): void
    {
        $r = app(AiParserService::class)->parseCheap('Ankara Ostim - İzmir Aliağa 800 kg koli 05321234567');

        $this->assertSame('Ankara Ostim', $r['pickup_location']);
        $this->assertSame('İzmir Aliağa', $r['delivery_location']);
        $this->assertSame(800, $r['weight']);
        $this->assertNull($r['price']);
    }

    public function test_message_without_route_falls_back(): void
    {
        $r = app(AiParserService::class)->parseCheap('Yükümüz hazır, tenteli arayanlar 0532 123 45 67');

        $this->assertFalse($r['success']);
    }
}
