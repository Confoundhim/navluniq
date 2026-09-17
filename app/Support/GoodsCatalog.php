<?php

namespace App\Support;

/**
 * Yük türü kataloğu: serbest metindeki yükü standart bir kategoriye bağlar ve
 * araç tipi yazmayan ilanlarda yükten en küçük uygun aracı çıkarır.
 *
 * min_vehicle: bu yükü taşıyabilecek en küçük araç sınıfı; daha büyük tüm araçlar da uygundur
 * (şoför filtresi "aracıma uygun" bu mantıkla çalışır: buzdolabı → panelvan ve üzeri, otomobil hariç).
 * traits: cold (soğuk zincir/frigo), fragile (kırılgan), hazmat (tehlikeli), oversize (gabari dışı/lowbed).
 */
final class GoodsCatalog
{
    /** Sıra önemli: özel kategoriler (donuk gıda, iş makinesi) genel olanlardan (gıda, makine) önce. */
    private const CATEGORIES = [
        'is_makinesi' => ['label' => 'İş makinesi', 'min_vehicle' => 'tir', 'traits' => ['oversize'],
            'pattern' => '/\b(?:is\s?makin[ae]s[iı]|is\s?makin[ae]|kepce|ekskavator|eksvator|forklift|vinc|dozer|greyder|silindir|beko|loder|yukleyici|traktor|bicerdover|kazici)\b/'],
        'arac_tasima' => ['label' => 'Araç taşıma', 'min_vehicle' => 'tir', 'traits' => ['oversize'],
            'pattern' => '/\b(?:arac\s+tasi|oto\s+tasi|otomobil\s+tasi|oto\s?tasiyici|cekme\s+arac|hasarli\s+arac)\w*/'],
        'donuk_gida' => ['label' => 'Soğuk zincir gıda', 'min_vehicle' => '6_teker_kamyon', 'traits' => ['cold'],
            'pattern' => '/\b(?:donuk|dondurulmus|donmus|sogutmali|soguk\s+zincir|tavuk|pilic|et\b|kirmizi\s+et|balik|sut\s+urun\w*|peynir|yogurt|dondurma|donma)\w*/'],
        'canli_hayvan' => ['label' => 'Canlı hayvan', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:canli\s+hayvan|buyukbas|kucukbas|koyun|kuzu|sigir|dana|inek|keci|hayvan\s+yuku)\b/'],
        'mermer_tas' => ['label' => 'Mermer / taş', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:mermer|granit|traverten|blok\s+tas|tas\s+blok|dogal\s+tas|bazalt|andezit)\w*/'],
        'demir_celik' => ['label' => 'Demir / çelik', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:demir|celik|sac\b|saclar|bobin|rulo\s+sac|profil|insaat\s+demiri|nervurlu|kutuk|hadde|boru)\w*/'],
        'hurda' => ['label' => 'Hurda', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\bhurda\w*/'],
        'insaat' => ['label' => 'İnşaat malzemesi', 'min_vehicle' => '6_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:tugla|cimento|kum\b|cakil|micir|kiremit|seramik|fayans|alci|kirec|briket|gazbeton|ytong|bims|parke\s+tas|bordur|kalip|iskele|insaat\s+malzeme\w*|hazir\s+beton|beton)\w*/'],
        'kereste' => ['label' => 'Kereste / orman ürünü', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:kereste|tomruk|sunta|mdf|kontrplak|osb|ahsap|odun|kutuk\s+agac)\w*/'],
        'tarim' => ['label' => 'Tarım ürünü', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:saman|yem\b|bugday|arpa|misir|tahil|pamuk|ayciceg|silaj|gubre|yonca|balya\s+saman|seker\s+pancar\w*|pancar)\w*/'],
        'meyve_sebze' => ['label' => 'Meyve / sebze', 'min_vehicle' => 'kamyonet', 'traits' => ['cold'],
            'pattern' => '/\b(?:meyve|sebze|narenciye|portakal|mandalina|limon|domates|patates|sogan|karpuz|kavun|elma|uzum|kiraz|kayisi|seftali|muz|nar\b|hiyar|salatalik|biber|patlican|kabak|marul|yas\s+sebze)\w*/'],
        'gida' => ['label' => 'Gıda', 'min_vehicle' => 'kamyonet', 'traits' => [],
            'pattern' => '/\b(?:gida|un\b|seker|bakliyat|pirinc|makarna|bulgur|nohut|fasulye|mercimek|yag\b|zeytin|zeytinyag|konserve|icecek|su\b|damacana|mesrubat|cay\b|kuruyemis|findik|fistik|ceviz|bal\b|salca)\w*/'],
        'kimyasal' => ['label' => 'Kimyasal / tehlikeli madde', 'min_vehicle' => '6_teker_kamyon', 'traits' => ['hazmat'],
            'pattern' => '/\b(?:kimyasal|adr\b|tehlikeli\s+madde|asit|boya\b|tiner|solvent|akaryakit|mazot|lpg|tup\b|gaz\s+tup\w*)\w*/'],
        'beyaz_esya' => ['label' => 'Beyaz eşya', 'min_vehicle' => 'orta_panelvan', 'traits' => ['fragile'],
            'pattern' => '/\b(?:beyaz\s+esya|buzdolab\w*|camasir\s+makin\w*|bulasik\s+makin\w*|kurutma\s+makin\w*|firin\b|klima|derin\s+dondurucu|kombi)\w*/'],
        'mobilya' => ['label' => 'Mobilya / ev eşyası', 'min_vehicle' => 'orta_panelvan', 'traits' => ['fragile'],
            'pattern' => '/\b(?:mobilya|koltuk|kanepe|yatak|baza|dolap|gardirop|masa|sandalye|ev\s+esya\w*|evden\s+eve|ofis\s+esya\w*|esya\s+tasi\w*|ev\s+tasi\w*)\w*/'],
        'elektronik' => ['label' => 'Elektronik', 'min_vehicle' => 'minivan', 'traits' => ['fragile'],
            'pattern' => '/\b(?:elektronik|televizyon|tv\b|bilgisayar|monitor|telefon\s+kolisi|beyaz\s+esya\s+elektronik)\w*/'],
        'cam' => ['label' => 'Cam', 'min_vehicle' => '6_teker_kamyon', 'traits' => ['fragile'],
            'pattern' => '/\b(?:cam\b|cam\s+yuk\w*|duz\s+cam|cam\s+kasa\w*|ayna)\b/'],
        'makine' => ['label' => 'Makine / ekipman', 'min_vehicle' => '6_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:makine|makina|ekipman|jenerator|kompresor|tezgah|pres\b|kalip\s+makin\w*|sanayi\s+makin\w*)\w*/'],
        'tekstil' => ['label' => 'Tekstil', 'min_vehicle' => 'kamyonet', 'traits' => [],
            'pattern' => '/\b(?:tekstil|kumas|balya|konfeksiyon|iplik|giyim|hazir\s+giyim|havlu|carsaf|halı|hali\b|kilim)\w*/'],
        'plastik' => ['label' => 'Plastik / hammadde', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:plastik|granul|big\s?bag|bigbag|cuval|hammadde|polietilen|pvc|kaucuk|lastik)\w*/'],
        'kagit' => ['label' => 'Kağıt / ambalaj', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:kagit|karton\s+bobin|oluklu\s+mukavva|mukavva|ambalaj\s+malzeme\w*|bobin\s+kagit)\w*/'],
        'palet' => ['label' => 'Paletli yük', 'min_vehicle' => 'kamyonet', 'traits' => [],
            'pattern' => '/\bpalet\w*/'],
        'koli' => ['label' => 'Koli / paket', 'min_vehicle' => 'minivan', 'traits' => [],
            'pattern' => '/\b(?:koli|paket|kutu|karton|parca\s+yuk|parsiyel\s+koli)\w*/'],
        'evrak' => ['label' => 'Evrak / numune', 'min_vehicle' => 'otomobil', 'traits' => [],
            'pattern' => '/\b(?:evrak|dosya|numune|zarf|belge)\w*/'],
    ];

    /**
     * @return array{key:string, label:string, min_vehicle:string, traits:list<string>, matched:string}|null
     */
    public static function detect(string $normalizedText): ?array
    {
        foreach (self::CATEGORIES as $key => $meta) {
            if (preg_match($meta['pattern'], $normalizedText, $m)) {
                return ['key' => $key, 'label' => $meta['label'], 'min_vehicle' => $meta['min_vehicle'], 'traits' => $meta['traits'], 'matched' => trim($m[0])];
            }
        }

        return null;
    }

    public static function label(string $key): ?string
    {
        return self::CATEGORIES[$key]['label'] ?? null;
    }

    /** @return array<string, string> anahtar → etiket */
    public static function labels(): array
    {
        return array_map(fn ($c) => $c['label'], self::CATEGORIES);
    }
}
