<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Support\ForeignPlaces;
use App\Support\GoodsCatalog;
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
            return false;
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
        ]);
        $changes = [];
        foreach (['pickup_location', 'pickup_province_code', 'pickup_district', 'pickup_lat', 'pickup_lng', 'delivery_location', 'delivery_province_code',
            'delivery_district', 'delivery_lat', 'delivery_lng', 'goods_type', 'vehicle_type', 'vehicle_type_source', 'weight', 'price', 'price_unit'] as $col) {
            if ($std[$col] !== null && (string) $std[$col] !== (string) $load->{$col}) {
                $changes[$col] = $std[$col];
            }
        }
        $changes['parse_metadata'] = array_merge($meta, $std['metadata']);
        $load->forceFill($changes)->save();

        return count($changes) > 1;
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
