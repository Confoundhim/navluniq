<?php

namespace App\Support;

use App\Services\AiParserService;

/**
 * "Tek yükleme, çok boşaltma noktası" ilan serisi (sektörde sık): bir başlık satırı kalkışı ve yükü verir
 * ("Ç.KALE ÇAN TORBA KÖMÜR YÜKLER"), altındaki her satır ayrı bir boşaltma noktasıdır ve her nokta ayrı araçtır
 * ("BURDUR AĞLASUN BOŞALTIR", "BURDUR BUCAK BOŞALTIR" …). Bloklar süs çizgisiyle ayrılır, başlık her blokta
 * tekrarlanabilir, araç/kasa notu ("DAMPERLİ ARAÇLAR") blok sonunda olabilir, telefonlar en sondadır.
 *
 * İkinci biçim (lojistik firmalarının "il / ilçe" listesi): "SAMSUN YÜKLEMELERİ" başlığı, araç/yük notları, irtibat satırları,
 * sonra alt alta yalnız yer adı taşıyan satırlar ("Amasya / MERZİFON", "Ankara / KAZAN", "Iğdır / IĞDIR"). Boşaltma fiili yoktur;
 * başlık görüldükten sonra yalnız yer adı taşıyan her satır boşaltma noktasıdır. Başlık bloğundaki notlar (kasa, yük) her noktaya
 * taşınır; irtibat bloğundaki adlar taşınmaz (numaralar mesaj düzeyinde toplanır).
 *
 * Her boşaltma satırı ayrı ilan adayı olur: başlık + satır + blok notları + numaralar. Genel ayırıcı bu düzeni
 * yarım tanıyıp satırları birleştiriyor ve 15 ilan sınırında kesiyordu; yapay zeka da çağrılmaz (kural yeterli).
 */
final class SeriesAd
{
    /** Bir mesajdan en fazla bu kadar nokta açılır (40 noktalı kömür ilanları, 80 ilçelik gübre listeleri görülüyor). */
    public const MAX_ADS = 120;

    /** En az bu kadar boşaltma satırı yoksa seri sayılmaz; genel ayırıcı çalışır. */
    public const MIN_DESTINATIONS = 3;

    private const SUMMARY_LINE = '/(?<!\p{L})(?:ilçeleri|ilceleri|ilçelerine|ilcelerine|tüm ilçe|tum ilce|her ilçe|her ilce|ilçelere|ilcelere)(?!\p{L})/u';

    private const PICKUP_SUFFIX = '/(?:^|\s)\p{L}{3,}(?:dan|den|tan|ten)\s*$/u';

    /** Yalnız yer adı taşıyan satırda yer adı dışında kalabilen sözcükler. */
    private const PLACE_ONLY_FILLER = ['merkez', 'merkezi', 'ilce', 'ilcesi', 'il', 'ili', 've', 'ile', 'veya', 'ya', 'da', 'de'];

    /**
     * Varış satırında yer adının yanında olabilen araç/kasa/adet sözcükleri: "ANKARA 2 YER KAPALI TIR", "SAMSUN KAPALI TIR",
     * "ÇANAKKALE TENTELİ KAMYON". Sayılar zaten atılır (2, 13.60).
     */
    private const VEHICLE_FILLER = ['yer', 'arac', 'araclar', 'adet', 'tir', 'tirlar', 'kamyon', 'kamyonet', 'panelvan', 'kirkayak', 'cekici', 'dorse', 'dorseli',
        'kapali', 'tenteli', 'tente', 'acik', 'frigo', 'frigorifik', 'damperli', 'damper', 'lowbed', 'silobas', 'kisa', 'uzun', 'liftli', 'lift', 'ton', 'tonluk',
        'parsiyel', 'komple', 'yuk', 'yukler', 'var', 'lazim', 'aranan', 'araniyor', 'olur', 'uygun', 'm', 'mt', 'metre', 'teker', 'tekerli', 'dingil'];

