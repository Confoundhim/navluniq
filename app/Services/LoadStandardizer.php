<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Support\BodyTypes;
use App\Support\ForeignPlaces;
use App\Support\GoodsCatalog;
use App\Support\TextPrep;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use App\Support\VehicleTypes;

/**
 * Ham WhatsApp ilanını ve ayrıştırıcı çıktısını (kalıp ya da yapay zeka) tek bir standart ilana çevirir.
 *
 * - Konum: il/ilçe kataloğuna bağlanır, yazım hataları düzeltilir ("Diyarbakr" → "Diyarbakır"),
 *   il/ilçe kodu ve koordinat yazılır. Standart yazım: "İl" ya da "İl İlçe".
 * - Yük: kataloğa bağlanır (Beyaz eşya, Paletli yük…); kataloğa girmeyen metin temizlenip başlık yapılır.
 * - Araç: sınıflandırıcı (araç adı → kasa ipucu → tonaj → palet → hacim); hiçbiri yoksa yükten en küçük uygun araç.
 *   Açık araç adı yapay zeka tahminine üstün gelir.
 * - Fiyat: "45 bin", "45k", "fiyat: 45000" gibi yazımlar tanınır. Aciliyet ve yükleme notu işaretlenir.
 *
 * Sonuçta `warnings` yöneticinin dikkat etmesi gereken noktaları taşır (il çözülemedi, araç tahmini…).
 */
