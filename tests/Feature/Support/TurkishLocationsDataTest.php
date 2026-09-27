<?php

namespace Tests\Feature\Support;

use App\Support\TurkishLocations;
use Tests\TestCase;

/** İl/ilçe tablosu resmî listeyle uyumlu kalsın: 81 il, 973 ilçe, il başına doğru sayı, her ilçe tek ve koordinatlı. */
class TurkishLocationsDataTest extends TestCase
{
    private const DISTRICTS_PER_PROVINCE = [1 => 15, 2 => 9, 3 => 18, 4 => 8, 5 => 7, 6 => 25, 7 => 19, 8 => 9, 9 => 17, 10 => 20, 11 => 8, 12 => 8, 13 => 7, 14 => 9, 15 => 11, 16 => 17, 17 => 12, 18 => 12, 19 => 14, 20 => 19, 21 => 17, 22 => 9, 23 => 11, 24 => 9, 25 => 20, 26 => 14, 27 => 9, 28 => 16, 29 => 6, 30 => 5, 31 => 15, 32 => 13, 33 => 13, 34 => 39, 35 => 30, 36 => 8, 37 => 20, 38 => 16, 39 => 8, 40 => 7, 41 => 12, 42 => 31, 43 => 13, 44 => 13, 45 => 17, 46 => 11, 47 => 10, 48 => 13, 49 => 6, 50 => 8, 51 => 6, 52 => 19, 53 => 12, 54 => 16, 55 => 17, 56 => 7, 57 => 9, 58 => 17, 59 => 11, 60 => 12, 61 => 18, 62 => 8, 63 => 13, 64 => 6, 65 => 13, 66 => 14, 67 => 8, 68 => 8, 69 => 3, 70 => 6, 71 => 9, 72 => 6, 73 => 7, 74 => 4, 75 => 6, 76 => 4, 77 => 6, 78 => 6, 79 => 4, 80 => 7, 81 => 8];

    public function test_district_table_matches_the_official_counts(): void
    {
        $data = json_decode((string) file_get_contents(resource_path('data/tr-locations.json')), true);
        $this->assertCount(81, $data['provinces']);
        $this->assertCount(973, $data['districts']);
        $this->assertSame(973, array_sum(self::DISTRICTS_PER_PROVINCE));

        $byProvince = [];
        $seen = [];
        foreach ($data['districts'] as $d) {
            $byProvince[$d['p']] = ($byProvince[$d['p']] ?? 0) + 1;
            $key = $d['p'].'|'.mb_strtolower($d['n']);
            $this->assertArrayNotHasKey($key, $seen, 'Aynı ilde aynı ilçe iki kez: '.$d['n']);
            $seen[$key] = true;
            $this->assertTrue(is_float($d['lat']) && is_float($d['lng']) && $d['lat'] > 35 && $d['lat'] < 43 && $d['lng'] > 25 && $d['lng'] < 45, 'Koordinat Türkiye dışında: '.$d['n']);
        }
        ksort($byProvince);
        $this->assertSame(self::DISTRICTS_PER_PROVINCE, $byProvince);
    }

    public function test_known_misplacements_are_fixed(): void
    {
        $district = fn (string $label) => TurkishLocations::resolve($label, false);
        $this->assertSame([68, 'Ağaçören'], [$district('Aksaray Ağaçören')['province_code'], $district('Aksaray Ağaçören')['district']]);
        $this->assertSame([15, 'Altınyayla'], [$district('Burdur Altınyayla')['province_code'], $district('Burdur Altınyayla')['district']]);
        $this->assertSame([17, 'Gökçeada'], [$district('Çanakkale Gökçeada')['province_code'], $district('Çanakkale Gökçeada')['district']]);
        $this->assertSame([34, 'Tuzla'], [$district('İstanbul Tuzla')['province_code'], $district('İstanbul Tuzla')['district']]);
        $this->assertSame([42, 'Emirgazi'], [$district('Konya Emirgazi')['province_code'], $district('Konya Emirgazi')['district']]);
        $this->assertSame([20, 'Merkezefendi'], [$district('Denizli Merkezefendi')['province_code'], $district('Denizli Merkezefendi')['district']]);
        $this->assertNull($district('Çanakkale Imbros')['district'] ?? null);
    }
}