    /** Mesajda kalkış bulunduğunu gösteren işaretler yoksa ve satırlar "yer + araç" biçimindeyse: kalkışsız varış listesi. */
    public static function isDestinationListWithoutPickup(string $prepared): bool
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $prepared) ?: []), fn ($l) => $l !== '' && AiParserService::phonesIn($l) === []));
        $destLines = 0;
        $placeLines = 0;
        foreach ($lines as $line) {
            $lower = TurkishCities::lower($line);
            if (preg_match(AiParserService::PICKUP_VERBS, $lower) === 1 || preg_match(self::PICKUP_SUFFIX, $lower) === 1 || preg_match(AiParserService::DELIVERY_VERBS, $lower) === 1
                || AiParserService::connectorMatches($line) !== [] || preg_match('/^(.{2,40}?)\s*(?:=>|=|→|->)\s*(.{2,60})$/u', $line) === 1) {
                return false; // kalkış/varış fiili ya da rota bağlacı var: olağan ilan
            }
            $places = AiParserService::placesIn($line, 2);
            if ($places === []) {
                continue;
            }
            $placeLines++;
            if (count($places) === 1 && self::isPlaceOnly($line, $places) && preg_match('/(?<!\p{L})(?:'.implode('|', array_filter(self::VEHICLE_FILLER, fn ($w) => strlen($w) >= 3)).')(?!\p{L})/u', TurkishCities::ascii($line)) === 1) {
                $destLines++;
            }
        }

        return $destLines >= 2 && $destLines === $placeLines;
    }

    /**
     * Hazırlanmış metin (TextPrep::prepare) seri düzenindeyse aday parçaları döner; değilse null.
     *
     * @return list<array{text:string, phones:list<string>, index:int, count:int, series:array{count:int, pickup:string, delivery:string}}>|null
     */
    public static function segments(string $prepared, ?string $fallbackPhone = null): ?array
    {
        $blocks = [];
        $current = self::emptyBlock();
        $headerCount = 0;
        $placeLines = 0;
        $destLines = 0;
        foreach (preg_split('/\n/u', $prepared) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $blocks[] = $current;
                $current = self::emptyBlock();

                continue;
            }
            if (AiParserService::phonesIn($line) !== []) {
                continue; // numaralar mesaj düzeyinde toplanır
            }
            $lower = TurkishCities::lower($line);
            $places = AiParserService::placesIn($line, 2);
            if ($places !== []) {
                $placeLines++;
            }
            // Üçüncü biçim: "KIZILTEPE = ADAPAZARI DİLOVASI" (kalkış = varış; sol taraf her satırda aynı). "=" / "=>" / "→" ayracı.
            if (preg_match('/^(.{2,40}?)\s*(?:=>|=|→|->)\s*(.{2,60})$/u', $line, $eq) === 1) {
                $left = AiParserService::placesIn(trim($eq[1]), 1);
                $right = AiParserService::placesIn(trim($eq[2]), 2);
                if ($left !== [] && $right !== []) {
                    if ($current['pickup'] !== $left[0]['label']) {
                        if ($current['dests'] !== []) {
                            $blocks[] = $current;
                            $current = self::emptyBlock();
                        }
                        $current['header'] = trim($eq[1]);
                        $current['pickup'] = $left[0]['label'];
                        $headerCount++;
                    }
                    $destLines++; // yer satırı yukarıda sayıldı
                    $current['dests'][] = ['line' => trim($eq[2]), 'label' => self::destinationLabel(trim($eq[2]), $right[0]), 'summary' => false];

                    continue;
                }
            }
            $hasPickupVerb = preg_match(AiParserService::PICKUP_VERBS, $lower) === 1 || preg_match(self::PICKUP_SUFFIX, $lower) === 1;
            // Varış satırı: "X boşaltır / iner" ya da (başlık görüldükten sonra) yalnız yer adı taşıyan satır ("Amasya / MERZİFON")
            $isDest = $places !== [] && ! $hasPickupVerb
                && (preg_match(AiParserService::DELIVERY_VERBS, $lower) === 1 || ($headerCount > 0 && self::isPlaceOnly($line, $places)));
            $isHeader = ! $isDest && $places !== [] && (preg_match(AiParserService::PICKUP_VERBS, $lower) === 1 || preg_match(self::PICKUP_SUFFIX, $lower) === 1);
            if ($isHeader) {
                if ($current['dests'] !== []) {
                    $blocks[] = $current;
                    $current = self::emptyBlock();
                }
                $current['header'] = $line;
                $current['pickup'] = $places[0]['label'];
                // "GEBZE+TUZLA YÜKLER", "Gebze / Tuzla yükler", "Gebze veya Tuzla yükler": iki ayrı kalkış seçeneği; her nokta her kalkıştan ayrı ilan
                $current['pickups'] = self::alternativePickups($line, $places);
                $headerCount++;
            } elseif ($isDest) {
                $destLines++;
                $current['dests'][] = ['line' => $line, 'label' => self::destinationLabel($line, $places[0]), 'summary' => preg_match(self::SUMMARY_LINE, $lower) === 1];
            } else {
                $current['notes'][] = $line;
            }
        }
        $blocks[] = $current;

        // Seri: en az bir kalkış başlığı ve yeterli sayıda "X boşaltır" satırı; yer satırlarının büyük çoğunluğu boşaltma satırı.
        // Başlık dışındaki her yer satırı varış satırıysa ("GEBZE+TUZLA YÜKLER / ANKARA 2 YER KAPALI TIR / ANTALYA 2 YER KAPALI TIR") iki nokta yeter.
        $minDestinations = $destLines === $placeLines - $headerCount ? 2 : self::MIN_DESTINATIONS;
        if ($headerCount === 0 || $destLines < $minDestinations || $destLines < ($placeLines - $headerCount) * 0.8) {
            return null;
        }

        $phones = AiParserService::phonesIn($prepared);
        if ($fallbackPhone !== null && ! in_array($fallbackPhone, $phones, true)) {
            $phones[] = $fallbackPhone;
        }
        $phoneLine = $phones !== [] ? "\n☎️ ".implode(', ', array_map(fn (string $p) => Phone::format($p), $phones)) : '';

        $items = [];
        $header = null;
        $pickup = null;
        $pickups = [];
        $headerNotes = []; // varışsız başlık bloğunun notları ("AÇIK TENTE DAMPER TIR") sonraki varış bloklarına taşınır
        $leadingNotes = []; // başlıksız giriş bloğunun notları ("PRESLİ SAMAN YÜKLEME KAPALI TENTE ARAÇLAR YÜKLER.") ilk başlığa taşınır
        foreach ($blocks as $block) {
            if ($block['header'] === null && $block['dests'] === []) {
                $leadingNotes = array_merge($leadingNotes, $block['notes']);

                continue;
            }
            if ($block['header'] !== null) {
                $header = $block['header'];
                $pickup = $block['pickup'];
                $pickups = $block['pickups'] ?? [$pickup];
                $headerNotes = array_values(array_unique(array_merge($leadingNotes, $block['dests'] === [] ? array_values(array_filter($block['notes'], fn ($n) => $n !== $header)) : [])));
                $leadingNotes = [];
            }
            if ($block['dests'] === [] || $header === null) {
                continue;
            }
            // "BURDUR İLÇELERİ BOŞALTIR" özet satırı: ilçeler tek tek sayıldıysa atlanır, yoksa il düzeyinde tek ilan olur.
            $dests = array_values(array_filter($block['dests'], fn ($d) => ! $d['summary']));
            if ($dests === []) {
                $dests = $block['dests'];
            }
            $notes = array_values(array_unique(array_merge($headerNotes, array_filter($block['notes'], fn ($n) => $n !== $header))));
            foreach ($dests as $dest) {
                foreach ($pickups as $from) {
                    // İki kalkışlı başlıkta her ilanın metni kendi kalkışını söyler (aynı metin tekrar sayılmasın; şoför hangi kalkış olduğunu görsün)
                    $items[] = [
                        'text' => $header."\n".$dest['line'].(count($pickups) > 1 ? "\nKalkış: ".$from : '').($notes !== [] ? "\n".implode("\n", $notes) : '').$phoneLine,
                        'phones' => $phones,
                        'pickup' => $from,
                        'delivery' => $dest['label'],
                    ];
                }
            }
        }
        $items = array_slice($items, 0, self::MAX_ADS);
        $count = count($items);
        if ($count < $minDestinations) {
            return null;
        }

        return array_values(array_map(fn (array $item, int $i) => [
            'text' => $item['text'],
            'phones' => $item['phones'],
            'index' => $i,
            'count' => $count,
            'series' => ['count' => $count, 'pickup' => $item['pickup'], 'delivery' => $item['delivery']],
        ], $items, array_keys($items)));
    }

    /**
     * Varış etiketi: il+ilçe çözüldüyse o; yalnız il çözüldüyse satırdaki fazladan sözcük (katalogda olmayan ilçe/belde,
     * "BURDUR ALTINYAYLA" gibi) etikete eklenir ki aynı ilin her satırı ayrı nokta kalsın ve ad kaybolmasın.
     *
     * @param  array{label:string, district:?string, text:string}  $place
     */
    private static function destinationLabel(string $line, array $place): string
    {
        if (! empty($place['district'])) {
            return $place['label'];
        }
        $rest = preg_replace(AiParserService::DELIVERY_VERBS, ' ', TurkishCities::lower($line)) ?? '';
        $rest = preg_replace('/(?<!\p{L})'.preg_quote(TurkishCities::lower($place['text']), '/').'(?!\p{L})/u', ' ', $rest) ?? $rest;
        $rest = preg_replace(self::SUMMARY_LINE, ' ', $rest) ?? $rest;
        $words = array_values(array_filter(preg_split('/[^\p{L}]+/u', $rest) ?: [], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['merkez', 'ilçe', 'ilce', 've', 'ile'], true)));

        return count($words) === 1 ? $place['label'].' '.TurkishText::title($words[0]) : $place['label'];
    }

    /**
     * Satır yalnız yer adından mı oluşuyor ("Amasya / MERZİFON", "Ankara / KAZAN", "Iğdır / IĞDIR", "Bolu Gerede")?
     * Yer adı sözcükleri ve aynı ilin ilçe/semt adları atılınca geriye dolgu sözcük ya da noktalama dışında bir şey kalmamalı.
     *
     * @param  list<array{label:string, province:string, district:?string, text:string}>  $places
     */
    private static function isPlaceOnly(string $line, array $places): bool
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}]+/u', TurkishCities::ascii($line)) ?: [], fn ($w) => $w !== ''));
        foreach ($words as $word) {
            if (in_array($word, self::PLACE_ONLY_FILLER, true) || in_array($word, self::VEHICLE_FILLER, true)) {
                continue; // "ANKARA 2 YER KAPALI TIR": araç/kasa/adet sözcükleri yer satırını bozmaz
            }
            $known = false;
            foreach ($places as $place) {
                $parts = array_merge(explode(' ', TurkishCities::ascii($place['label'])), [TurkishCities::ascii($place['text'])]);
                if (in_array($word, $parts, true) || array_filter($parts, fn ($p) => $p !== '' && str_starts_with($word, $p) && strlen($word) - strlen($p) <= 4) !== []) {
                    $known = true; // yer adı ya da ekli yazımı ("merzifona")
                    break;
                }
                // Aynı ilin ilçe / semt / eski adı ("KAZAN" → Kahramankazan)
                if (strlen($word) >= 3 && ($place['province_code'] ?? 0) > 0 && (TurkishLocations::resolve($place['province'].' '.$word, false)['district'] ?? null) !== null) {
                    $known = true;
                    break;
                }
            }
            if (! $known) {
                return false;
            }
        }

        return $words !== [];
    }

    /**
     * Başlıktaki kalkışlar: "GEBZE+TUZLA", "Gebze / Tuzla", "Gebze veya Tuzla" iki seçenektir (ikisinden de yüklenir); "Ankara Kazan" tek yerdir.
     *
     * @param  list<array{label:string, text:string}>  $places
     * @return list<string>
     */
    public static function alternativePickups(string $line, array $places): array
    {
        $labels = array_values(array_unique(array_column($places, 'label')));
        if (count($labels) < 2) {
            return [$places[0]['label']];
        }
        $a = preg_quote(TurkishCities::lower($places[0]['text']), '/');
        $b = preg_quote(TurkishCities::lower($places[1]['text']), '/');
        $joined = preg_match('/'.$a.'\s*(?:\+|\/|veya|ve|ya da)\s*'.$b.'/u', TurkishCities::lower($line)) === 1;

        return $joined ? array_slice($labels, 0, 2) : [$places[0]['label']];
    }

    /** @return array{header:?string, pickup:?string, pickups?:list<string>, dests:list<array{line:string,label:string,summary:bool}>, notes:list<string>} */
    private static function emptyBlock(): array
    {
        return ['header' => null, 'pickup' => null, 'dests' => [], 'notes' => []];
    }
}
