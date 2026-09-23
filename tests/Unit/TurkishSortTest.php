<?php

namespace Tests\Unit;

use App\Support\TurkishLocations;
use App\Support\TurkishText;
use PHPUnit\Framework\TestCase;

class TurkishSortTest extends TestCase
{
    public function test_turkish_alphabet_order(): void
    {
        $names = ['Şereflikoçhisar', 'Çubuk', 'Yenimahalle', 'Çankaya', 'Akyurt', 'Ilgaz', 'İzmir', 'Ümraniye', 'Uşak', 'Ağaçören', 'Adana'];
        usort($names, [TurkishText::class, 'compare']);
        $this->assertSame(['Adana', 'Ağaçören', 'Akyurt', 'Çankaya', 'Çubuk', 'Ilgaz', 'İzmir', 'Şereflikoçhisar', 'Uşak', 'Ümraniye', 'Yenimahalle'], $names);
    }

    public function test_districts_are_unique_and_in_turkish_order(): void
    {
        $ankara = TurkishLocations::districtsOf(6);
        $this->assertSame($ankara, array_values(array_unique($ankara)), 'Takma adlar listeyi çoğaltmaz');
        $this->assertLessThan(array_search('Elmadağ', $ankara, true), array_search('Çankaya', $ankara, true));
        $this->assertContains('Kahramankazan', $ankara);
    }
}
