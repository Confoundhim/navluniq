<?php

namespace Tests\Unit;

use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use PHPUnit\Framework\TestCase;

class VehicleTypesAndLocationsTest extends TestCase
{
    public function test_vehicle_type_detection_prefers_keywords_then_weight(): void
    {
        $this->assertSame(['type' => 'tir', 'source' => 'keyword'], VehicleTypes::detect('24 ton palet tenteli lazım', 24000));
        $this->assertSame(['type' => 'kirkayak', 'source' => 'keyword'], VehicleTypes::detect('Kırkayak arayan var mı', null));
        $this->assertSame(['type' => '10_teker_kamyon', 'source' => 'keyword'], VehicleTypes::detect('10 teker kamyon lazım', null));
        $this->assertSame(['type' => '6_teker_kamyon', 'source' => 'keyword'], VehicleTypes::detect('kamyon lazım 5 ton', 5000));
        $this->assertSame(['type' => 'minivan', 'source' => 'keyword'], VehicleTypes::detect('Doblo ile gidecek koli', null));
        $this->assertSame(['type' => 'kamyonet', 'source' => 'weight'], VehicleTypes::detect('3 ton yük var', 3000));
        $this->assertSame(['type' => null, 'source' => null], VehicleTypes::detect('sadece rota yazılmış', null));
        $this->assertTrue(VehicleTypes::canCarry('tir', 'kamyonet'));
        $this->assertFalse(VehicleTypes::canCarry('minivan', 'tir'));
        $this->assertSame(10, count(VehicleTypes::labels()));
    }

    public function test_locations_resolve_province_district_and_distance(): void
    {
        $r = TurkishLocations::resolve('İzmir Aliağa');
        $this->assertSame(35, $r['province_code']);
        $this->assertSame('Aliağa', $r['district']);

        $this->assertSame('Aliağa', TurkishLocations::resolve('Aliağaya')['district']);
        $this->assertSame('Kocaeli', TurkishLocations::resolve('Gebze')['province']);
        $this->assertSame('Kadıköy', TurkishLocations::resolve('Kadıköy')['district']);
        $this->assertNull(TurkishLocations::resolve('Ankaradan Ostim')['district']);
        $this->assertSame(6, TurkishLocations::resolve('Ankaradan Ostim')['province_code']);
        $this->assertNull(TurkishLocations::resolve('Bilinmeyen Yer'));

        $this->assertSame(81, count(TurkishLocations::provinces()));
        $this->assertGreaterThan(25, count(TurkishLocations::districtsOf(34)));
        $this->assertEqualsWithDelta(306, TurkishLocations::distanceKm(40.8, 29.43, 38.8, 26.97), 5);
    }
}
