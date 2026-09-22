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
            'pattern' => '/\b(?:donuk|dondurulmus|donmus|sogutmali|soguk\s+zincir|tavuk|pilic|et\b|kirmizi\s+et|balik(?!esir)|sut\s+urun\w*|peynir|yogurt|dondurma|donma)\w*/'],
        'canli_hayvan' => ['label' => 'Canlı hayvan', 'min_vehicle' => '8_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:canli\s+hayvan|buyukbas|kucukbas|koyun|kuzu|sigir|dana|inek|keci|hayvan\s+yuku)\b/'],
        'kemik' => ['label' => 'Kemik / hayvansal yan ürün', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:kemik|kemikler|kemik\s+yuk\w*|rendering|hayvansal\s+yan\s+urun\w*|mezbaha\s+atik\w*)\w*/'],
        'komur' => ['label' => 'Kömür', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:komur|komurler|torbali\s+komur|cuvalli\s+komur|dokme\s+komur|linyit|petrokok|pet\s?kok|kok\s+komur\w*|antrasit)\w*/'],
        'maden_dokme' => ['label' => 'Dökme maden / cevher', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:klink[ae]r|klinger|pomza|micir|mucur|cur[uü]f|grit\b|maden\s+topra\w*|dokme\s+maden|cevher|krom\b|manyezit|feldspat|kuvars|perlit|bentonit|zeolit|dolomit|kalsit|kaolin|boksit|silis\s+kum\w*|dokme\s+malzeme)\w*/'],
        'tuz' => ['label' => 'Tuz', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:tuz|dokme\s+tuz|torbali\s+tuz|yol\s+tuzu|kaya\s+tuzu)\b\w*/'],
        'gubre' => ['label' => 'Gübre', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:gubre|kemre|kemresi|tavuk\s+gubre\w*|hayvan\s+gubre\w*|kompost|dap\b|ure\b|amonyum)\w*/'],
        'lastik' => ['label' => 'Lastik', 'min_vehicle' => '10_teker_kamyon', 'traits' => [],
            'pattern' => '/\b(?:lastik|lastikler|omca\s+lastik|hurda\s+lastik|lastik\s+dokme)\w*/'],
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
            'pattern' => '/\b(?:saman|pres\s+saman|yem\b|yemi\b|cuvalli\s+yem|bugday|arpa|misir|tahil|pamuk|ayciceg|silaj|yonca|balya|balle|balye|seker\s+pancar\w*|pancar|kepek|kuspe|tohum|soya|kanola|fistik|tutun)\w*/'],
        'orman_kagit' => ['label' => 'Ağaç / kağıt hammaddesi', 'min_vehicle' => 'tir', 'traits' => [],
            'pattern' => '/\b(?:tahta\s+cips\w*|agac\s+cips\w*|cips\b|yonga|talas|tahta\s+parca\w*|kabuk|odun\s+parca\w*)\w*/'],
        'meyve_sebze' => ['label' => 'Meyve / sebze', 'min_vehicle' => 'kamyonet', 'traits' => ['cold'],
            'pattern' => '/\b(?:meyve|sebze|narenciye|portakal|mandalina|limon|domates|patates|sogan|karpuz|kavun|elma|uzum|kiraz|kayisi|seftali|muz|nar\b|hiyar|salatalik|biber|patlican|kabak|marul|yas\s+sebze|havuc|lahana|incir|erik|armut|cilek|kivi|avokado|mevsim\s+sebze\w*)\w*/'],
        'tavuk_yumurta' => ['label' => 'Tavuk / yumurta', 'min_vehicle' => '10_teker_kamyon', 'traits' => ['cold'],
            'pattern' => '/\b(?:canli\s+tavuk|pilic|yumurta|civciv|tavuk\s+eti|kanatli)\w*/'],
        'kagit_pecete' => ['label' => 'Kağıt / peçete', 'min_vehicle' => 'tir', 'traits' => [],
            'pattern' => '/\b(?:pecete|tuvalet\s+kagi\w*|havlu\s+kagi\w*|bebek\s+bezi|hijyen)\w*/'],
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
    /**
     * İlanlarda yük yerine emoji kullanılır ("Buldan/Antalya 🍇🍇🍇", "🦴 kemik"). Emoji → yük sözcüğü (ASCII).
     * Normalleştirme emojileri attığından bu sözcükler metne ayrıca eklenir.
     */
    public const EMOJI_GOODS = [
        '🍇' => 'uzum', '🍅' => 'domates', '🍉' => 'karpuz', '🍈' => 'kavun', '🍊' => 'mandalina', '🍋' => 'limon', '🍎' => 'elma', '🍏' => 'elma',
        '🍐' => 'armut', '🍑' => 'seftali', '🍒' => 'kiraz', '🍓' => 'cilek', '🥝' => 'kivi', '🍌' => 'muz', '🥔' => 'patates', '🧅' => 'sogan',
        '🌽' => 'misir', '🌾' => 'bugday', '🥕' => 'havuc', '🥒' => 'salatalik', '🌶' => 'biber', '🍆' => 'patlican', '🥬' => 'marul', '🥦' => 'sebze',
        '🍯' => 'bal', '🥚' => 'yumurta', '🐔' => 'canli tavuk', '🐄' => 'buyukbas', '🐑' => 'koyun', '🐟' => 'balik', '🦴' => 'kemik', '🪵' => 'tomruk',
        '🧱' => 'tugla', '🪨' => 'tas', '⛏' => 'maden', '🛢' => 'varil', '📦' => 'koli', '🛋' => 'mobilya', '🧊' => 'donuk',
    ];

    /** Ham metindeki yük emojilerinin sözcük karşılıkları (boşlukla ayrılmış, ASCII). */
    public static function emojiWords(string $raw): string
    {
        $words = [];
        foreach (self::EMOJI_GOODS as $emoji => $word) {
            if (str_contains($raw, $emoji) && ! in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return implode(' ', $words);
    }

    public static function detect(string $normalizedText): ?array
    {
        // Jargon sözlüğü: yöneticinin öğrettiği yük sözcükleri katalog kalıplarından önce gelir.
        if (($lex = Lexicon::matchGoods($normalizedText)) !== null && isset(self::CATEGORIES[$lex['canonical']])) {
            $meta = self::CATEGORIES[$lex['canonical']];

            return ['key' => $lex['canonical'], 'label' => $meta['label'], 'min_vehicle' => $meta['min_vehicle'], 'traits' => $meta['traits'], 'matched' => $lex['term']];
        }
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
