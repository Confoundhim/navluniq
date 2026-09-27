<?php

namespace Tests\Unit;

use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * İl/ilçe yazım biçimleri: gruplarda görülen kısaltma, ayrık yazım, Türkçe karaktersiz, ekli, eski adlı, semt/OSB
 * yazımları doğru il ve ilçeye gitmeli; gündelik sözcükler ve yakın adlar yanlış ile gitmemeli. Liste docs/IL_ILCE_ESLESTIRME.md.
 */
class LocationSpellingTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string}> yazım, beklenen il, beklenen ilçe (null: yalnız il) */
    public static function spellings(): iterable
    {
        // Noktalı il kısaltmaları
        yield 'Ç.KALE' => ['Ç.KALE', 'Çanakkale', null];
        yield 'K.MARAŞ' => ['K.MARAŞ', 'Kahramanmaraş', null];
        yield 'Ş.URFA' => ['Ş.URFA', 'Şanlıurfa', null];
        yield 'D.BAKIR' => ['D.BAKIR', 'Diyarbakır', null];
        yield 'G.ANTEP' => ['G.ANTEP', 'Gaziantep', null];
        yield 'K.KALE' => ['K.KALE', 'Kırıkkale', null];
        yield 'T.DAĞ' => ['T.DAĞ', 'Tekirdağ', null];
        yield 'B.KESİR' => ['B.KESİR', 'Balıkesir', null];
        yield 'E.ŞEHİR' => ['E.ŞEHİR', 'Eskişehir', null];
        yield 'N.ŞEHİR' => ['N.ŞEHİR', 'Nevşehir', null];
        yield 'A.KARAHİSAR' => ['A.KARAHİSAR', 'Afyonkarahisar', null];
        yield 'K.MONU' => ['K.MONU', 'Kastamonu', null];
        yield 'G.HANE' => ['G.HANE', 'Gümüşhane', null];
        // Noktasız kısaltmalar ve kısa adlar
        yield 'GANTEP' => ['GANTEP', 'Gaziantep', null];
        yield 'KMARAŞ' => ['KMARAŞ', 'Kahramanmaraş', null];
        yield 'KKALE' => ['KKALE', 'Kırıkkale', null];
        yield 'ZONG' => ['ZONG', 'Zonguldak', null];
        yield 'İST' => ['İST', 'İstanbul', null];
        yield 'ANK' => ['ANK', 'Ankara', null];
        yield 'AFYON' => ['AFYON', 'Afyonkarahisar', null];
        yield 'URFA' => ['URFA', 'Şanlıurfa', null];
        yield 'ANTEP' => ['ANTEP', 'Gaziantep', null];
        yield 'MARAŞ' => ['MARAŞ', 'Kahramanmaraş', null];
        yield 'İZMİT' => ['İZMİT', 'Kocaeli', null];
        // Ayrık yazımlar
        yield 'KAHRAMAN MARAŞ' => ['KAHRAMAN MARAŞ', 'Kahramanmaraş', null];
        yield 'KAHRAMAN MARAŞ ELBİSTAN' => ['KAHRAMAN MARAŞ ELBİSTAN', 'Kahramanmaraş', 'Elbistan'];
        yield 'GAZİ ANTEP' => ['GAZİ ANTEP', 'Gaziantep', null];
        yield 'KIRIK KALE' => ['KIRIK KALE', 'Kırıkkale', null];
        yield 'ŞANLI URFA' => ['ŞANLI URFA', 'Şanlıurfa', null];
        yield 'AFYON KARAHİSAR' => ['AFYON KARAHİSAR', 'Afyonkarahisar', null];
        yield 'BURSA MUSTAFA KEMAL PAŞA' => ['BURSA MUSTAFA KEMAL PAŞA', 'Bursa', 'Mustafakemalpaşa'];
        yield 'İSTANBUL SULTAN BEYLİ' => ['İSTANBUL SULTAN BEYLİ', 'İstanbul', 'Sultanbeyli'];
        // Türkçe karaktersiz / küçük harf / ekli
        yield 'sanliurfa viransehir' => ['sanliurfa viransehir', 'Şanlıurfa', 'Viranşehir'];
        yield 'izmirden aliagaya' => ['izmirden aliagaya', 'İzmir', 'Aliağa'];
        yield "Ankara'dan" => ["Ankara'dan", 'Ankara', null];
        yield 'DİYARBAKIR BİSMİL' => ['DİYARBAKIR BİSMİL', 'Diyarbakır', 'Bismil'];
        yield 'canakkale gokceada' => ['canakkale gokceada', 'Çanakkale', 'Gökçeada'];
        // Yazım hataları
        yield 'DİYARBAKR' => ['DİYARBAKR', 'Diyarbakır', null];
        yield 'istanbl' => ['istanbl', 'İstanbul', null];
        yield 'Konya Eregli' => ['Konya Eregli', 'Konya', 'Ereğli'];
        // İlçe kısaltmaları (il bilinirken)
        yield 'ISPARTA Ş.KARAAĞAÇ' => ['ISPARTA Ş.KARAAĞAÇ', 'Isparta', 'Şarkikaraağaç'];
        yield 'KARAMAN K.KARABEKİR' => ['KARAMAN K.KARABEKİR', 'Karaman', 'Kazımkarabekir'];
        yield 'KARAMAN KKARABEKİR' => ['KARAMAN KKARABEKİR', 'Karaman', 'Kazımkarabekir'];
        yield 'İSTANBUL K.ÇEKMECE' => ['İSTANBUL K.ÇEKMECE', 'İstanbul', 'Küçükçekmece'];
        yield 'İSTANBUL B.ÇEKMECE' => ['İSTANBUL B.ÇEKMECE', 'İstanbul', 'Büyükçekmece'];
        yield 'İSTANBUL G.O.PAŞA' => ['İSTANBUL G.O.PAŞA', 'İstanbul', 'Gaziosmanpaşa'];
        yield 'İSTANBUL S.BEYLİ' => ['İSTANBUL S.BEYLİ', 'İstanbul', 'Sultanbeyli'];
        yield 'BURSA M.KEMALPAŞA' => ['BURSA M.KEMALPAŞA', 'Bursa', 'Mustafakemalpaşa'];
        yield 'TEKİRDAĞ Ç.KÖY' => ['TEKİRDAĞ Ç.KÖY', 'Tekirdağ', 'Çerkezköy'];
        yield 'İZMİR K.YAKA' => ['İZMİR K.YAKA', 'İzmir', 'Karşıyaka'];
        yield 'İZMİR K.PAŞA' => ['İZMİR K.PAŞA', 'İzmir', 'Kemalpaşa'];
        yield 'ANKARA K.HAMAM' => ['ANKARA K.HAMAM', 'Ankara', 'Kızılcahamam'];
        yield 'ANKARA Y.MAHALLE' => ['ANKARA Y.MAHALLE', 'Ankara', 'Yenimahalle'];
        yield 'ANKARA Ş.KOÇHİSAR' => ['ANKARA Ş.KOÇHİSAR', 'Ankara', 'Şereflikoçhisar'];
        yield 'ANKARA B.PAZARI' => ['ANKARA B.PAZARI', 'Ankara', 'Beypazarı'];
        // Eski adlar, semtler, OSB ve limanlar (ilçe düzeyinde)
        yield 'Ankara Kazan' => ['Ankara Kazan', 'Ankara', 'Kahramankazan'];
        yield 'İstanbul Eyüp' => ['İstanbul Eyüp', 'İstanbul', 'Eyüpsultan'];
        yield 'İstanbul Büyükada' => ['İstanbul Büyükada', 'İstanbul', 'Adalar'];
        yield 'Ankara Ostim' => ['Ankara Ostim', 'Ankara', 'Yenimahalle'];
        yield 'Kocaeli Gebze OSB' => ['Kocaeli Gebze OSB', 'Kocaeli', 'Gebze'];
        yield 'İstanbul Hadımköy' => ['İstanbul Hadımköy', 'İstanbul', 'Arnavutköy'];
        yield 'Hatay Cilvegözü' => ['Hatay Cilvegözü', 'Hatay', 'Reyhanlı'];
        yield 'Adapazarı' => ['Adapazarı', 'Sakarya', null];
        yield 'Antakya' => ['Antakya', 'Hatay', null];
        yield 'Gebze' => ['Gebze', 'Kocaeli', 'Gebze'];
        yield 'Mecitözünden' => ['Mecitözünden', 'Çorum', 'Mecitözü'];
        yield 'Tekkeköyünden' => ['Tekkeköyünden', 'Samsun', 'Tekkeköy'];
        yield 'Rize Pazar' => ['Rize Pazar', 'Rize', 'Pazar'];
        yield 'Samsun Çarşamba' => ['Samsun Çarşamba', 'Samsun', 'Çarşamba'];
        // Düzeltilen ilçe tablosu
        yield 'Aksaray Ağaçören' => ['Aksaray Ağaçören', 'Aksaray', 'Ağaçören'];
        yield 'Burdur Altınyayla' => ['Burdur Altınyayla', 'Burdur', 'Altınyayla'];
        yield 'Sivas Altınyayla' => ['Sivas Altınyayla', 'Sivas', 'Altınyayla'];
        yield 'Konya Emirgazi' => ['Konya Emirgazi', 'Konya', 'Emirgazi'];
        yield 'İstanbul Tuzla' => ['İstanbul Tuzla', 'İstanbul', 'Tuzla'];
        yield 'Denizli Merkezefendi' => ['Denizli Merkezefendi', 'Denizli', 'Merkezefendi'];
    }

    #[DataProvider('spellings')]
    public function test_spelling_variants_resolve_to_the_right_province_and_district(string $text, string $province, ?string $district): void
    {
        $r = TurkishLocations::resolve($text);
        $this->assertNotNull($r, "Çözülemedi: {$text}");
        $this->assertSame($province, $r['province'], "İl yanlış: {$text}");
        if ($district !== null) {
            $this->assertSame($district, $r['district'], "İlçe yanlış: {$text}");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonPlaces(): iterable
    {
        foreach (['kadar', 'burda', 'sonra', 'Kahraman', 'kamyon', 'tenteli', 'yükleme', 'acil', 'sanayi', 'liman', 'merkez', 'PAZAR GÜNÜ', 'Çarşamba günü', 'cuma akşamı', 'pazartesi sabah'] as $w) {
            yield $w => [$w];
        }
    }

    #[DataProvider('nonPlaces')]
    public function test_everyday_words_are_not_mistaken_for_places(string $text): void
    {
        $this->assertNull(TurkishCities::fromText($text), "İl sanıldı: {$text}");
        $this->assertNull(TurkishLocations::resolve($text)['district'] ?? null, "İlçe sanıldı: {$text}");
    }

    public function test_near_names_never_go_to_the_wrong_province(): void
    {
        // "Kahraman Maraş" Karaman değil; "Kırık Kale" Denizli Kale değil; "Sivas Altınyayla" Burdur değil.
        $this->assertSame('Kahramanmaraş', TurkishLocations::resolve('KAHRAMAN MARAŞ')['province']);
        $this->assertSame(['Kırıkkale', null], [TurkishLocations::resolve('KIRIK KALE')['province'], TurkishLocations::resolve('KIRIK KALE')['district']]);
        $this->assertSame(58, TurkishLocations::resolve('Sivas Altınyayla')['province_code']);
        $this->assertSame(['name' => 'Kahramanmaraş', 'tokens' => 2], TurkishCities::match('Kahraman Maraş Elbistan'));
        $this->assertNull(TurkishCities::abbreviation('kadar', 4));
        $this->assertSame('Kırıkkale', TurkishCities::abbreviation('k.ale', 3));
        $this->assertNull(TurkishCities::abbreviation('x.zzz', 3));
        $this->assertNull(TurkishCities::abbreviation('k.eli', 3), 'Kırklareli / Kocaeli: birden çok il uyarsa çözülmez');
        $this->assertNull(TurkishLocations::resolve('K.ELİ'));
    }
}
