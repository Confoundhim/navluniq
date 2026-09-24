<?php

namespace Tests\Unit;

use App\Support\VehicleClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VehicleClassifierTest extends TestCase
{
    /** Gerçekçi WhatsApp ilanları → beklenen araç tipi. */
    public static function corpus(): array
    {
        return [
            // TIR ailesi: açık ad
            ["Ankara'dan İzmir'e 24 ton palet yük, tenteli tır lazım 0532 123 45 67", 'tir'],
            ['Ankaradan İzmire 24 ton palet yük tenteli lazım 0532 123 45 67', 'tir'],
            ['TIR LAZIM ACİL GEBZE ÇIKIŞLI ADANA 0533 111 22 33', 'tir'],
            ['Tırla gidecek 26 ton demir İskenderun - Ankara', 'tir'],
            ['Çekici dorse arıyoruz Mersin limanı Konya', 'tir'],
            ['Konteyner yükü Ambarlı → Kayseri 40lık', 'tir'],
            ['Lowbed lazım iş makinası taşınacak Bursa Eskişehir', 'tir'],
            ['13.60 tenteli araç lazım İstanbul Samsun', 'tir'],
            ['Mega araç arıyoruz hacimli yük Çorlu Antalya', 'tir'],
            ['Komple yük var Gaziantep İzmir 0544 222 33 44', 'tir'],
            ['Silobas aranıyor çimento Adana Hatay', 'tir'],
            ['Frigo 22 ton donuk ürün Bandırma Diyarbakır', 'tir'],
            ['33 palet gıda Manisa Kocaeli araç lazım', 'tir'],
            ['90 m3 hacimli yük Bursa Ankara', 'tir'],
            ['Yirmi dört ton mermer Afyon Bursa 0535 666 77 88', 'tir'],
            ['20-25 ton hurda Karabük İzmit', 'tir'],
            ['24t bobin Ereğli Gebze', 'tir'],
            // Kırkayak
            ['Kırkayak lazım 20 ton Aksaray Konya', 'kirkayak'],
            ['4 dingil araç arıyorum 18 ton Malatya Ankara', 'kirkayak'],
            ['kirk ayak arayan Adana Mersin', 'kirkayak'],
            ['22 ton tuğla Eskişehir İstanbul', 'kirkayak'],
            // Kamyon alt tipleri
            ['10 teker kamyon lazım Denizli İzmir', '10_teker_kamyon'],
            ['On teker araç arıyoruz 15 ton Kütahya Bursa', '10_teker_kamyon'],
            ['kamyon lazım 14 ton Sakarya Ankara', '10_teker_kamyon'],
            ['Damperli araç 15 ton kum Yalova Bursa', '10_teker_kamyon'],
            ['8 teker kamyonla gidecek Balıkesir Manisa', '8_teker_kamyon'],
            ['Sekiz teker lazım Trabzon Samsun', '8_teker_kamyon'],
            ['Kamyon aranıyor 10 ton tekstil Bursa Antalya 0533 987 65 43', '8_teker_kamyon'],
            ["Bursa'dan Antalya'ya 12 ton tekstil, kamyon aranıyor 0533 987 65 43", '8_teker_kamyon'],
            ['kamyona yük var Kayseri Sivas', '8_teker_kamyon'],
            ['Tenteli kamyon lazım 7 ton Uşak İzmir', '6_teker_kamyon'],
            ['6 teker kamyon 5 ton mobilya İnegöl Antalya', '6_teker_kamyon'],
            ['Altı teker araç Tokat Amasya', '6_teker_kamyon'],
            ['Kamyon lazım 5 ton Isparta Burdur', '6_teker_kamyon'],
            ['Açık kasa 6 ton demir profil Adapazarı Bolu', '6_teker_kamyon'],
            ['Frigo kamyon 8 ton et Erzurum Ankara', '6_teker_kamyon'],
            // Kamyonet
            ['Kamyonet lazım 2 ton Gebze Bolu 0536 123 45 67', 'kamyonet'],
            ['Kamyonetle gidecek mobilya Ankara Kırıkkale', 'kamyonet'],
            ['Açık kasa araç lazım 3 ton Manisa Turgutlu', 'kamyonet'],
            ['Kapalı kasa kamyonet 2.5 ton Bursa Bilecik', 'kamyonet'],
            ['Pikap lazım küçük yük Antalya Alanya', 'kamyonet'],
            ['3 ton yük var Konya Aksaray', 'kamyonet'],
            ['3,5 tonluk araç Adana Osmaniye', 'kamyonet'],
            // Panelvan
            ['Uzun panelvan lazım 1500 kg koli Ankara Eskişehir', 'panelvan'],
            ['Sprinter uzun şasi arıyoruz Bursa İstanbul', 'panelvan'],
            ['Transit lazım 2 ton koli İzmir Aydın', 'panelvan'],
            ['Panelvan lazım 1 ton koli İstanbul Kocaeli 0537 000 11 22', 'panelvan'],
            ['Ducato ile gidecek 20 koli Ankara Kırşehir', 'panelvan'],
            ['Kapalı kasa 1000 kg elektronik Gebze İstanbul', 'panelvan'],
            // Minivan / otomobil taksonomide yok: hafif ticari her şey panelvan
            ['Doblo lazım 300 kg numune Bursa İzmir', 'panelvan'],
            ['Caddy kangoo fiorino olur 5 koli İstanbul Tekirdağ', 'panelvan'],
            ['Hafif ticari araç lazım Ankara Çankırı', 'panelvan'],
            ['500 kg yük var Antalya Isparta', 'panelvan'],
            ['200 kg paket İzmir Manisa', 'panelvan'],
            // Lowbed tır kasasıdır
            ['Lowbed lazım iş makinesi Ankara Konya', 'tir'],
            // Yanlış pozitif olmamalı
            ['Yükü yarın getir Ankara İzmir', null],
            ['Bitirdik işi teşekkürler', null],
            ['Selam nasılsın', null],
            ['Ankara İzmir yük var fiyat 45.000 TL', null],
        ];
    }

    #[DataProvider('corpus')]
    public function test_classifies_realistic_ads(string $text, ?string $expected): void
    {
        $r = VehicleClassifier::analyze($text);
        $this->assertSame($expected, $r['type'], $text.' → '.json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function test_explicit_vehicle_name_gives_high_confidence_and_weight_is_extracted(): void
    {
        $r = VehicleClassifier::analyze("Ankara'dan İzmir'e 24 ton palet yük, tenteli tır lazım 0532 123 45 67");
        $this->assertSame('high', $r['confidence']);
        $this->assertSame('keyword', $r['source']);
        $this->assertSame(24000, $r['weight_kg']);

        $this->assertSame('keyword', VehicleClassifier::analyze('Tenteli lazım Ankara İzmir')['source']);
        $this->assertSame('weight', VehicleClassifier::analyze('26 ton yük')['source']);
        $this->assertSame('pallet', VehicleClassifier::analyze('33 palet yük')['source']);
        $this->assertSame('volume', VehicleClassifier::analyze('90 m3 yük')['source']);
    }

    public function test_weight_parsing_variants(): void
    {
        $w = fn (string $t) => VehicleClassifier::weightFromText(VehicleClassifier::normalize($t));
        $this->assertSame(24000, $w('24 ton'));
        $this->assertSame(24000, $w('24t'));
        $this->assertSame(24000, $w('24 tn yük'));
        $this->assertSame(24000, $w('24 tonluk'));
        $this->assertSame(3500, $w('3,5 ton'));
        $this->assertSame(25000, $w('20-25 ton'));
        $this->assertSame(25000, $w('20/25 ton'));
        $this->assertSame(24000, $w('24.000 kg'));
        $this->assertSame(1500, $w('1500 kg'));
        $this->assertSame(24000, $w('yirmi dört ton'));
        $this->assertSame(3500, $w('üç buçuk ton'));
        $this->assertNull($w('0532 123 45 67 45.000 TL'));
        $this->assertNull($w('on teker'));
    }
}