class LoadStandardizer
{
    /**
     * @param  array<string, mixed>  $parsed  AiParserService çıktısı
     * @return array<string, mixed> scraped_loads kolonları + warnings + metadata
     */
    public function standardize(string $raw, array $parsed): array
    {
        $norm = VehicleClassifier::normalize($raw);
        // Yük emojileri (🍇 üzüm, 🦴 kemik) normalleştirmede düşer; sözcük olarak eklenir (yük ve kasa çıkarımı için).
        if (($emojiWords = GoodsCatalog::emojiWords($raw)) !== '') {
            $norm = rtrim($norm).' '.$emojiWords.' ';
        }
        $warnings = [];

        // Konumlar
        $pickup = $this->location($parsed['pickup_location'] ?? null);
        $delivery = $this->location($parsed['delivery_location'] ?? null);
        if ($pickup['province_code'] === null && empty($pickup['foreign'])) {
            $warnings[] = 'pickup_unresolved';
        }
        if ($delivery['province_code'] === null && empty($delivery['foreign'])) {
            $warnings[] = 'delivery_unresolved';
        }
        $international = array_filter(['pickup' => ! empty($pickup['foreign']), 'delivery' => ! empty($delivery['foreign'])]);
        if ($pickup['province_code'] !== null && $pickup['province_code'] === $delivery['province_code'] && $pickup['district'] === $delivery['district']) {
            $warnings[] = 'same_route_ends';
        }

        // Yük
        $goods = GoodsCatalog::detect($norm);
        $goodsLabel = $goods['label'] ?? $this->titleCase($parsed['goods_type'] ?? null);
        if ($goodsLabel === null && ! empty($parsed['goods_type'])) {
            $goodsLabel = $this->titleCase($parsed['goods_type']);
        }

        // Tonaj ve fiyat
        $weight = isset($parsed['weight']) && (int) $parsed['weight'] > 0 ? (int) $parsed['weight'] : VehicleClassifier::weightFromText($norm);
        $price = isset($parsed['price']) && (float) $parsed['price'] > 0 ? round((float) $parsed['price'], 2) : $this->priceFromText($norm);
        $priceUnit = $price !== null
            ? (in_array($parsed['price_unit'] ?? null, ['total', 'per_ton'], true) ? $parsed['price_unit'] : AiParserService::priceUnitFromText($raw, $price))
            : null;
        $currency = in_array($parsed['currency'] ?? null, ['TRY', 'USD', 'EUR'], true) ? $parsed['currency'] : 'TRY';

        // Araç
        $vehicle = VehicleClassifier::analyze($raw, $weight);
        $vehicleType = $vehicle['type'];
        $vehicleSource = $vehicle['source'];
        $aiType = VehicleTypes::isValid($parsed['vehicle_type'] ?? null) ? $parsed['vehicle_type'] : null;
        if ($aiType !== null && ($vehicleType === null || $vehicle['confidence'] !== 'high')) {
            $vehicleType = $aiType;
            $vehicleSource = 'ai';
        }
        if ($vehicleType === null && $goods !== null) {
            $vehicleType = $goods['min_vehicle'];
            $vehicleSource = 'goods';
        }
        if ($vehicleType === null) {
            $warnings[] = 'vehicle_unresolved';
        } elseif (! in_array($vehicleSource, ['keyword', 'ai'], true)) {
            $warnings[] = 'vehicle_inferred';
        }

        // Kasa / dorse: açık sözcük > sözlük > "13.60 = damper hariç" > yükten çıkarım; yapay zeka yalnız kural boşsa.
        $body = BodyTypes::detect($norm, $goods['key'] ?? null);
        $bodyTypes = $body['types'];
        $bodySource = $body['source'];
        $aiBodies = BodyTypes::clean($parsed['body_types'] ?? []);
        if ($aiBodies !== [] && ($bodyTypes === [] || ! in_array($bodySource, ['keyword', 'lexicon'], true)) && ! $body['any']) {
            $bodyTypes = $aiBodies;
            $bodySource = 'ai';
        }
        if (in_array($parsed['body_type_source'] ?? null, ['admin'], true) && BodyTypes::clean($parsed['body_types'] ?? []) !== []) {
            $bodyTypes = BodyTypes::clean($parsed['body_types']);
            $bodySource = 'admin';
        }

        // İstenen araç adedi ("2 yer" = 2 ayrı araç), yük biçimi (komple/parça), çoklu teslim ("Çorum+Ankara+Denizli")
        $vehicleCount = BodyTypes::detectVehicleCount($norm) ?? (isset($parsed['vehicle_count']) && (int) $parsed['vehicle_count'] >= 2 ? (int) $parsed['vehicle_count'] : null);
        $loadKind = BodyTypes::detectLoadKind($norm, $weight, $vehicleCount) ?? (in_array($parsed['load_kind'] ?? null, ['komple', 'parca'], true) ? $parsed['load_kind'] : null);
        $stops = $this->deliveryStops($raw, $delivery['label'], $pickup['label']);
        if ($stops === [] && is_array($parsed['delivery_stops'] ?? null)) {
            $stops = array_values(array_filter(array_map(fn ($v) => is_string($v) ? $this->location($v)['label'] : null, $parsed['delivery_stops'])));
        }

        $urgent = (bool) preg_match('/\b(?:acil|acilen|hemen|ivedi|bugun|simdi|derhal)\b/', $norm);
        $pickupNote = $this->pickupNote($norm);

        return [
            'pickup_location' => $pickup['label'],
            'pickup_province_code' => $pickup['province_code'],
            'pickup_district' => $pickup['district'],
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],
            'delivery_location' => $delivery['label'],
            'delivery_province_code' => $delivery['province_code'],
            'delivery_district' => $delivery['district'],
            'delivery_lat' => $delivery['lat'],
            'delivery_lng' => $delivery['lng'],
            'goods_type' => $goodsLabel,
            'vehicle_type' => $vehicleType,
            'vehicle_type_source' => $vehicleSource,
            'body_types' => $bodyTypes !== [] ? $bodyTypes : null,
            'body_type_source' => $bodyTypes !== [] ? $bodySource : null,
            'load_kind' => $loadKind,
            'vehicle_count' => $vehicleCount,
            'delivery_stops' => count($stops) > 1 ? $stops : null,
            'weight' => $weight,
            'price' => $price,
            'price_unit' => $priceUnit,
            'currency' => $currency,
            'warnings' => $warnings,
            'metadata' => array_filter([
                'international' => $international !== [] ? $international : null,
                'goods_category' => $goods['key'] ?? null,
                'goods_traits' => $goods['traits'] ?? [],
                'vehicle_confidence' => $vehicle['confidence'],
                'vehicle_evidence' => $vehicle['evidence'],
                'body_evidence' => $body['evidence'],
                'body_any' => $body['any'],
                'urgent' => $urgent,
                'pickup_note' => $pickupNote,
                'warnings' => $warnings,
            ], fn ($v) => $v !== null && $v !== [] && $v !== false),
        ];
    }

    /** Kayıtlı ilanı yeniden standartlaştırır; yönetici elle düzenlediyse dokunmaz. */
    public function restandardize(ScrapedLoad $load): bool
    {
        $meta = (array) ($load->parse_metadata ?? []);
        if (! empty($meta['admin_edited'])) {
            // Yönetici düzeltmiş: alanlarına dokunulmaz; yalnız yeni eklenen kasa/yük biçimi/teslim noktası boşsa doldurulur.
            return $this->fillBodyFields($load);
        }
        $std = $this->standardize((string) $load->raw_message, [
            'pickup_location' => $load->pickup_location,
            'delivery_location' => $load->delivery_location,
            'goods_type' => $load->goods_type,
            'weight' => $load->weight,
            'price' => $load->price,
            'price_unit' => $load->price_unit,
            'currency' => $load->currency,
            'vehicle_type' => $load->vehicle_type_source === 'ai' ? $load->vehicle_type : null,
            'body_types' => in_array($load->body_type_source, ['ai', 'admin'], true) ? $load->body_types : null,
            'body_type_source' => in_array($load->body_type_source, ['ai', 'admin'], true) ? $load->body_type_source : null,
            'load_kind' => $load->load_kind,
            'vehicle_count' => $load->vehicle_count,
            'delivery_stops' => $load->delivery_stops,
        ]);
        $changes = [];
        foreach (['pickup_location', 'pickup_province_code', 'pickup_district', 'pickup_lat', 'pickup_lng', 'delivery_location', 'delivery_province_code',
            'delivery_district', 'delivery_lat', 'delivery_lng', 'goods_type', 'vehicle_type', 'vehicle_type_source', 'weight', 'price', 'price_unit',
            'body_type_source', 'load_kind', 'vehicle_count'] as $col) {
            if ($std[$col] !== null && (string) $std[$col] !== (string) $load->{$col}) {
                $changes[$col] = $std[$col];
            }
        }
        foreach (['body_types', 'delivery_stops'] as $col) {
            if ($std[$col] !== null && $std[$col] !== (array) ($load->{$col} ?? [])) {
                $changes[$col] = $std[$col];
            }
        }
        $changes['parse_metadata'] = array_merge($meta, $std['metadata']);
        $load->forceFill($changes)->save();

        return count($changes) > 1;
    }

    /** Yönetici düzenlemiş ilanda yalnız boş olan kasa / yük biçimi / araç adedi / teslim noktalarını doldurur. */
    public function fillBodyFields(ScrapedLoad $load): bool
    {
        if ($load->body_types !== null && $load->load_kind !== null) {
            return false;
        }
        $std = $this->standardize((string) $load->raw_message, ['vehicle_type' => $load->vehicle_type, 'weight' => $load->weight]);
        $changes = [];
        if ($load->body_types === null && $std['body_types'] !== null) {
            $changes['body_types'] = $std['body_types'];
            $changes['body_type_source'] = $std['body_type_source'];
        }
        foreach (['load_kind', 'vehicle_count', 'delivery_stops'] as $col) {
            if ($load->{$col} === null && $std[$col] !== null) {
                $changes[$col] = $std[$col];
            }
        }
        if ($changes === []) {
            return false;
        }
        $load->forceFill($changes)->save();

        return true;
    }

    /**
     * "+" ile bağlı teslim noktaları: "Gönen+Merkez", "Çorum+Ankara+Denizli" → tek araç, sırayla boşaltma (dağıtmalı).
     * Alt alta yazılan iller ayrı ilandır (splitSegments); burada yalnız aynı satırdaki "+" zinciri okunur.
     *
     * @return list<string> ilk öğe ana varış
     */
    public function deliveryStops(string $raw, ?string $deliveryLabel, ?string $pickupLabel): array
    {
        $prepared = TextPrep::prepare($raw);
        foreach (preg_split('/\n/u', $prepared) ?: [] as $line) {
            if (! preg_match('/\p{L}[\p{L} ().]*\s*\+\s*\p{L}/u', $line)) {
                continue;
            }
            // Satırın "+" zinciri: "GÖNEN+MERKEZ – TIR – 26 TON" → ["GÖNEN", "MERKEZ – TIR – 26 TON"]
            $tokens = array_map('trim', preg_split('/\s*\+\s*/u', preg_replace(AiParserService::PHONE_PATTERN, ' ', $line) ?? $line) ?: []);
            $stops = [];
            foreach ($tokens as $i => $token) {
                if ($i === 0) {
                    $token = preg_replace('/^.*->\s*/u', '', $token) ?? $token; // "Samsundan 13.60 var -> Çorum" → "Çorum"
                }
                $token = trim(preg_replace('/(?:[–—:|]|\s-\s|(?<=\p{L})-(?=\s)).*$/u', '', $token) ?? $token); // "MERKEZ – TIR – 26 TON" → "MERKEZ"
                if ($token === '') {
                    continue;
                }
                $places = AiParserService::placesIn($token, 3);
                if ($places !== []) {
                    // İlk parçada kalkış/başlık da olabilir ("Samsun'dan 13.60 tenteli var Çorum+…" → Çorum): son yer adı;
                    // sonraki parçalarda ilk yer adı ("Denizli 26 ton" → Denizli).
                    $label = $i === 0 ? $places[array_key_last($places)]['label'] : $places[0]['label'];
                } elseif (preg_match('/^merkez\b/iu', $token) && $stops !== []) {
                    $label = (string) strtok($stops[array_key_last($stops)], ' ').' Merkez';
                } else {
                    $label = $this->titleCase($token);
                }
                if ($label !== null && ! in_array($label, $stops, true) && $label !== $pickupLabel) {
                    $stops[] = $label;
                }
            }
            $stops = array_values(array_filter($stops, fn ($s) => mb_strlen($s) >= 3));
            if (count($stops) >= 2) {
                // Ana varış listenin başına: rota çözümleyicisi ilk noktayı seçtiyse sıra korunur
                if ($deliveryLabel !== null && in_array($deliveryLabel, $stops, true)) {
                    $stops = array_values(array_unique(array_merge([$deliveryLabel], $stops)));
                }

                return $stops;
            }
        }

        return [];
    }

    /**
     * @return array{label:?string, province_code:?int, district:?string, lat:?float, lng:?float}
     */
    private function location(mixed $text): array
    {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            return ['label' => null, 'province_code' => null, 'district' => null, 'lat' => null, 'lng' => null];
        }
        $r = TurkishLocations::resolve($text);
        if ($r === null) {
            // Yurt dışı yer (Erbil, Zaho, Bazargan…): il kodu yok ama koordinat ve "international" işareti var
            if (($f = ForeignPlaces::match($text)) !== null) {
                return ['label' => $f['label'], 'province_code' => null, 'district' => null, 'lat' => $f['lat'], 'lng' => $f['lng'], 'foreign' => true];
            }

            return ['label' => $this->titleCase($text), 'province_code' => null, 'district' => null, 'lat' => null, 'lng' => null];
        }

        return [
            'label' => $r['province'].($r['district'] && $r['district'] !== 'Merkez' ? ' '.$r['district'] : ''),
            'province_code' => $r['province_code'],
            'district' => $r['district'] !== 'Merkez' ? $r['district'] : null,
            'lat' => $r['lat'],
            'lng' => $r['lng'],
        ];
    }

    /** "45 bin", "45bin tl", "45k", "fiyat: 45000", "45.000 tl" → 45000. */
    public function priceFromText(string $norm): ?float
    {
        if (preg_match('/(?<![\d.,])(\d{1,3}(?:[.,]\d{1,2})?)\s*(?:bin|k)\b(?!\s*(?:ton|kg|km|palet|adet|koli))/', $norm, $m)) {
            $v = (float) str_replace(',', '.', $m[1]) * 1000;

            return $v >= 500 ? round($v, 2) : null;
        }
        if (preg_match('/(?<![\d.])(\d{1,3}(?:\.\d{3})+|\d{4,7})(?:,(\d{1,2}))?\s*(?:tl|lira|₺)\b/', $norm, $m)) {
            $v = (float) str_replace('.', '', $m[1]) + (isset($m[2]) ? (float) ('0.'.$m[2]) : 0.0);

            return $v >= 500 ? round($v, 2) : null;
        }
        if (preg_match('/\b(?:fiyat[i]?|navlun|ucret[i]?)\s*[:=]?\s*(\d{1,3}(?:\.\d{3})+|\d{4,7})\b/', $norm, $m)) {
            $v = (float) str_replace('.', '', $m[1]);

            return $v >= 500 ? round($v, 2) : null;
        }

        return null;
    }

    private function pickupNote(string $norm): ?string
    {
        $map = ['bugun' => 'Bugün', 'yarin' => 'Yarın', 'hemen' => 'Hemen', 'pazartesi' => 'Pazartesi', 'sali' => 'Salı', 'carsamba' => 'Çarşamba',
            'persembe' => 'Perşembe', 'cuma' => 'Cuma', 'cumartesi' => 'Cumartesi', 'pazar' => 'Pazar', 'hafta sonu' => 'Hafta sonu', 'sabah' => 'Sabah', 'aksam' => 'Akşam'];
        foreach ($map as $k => $label) {
            if (preg_match('/\b'.preg_quote($k, '/').'\b/', $norm)) {
                return $label;
            }
        }
        if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?\b/', $norm, $m) && (int) $m[1] <= 31 && (int) $m[2] <= 12) {
            return sprintf('%02d.%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    private function titleCase(mixed $text): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_convert_case(mb_substr($clean, 0, 120), MB_CASE_TITLE, 'UTF-8');
    }
}
