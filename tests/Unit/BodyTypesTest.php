<?php

namespace Tests\Unit;

use App\Support\BodyTypes;
use App\Support\VehicleClassifier;
use App\Support\VehicleTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BodyTypesTest extends TestCase
{
    /** Sektör dili: yük verenin yazdığı → şoförün anladığı kasa listesi. */
    public static function bodyCorpus(): array
    {
        return [
            ['13.60 istiyorum', ['tenteli', 'kapali', 'acik', 'frigo', 'uzun_dorse'], 'keyword'], // damper hariç
            ['sadece frigo', ['frigo'], 'keyword'],
            ['sadece damper', ['damperli'], 'keyword'],
            ['sadece sal açık', ['acik'], 'keyword'],
            ['sadece tenteneli', ['tenteli'], 'keyword'],
            ['13.60 tenteli', ['tenteli', 'uzun_dorse'], 'keyword'], // kapalı eklenmez
            ['13.60 kapalı', ['kapali', 'uzun_dorse'], 'keyword'],
            ['kapalı açıkta uyar 13.60', ['kapali', 'acik', 'uzun_dorse'], 'keyword'],
            ['yükümüz dökme yük', ['damperli'], 'keyword'],
            ['dökme yük fakat damper ile 13.60 açık sal dorse uygundur', ['acik', 'damperli', 'uzun_dorse'], 'keyword'],
            ['üzüm kasalı vardır', ['tenteli', 'kapali', 'frigo'], 'keyword'],
            ['mega tenteli araç', ['tenteli', 'uzun_dorse'], 'keyword'],
            ['tentesiz araç lazım', ['acik'], 'keyword'],
            ['kısa dorse damperli', ['damperli', 'kisa_dorse'], 'keyword'],
            ['silobas çimento', ['silobas'], 'keyword'],
            ['liftli kamyon 5 ton', ['liftli'], 'keyword'],
            ['tır (kapalı) 5 ton', ['kapali'], 'keyword'],
            ['forklift ile yüklenir tenteli', ['tenteli'], 'keyword'], // "forklift" liftli sayılmaz
        ];
    }

    #[DataProvider('bodyCorpus')]
    public function test_body_types_follow_sector_language(string $text, array $expected, string $source): void
    {
        $r = BodyTypes::detect(VehicleClassifier::normalize($text));
        $this->assertSame(BodyTypes::clean($expected), $r['types'], $text);
        $this->assertSame($source, $r['source'], $text);
        $this->assertFalse($r['any']);
    }

    public function test_any_body_means_no_restriction(): void
    {
        $r = BodyTypes::detect(VehicleClassifier::normalize('elimizdeki iş her türlü dorseye uygun 24 ton'));
        $this->assertSame([], $r['types']);
        $this->assertTrue($r['any']);

        // Açık sözcük varsa "fark etmez" yalnız o ikisini kapsar
        $r = BodyTypes::detect(VehicleClassifier::normalize('açık kapalı fark etmez'));
        $this->assertSame(['kapali', 'acik'], $r['types']);
        $this->assertFalse($r['any']);
    }

    public function test_goods_infer_body_only_when_nothing_is_written(): void
    {
        $this->assertSame(['damperli'], BodyTypes::detect(VehicleClassifier::normalize('kemik yükümüz var'), 'kemik')['types']);
        $this->assertSame('goods', BodyTypes::detect(VehicleClassifier::normalize('kömür 26 ton'), 'komur')['source']);
        $this->assertSame(['frigo'], BodyTypes::detect(VehicleClassifier::normalize('donuk tavuk'), 'donuk_gida')['types']);
        // Kasa yazılmışsa yük çıkarımı devreye girmez
        $this->assertSame(['tenteli'], BodyTypes::detect(VehicleClassifier::normalize('kömür tenteli'), 'komur')['types']);
        $this->assertSame([], BodyTypes::detect(VehicleClassifier::normalize('yük var'), null)['types']);
    }

    public function test_summary_labels_are_short_and_human(): void
    {
        $this->assertSame('13.60 · damper hariç', BodyTypes::summary(['uzun_dorse', 'tenteli', 'kapali', 'acik', 'frigo']));
        $this->assertSame('Tenteli', BodyTypes::summary(['tenteli']));
        $this->assertSame('13.60 · Tenteli', BodyTypes::summary(['tenteli', 'uzun_dorse']));
        $this->assertSame('Açık / Damper', BodyTypes::summary(['damperli', 'acik']));
        $this->assertSame('Kapalı / Tenteli / Frigo', BodyTypes::summary(['kapali', 'tenteli', 'frigo']));
        $this->assertNull(BodyTypes::summary([]));
        $this->assertNull(BodyTypes::summary(null));
    }

    public function test_load_kind_and_vehicle_count(): void
    {
        $n = fn (string $t) => VehicleClassifier::normalize($t);
        $this->assertSame('parca', BodyTypes::detectLoadKind($n('5 palet parça yükümüz vardır')));
        $this->assertSame('parca', BodyTypes::detectLoadKind($n('parsiyel 3 palet')));
        $this->assertSame('komple', BodyTypes::detectLoadKind($n('komple yük 24 ton'), 24000));
        $this->assertSame('komple', BodyTypes::detectLoadKind($n('26 ton tır'), 26000));
        $this->assertSame('komple', BodyTypes::detectLoadKind($n('tırlık yük')));
        $this->assertNull(BodyTypes::detectLoadKind($n('Ankara İzmir tenteli'), null));

        $this->assertSame(2, BodyTypes::detectVehicleCount($n('SAMSUN 2 YER – TIR – 26 TON'))); // 2 ayrı tır
        $this->assertSame(4, BodyTypes::detectVehicleCount($n('ADANA 4YER+URFA')));
        $this->assertSame(3, BodyTypes::detectVehicleCount($n('3 tır lazım')));
        $this->assertSame(15, BodyTypes::detectVehicleCount($n('Çorlu damperli 15 araç')));
        $this->assertSame(2, BodyTypes::detectVehicleCount($n('iki tır lazım')));
        $this->assertNull(BodyTypes::detectVehicleCount($n('1 araç lazım')));
        $this->assertNull(BodyTypes::detectVehicleCount($n('13.60 tır 26 ton'))); // "60 tır" değil
        $this->assertNull(BodyTypes::detectVehicleCount($n('8.60 kamyon')));
    }

    public function test_vehicle_fits_load_bodies(): void
    {
        $this->assertTrue(BodyTypes::vehicleFits('damperli', null, null));          // ilan kasa belirtmemiş
        $this->assertTrue(BodyTypes::vehicleFits(null, null, ['tenteli']));         // şoför kasasını girmemiş
        $this->assertTrue(BodyTypes::vehicleFits('tenteli', 'uzun', ['tenteli', 'kapali', 'uzun_dorse']));
        $this->assertFalse(BodyTypes::vehicleFits('damperli', null, ['tenteli', 'kapali']));
        $this->assertTrue(BodyTypes::vehicleFits('frigo', 'kisa', ['uzun_dorse', 'kisa_dorse']));
        $this->assertFalse(BodyTypes::vehicleFits('tenteli', 'kisa', ['uzun_dorse']));       // 13.60 isteyen, kısa dorse uymaz
        $this->assertTrue(BodyTypes::vehicleFits('tenteli', 'uzun', ['uzun_dorse']));
        $this->assertTrue(BodyTypes::vehicleFits('damperli', 'uzun', ['tenteli', 'kapali', 'acik', 'frigo', 'damperli']));
        // Lift: ilan lift istiyor → "liftim yok" diyen araç uymaz, belirtmeyen gizlenmez, liftli uyar; lowbed ayrı kasa
        $this->assertFalse(BodyTypes::vehicleFits('tenteli', 'uzun', ['tenteli', 'liftli'], false));
        $this->assertTrue(BodyTypes::vehicleFits('tenteli', 'uzun', ['tenteli', 'liftli'], null));
        $this->assertTrue(BodyTypes::vehicleFits('tenteli', 'uzun', ['tenteli', 'liftli'], true));
        $this->assertTrue(BodyTypes::vehicleFits('lowbed', null, ['lowbed', 'acik']));
        $this->assertFalse(BodyTypes::vehicleFits('tenteli', null, ['lowbed']));
    }

    public function test_body_types_are_bound_to_vehicle_classes(): void
    {
        $this->assertSame('tir', VehicleTypes::classOf('tir'));
        $this->assertSame('kamyon', VehicleTypes::classOf('10_teker_kamyon'));
        $this->assertSame('panelvan', VehicleTypes::classOf('panelvan'));
        $this->assertSame('panelvan', VehicleTypes::canonical('orta_panelvan'));
        $this->assertSame('panelvan', VehicleTypes::canonical('minivan'));
        $this->assertNull(VehicleTypes::classOf('otomobil'));
        $this->assertNull(VehicleTypes::classOf('yok'));
        $this->assertContains('uzun_dorse', BodyTypes::forClass('tir'));
        $this->assertNotContains('uzun_dorse', BodyTypes::forClass('kamyon'));
        $this->assertNotContains('damperli', BodyTypes::forClass('panelvan'));
        $this->assertSame(['kapali', 'frigo'], BodyTypes::kindsOf(BodyTypes::forClass('panelvan')));
        $this->assertSame(['tenteli', 'kapali', 'acik', 'frigo', 'damperli'], BodyTypes::kindsOf(BodyTypes::forClass('kamyon')));
        $this->assertSame(['tenteli', 'kapali', 'acik', 'frigo', 'damperli'], BodyTypes::kindsOf(BodyTypes::forClass('kirkayak')));
        $this->assertSame(['tenteli', 'kapali', 'acik', 'frigo', 'damperli', 'silobas', 'lowbed'], BodyTypes::kindsOf(BodyTypes::forClass('tir')));
        $this->assertContains('liftli', BodyTypes::forClass('kamyonet'));
        $this->assertNotContains('liftli', BodyTypes::forClass('panelvan'));
        $this->assertSame(array_keys(BodyTypes::TYPES), BodyTypes::forClass(null));
        $this->assertSame(['tenteli', 'damperli'], BodyTypes::clean(['damperli', 'tenteli', 'damperli', 'yok', 3]));
    }
}
