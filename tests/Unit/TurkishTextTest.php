<?php

namespace Tests\Unit;

use App\Support\TurkishText;
use PHPUnit\Framework\TestCase;

class TurkishTextTest extends TestCase
{
    public function test_turkish_letters_are_cased_correctly(): void
    {
        $this->assertSame('İstanbul Kartal', TurkishText::title('İSTANBUL KARTAL'));
        $this->assertSame('Isparta (Karağaç)', TurkishText::title('ISPARTA (KARAĞAÇ)'));
        $this->assertSame('Şırnak Silopi', TurkishText::title('şırnak silopi'));
        $this->assertSame('Balıkesir Merkez', TurkishText::title('BALIKESİR MERKEZ'));
        $this->assertSame('Paletli yük', TurkishText::sentence('PALETLİ YÜK'));
        $this->assertSame('İzolasyon malzemesi', TurkishText::sentence('İZOLASYON MALZEMESİ'));
        $this->assertSame('Buzdolabı ve çamaşır makinesi', TurkishText::sentence('BUZDOLABI VE ÇAMAŞIR MAKİNESİ'));
        $this->assertSame('ADR kimyasal', TurkishText::sentence('ADR KİMYASAL')); // kısaltma korunur
        $this->assertSame('Kömür', TurkishText::sentence('‼️ KÖMÜR ‼️ 🔥')); // süs karakterleri atılır
        $this->assertNull(TurkishText::sentence('🔥🔥'));
        $this->assertNull(TurkishText::title(null));
        $this->assertSame('izmir', TurkishText::lower('İZMİR'));
        $this->assertSame('ISPARTA', TurkishText::upper('ısparta'));
    }
}
