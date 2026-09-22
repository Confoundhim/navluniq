<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Support\BodyTypes;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Şoför ilan filtreleri: platform ilanları (loads) ve dış kaynak ilanları (scraped_loads) için ortak süzme.
 * Filtre yapısı JSON olarak kalıcı profillerde saklanır; normalize() güvenli biçime indirger.
 */
class LoadFilterService
{
    public const RADII = [10, 25, 50, 100, 250];

    public const SORTS = ['newest' => 'En yeni', 'pickup_date' => 'Yükleme tarihi', 'price_desc' => 'Fiyat (yüksek)', 'distance' => 'Mesafe (yakın)'];

    public const WITHIN_DAYS = ['' => 'Fark etmez', '0' => 'Bugün', '1' => 'Yarına kadar', '3' => '3 gün içinde', '7' => 'Bu hafta'];

    public static function defaults(): array
    {
        return [
            'vehicle_mode' => 'mine',        // mine: aracımın taşıyabildikleri · any: tümü · custom: seçtiklerim
            'vehicle_types' => [],
            'body_types' => [],              // seçili kasa tipleri (boş: hepsi); ilan kasa belirtmemişse gizlenmez
            'load_kind' => '',               // '' hepsi · komple · parca
            'pickup_provinces' => [],
            'delivery_provinces' => [],
            'goods_keywords' => '',
            'min_weight' => null,
            'max_weight' => null,
            'min_price' => null,
            'only_priced' => false,
            'pickup_within_days' => '',
            'near_radius_km' => null,        // null: kapalı
            'near_lat' => null,
            'near_lng' => null,
            'near_label' => '',
            'sort' => 'newest',
        ];
    }

    /** Kullanıcı girdisini bilinen anahtar ve tiplere indirger. */
    public static function normalize(array $input): array
    {
        $d = self::defaults();
        $out = $d;
        $out['vehicle_mode'] = in_array($input['vehicle_mode'] ?? '', ['mine', 'any', 'custom'], true) ? $input['vehicle_mode'] : 'mine';
        $out['vehicle_types'] = array_values(array_filter((array) ($input['vehicle_types'] ?? []), fn ($v) => VehicleTypes::isValid((string) $v)));
        $out['body_types'] = BodyTypes::clean($input['body_types'] ?? []);
        $out['load_kind'] = array_key_exists((string) ($input['load_kind'] ?? ''), BodyTypes::LOAD_KINDS) ? (string) $input['load_kind'] : '';
        foreach (['pickup_provinces', 'delivery_provinces'] as $key) {
            $out[$key] = array_values(array_unique(array_filter(array_map('intval', (array) ($input[$key] ?? [])), fn ($c) => $c >= 1 && $c <= 81)));
        }
        $out['goods_keywords'] = mb_substr(trim((string) ($input['goods_keywords'] ?? '')), 0, 120);
        foreach (['min_weight', 'max_weight', 'min_price'] as $key) {
            $v = $input[$key] ?? null;
            $out[$key] = ($v === null || $v === '') ? null : max(0, (int) $v);
        }
        $out['only_priced'] = (bool) ($input['only_priced'] ?? false);
        $within = (string) ($input['pickup_within_days'] ?? '');
        $out['pickup_within_days'] = array_key_exists($within, self::WITHIN_DAYS) ? $within : '';
        $radius = (int) ($input['near_radius_km'] ?? 0);
        $out['near_radius_km'] = in_array($radius, self::RADII, true) ? $radius : null;
        $lat = $input['near_lat'] ?? null;
        $lng = $input['near_lng'] ?? null;
        if ($out['near_radius_km'] !== null && is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $out['near_lat'] = round((float) $lat, 6);
            $out['near_lng'] = round((float) $lng, 6);
            $out['near_label'] = mb_substr(trim((string) ($input['near_label'] ?? '')), 0, 60);
        } else {
            $out['near_radius_km'] = null;
        }
        $out['sort'] = array_key_exists((string) ($input['sort'] ?? ''), self::SORTS) ? (string) $input['sort'] : 'newest';
        if ($out['sort'] === 'distance' && $out['near_radius_km'] === null) {
            $out['sort'] = 'newest';
        }

        return $out;
    }

    /** Aktif filtre sayısı (varsayılandan sapanlar). */
    public static function activeCount(array $f): int
    {
        $n = 0;
        $n += $f['vehicle_mode'] !== 'mine' ? 1 : 0;
        $n += $f['body_types'] !== [] ? 1 : 0;
        $n += $f['load_kind'] !== '' ? 1 : 0;
        $n += $f['pickup_provinces'] !== [] ? 1 : 0;
        $n += $f['delivery_provinces'] !== [] ? 1 : 0;
        $n += $f['goods_keywords'] !== '' ? 1 : 0;
        $n += ($f['min_weight'] !== null || $f['max_weight'] !== null) ? 1 : 0;
        $n += ($f['min_price'] !== null || $f['only_priced']) ? 1 : 0;
        $n += $f['pickup_within_days'] !== '' ? 1 : 0;
        $n += $f['near_radius_km'] !== null ? 1 : 0;

        return $n;
    }

    /** Ekranda gösterilecek kısa özet rozetleri. */
    public static function chips(array $f): array
    {
        $chips = [];
        $chips[] = match ($f['vehicle_mode']) {
            'any' => 'Tüm araç tipleri',
            'custom' => implode(', ', array_map(fn ($t) => VehicleTypes::label($t), $f['vehicle_types'])) ?: 'Araç tipi seçilmedi',
            default => 'Aracıma uygun',
        };
        if ($f['body_types'] !== []) {
            $chips[] = 'Kasa: '.implode(' / ', array_map(fn ($b) => BodyTypes::short($b), $f['body_types']));
        }
        if ($f['load_kind'] !== '') {
            $chips[] = BodyTypes::LOAD_KINDS[$f['load_kind']];
        }
        $prov = fn (array $codes) => implode(', ', array_map(fn ($c) => TurkishLocations::province($c)['name'] ?? $c, $codes));
        if ($f['pickup_provinces'] !== []) {
            $chips[] = 'Çıkış: '.$prov($f['pickup_provinces']);
        }
        if ($f['delivery_provinces'] !== []) {
            $chips[] = 'Varış: '.$prov($f['delivery_provinces']);
        }
        if ($f['near_radius_km'] !== null) {
            $chips[] = ($f['near_label'] !== '' ? $f['near_label'] : 'Konumum').' çevresi '.$f['near_radius_km'].' km';
        }
        if ($f['min_weight'] !== null || $f['max_weight'] !== null) {
            $chips[] = 'Tonaj '.($f['min_weight'] !== null ? number_format($f['min_weight'] / 1000, 1, ',', '.') : '0').'–'.($f['max_weight'] !== null ? number_format($f['max_weight'] / 1000, 1, ',', '.') : '∞').' ton';
        }
        if ($f['min_price'] !== null) {
            $chips[] = 'En az '.number_format($f['min_price'], 0, ',', '.').' ₺';
        } elseif ($f['only_priced']) {
            $chips[] = 'Yalnız fiyatlı';
        }
        if ($f['pickup_within_days'] !== '') {
            $chips[] = self::WITHIN_DAYS[$f['pickup_within_days']];
        }
        if ($f['goods_keywords'] !== '') {
            $chips[] = 'Yük: '.$f['goods_keywords'];
        }

        return $chips;
    }

    /** Aracıma uygun tipler: aktif aracın kapasitesini aşmayan tüm tipler. */
    public static function typesForVehicle(?string $vehicleType): array
    {
        if (! VehicleTypes::isValid($vehicleType)) {
            return array_keys(VehicleTypes::TYPES);
        }

        return array_values(array_filter(array_keys(VehicleTypes::TYPES), fn ($t) => VehicleTypes::canCarry($vehicleType, $t)));
    }

    public function applyToLoads(Builder $q, array $f, ?DriverProfile $profile): Builder
    {
        $this->applyCommon($q, $f, $profile, allowUnknownVehicle: false);
        $this->applyWeightPrice($q, $f, priceNullable: false);

        if ($f['pickup_within_days'] !== '') {
            $q->whereBetween('pickup_date', [now()->startOfDay(), now()->addDays((int) $f['pickup_within_days'])->endOfDay()]);
        }

        return $this->applySort($q, $f, 'published_at');
    }

    public function applyToScraped(Builder $q, array $f, ?DriverProfile $profile): Builder
    {
        $this->applyCommon($q, $f, $profile, allowUnknownVehicle: true);
        $this->applyWeightPrice($q, $f, priceNullable: true);

        return $this->applySort($q, $f, 'created_at');
    }

    private function applyCommon(Builder $q, array $f, ?DriverProfile $profile, bool $allowUnknownVehicle): void
    {
        $types = match ($f['vehicle_mode']) {
            'any' => null,
            'custom' => $f['vehicle_types'] ?: null,
            default => self::typesForVehicle($profile?->activeVehicle()->value('vehicle_type')),
        };
        if ($types !== null) {
            $q->where(function (Builder $w) use ($types, $allowUnknownVehicle): void {
                $w->whereIn('vehicle_type', $types);
                if ($allowUnknownVehicle) {
                    $w->orWhereNull('vehicle_type'); // dış kaynakta tip çözülemediyse gizlenmez
                }
            });
        }
        // Kasa: "Aracıma uygun" kipinde şoförün aracının kasası; "Seçtiklerim"de seçilen kasalar. Kasa belirtmeyen ilan gizlenmez.
        // JSON listede arama LIKE ile yapılır ('"tenteli"'), her veritabanında çalışır.
        $vehicle = $f['vehicle_mode'] === 'mine' ? $profile?->activeVehicle()->first() : null;
        if ($vehicle && $vehicle->body_type && BodyTypes::isValid($vehicle->body_type)) {
            $body = $vehicle->body_type;
            $length = $vehicle->trailer_length;
            $q->where(function (Builder $w) use ($body): void {
                $w->whereNull('body_types')->orWhere('body_types', 'like', '%"'.$body.'"%');
                // Yalnız uzunluk yazan ilan ("13.60") her kasaya açıktır
                $w->orWhere(function (Builder $k): void {
                    foreach (BodyTypes::KINDS as $kind) {
                        $k->where('body_types', 'not like', '%"'.$kind.'"%');
                    }
                });
            });
            if ($length === 'kisa') {
                $q->where(fn (Builder $w) => $w->whereNull('body_types')->orWhere('body_types', 'not like', '%"uzun_dorse"%')->orWhere('body_types', 'like', '%"kisa_dorse"%'));
            } elseif ($length === 'uzun') {
                $q->where(fn (Builder $w) => $w->whereNull('body_types')->orWhere('body_types', 'not like', '%"kisa_dorse"%')->orWhere('body_types', 'like', '%"uzun_dorse"%'));
            }
        }
        if ($f['body_types'] !== []) {
            $q->where(function (Builder $w) use ($f): void {
                $w->whereNull('body_types');
                foreach ($f['body_types'] as $b) {
                    $w->orWhere('body_types', 'like', '%"'.$b.'"%');
                }
            });
        }
        if ($f['load_kind'] !== '') {
            $q->where(fn (Builder $w) => $w->where('load_kind', $f['load_kind'])->orWhereNull('load_kind'));
        }
        if ($f['pickup_provinces'] !== []) {
            $q->whereIn('pickup_province_code', $f['pickup_provinces']);
        }
        if ($f['delivery_provinces'] !== []) {
            $q->whereIn('delivery_province_code', $f['delivery_provinces']);
        }
        if ($f['goods_keywords'] !== '') {
            $words = array_filter(array_map('trim', preg_split('/[,\s]+/u', $f['goods_keywords']) ?: []));
            $searchRaw = $q->getModel()->getTable() === 'scraped_loads';
            $q->where(function (Builder $w) use ($words, $searchRaw): void {
                foreach ($words as $word) {
                    $w->orWhere('goods_type', 'like', '%'.$word.'%');
                    if ($searchRaw) {
                        $w->orWhere('raw_message', 'like', '%'.$word.'%');
                    }
                }
            });
        }
        if ($f['near_radius_km'] !== null) {
            $this->applyNear($q, (float) $f['near_lat'], (float) $f['near_lng'], (int) $f['near_radius_km']);
        }
    }

    /** Yakınımda: taşınabilir sınırlayıcı kutu, MySQL'de ayrıca kesin haversine. */
    private function applyNear(Builder $q, float $lat, float $lng, int $radiusKm): void
    {
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / max(0.1, 111.0 * cos(deg2rad($lat)));
        $q->whereNotNull('pickup_lat')
            ->whereBetween('pickup_lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('pickup_lng', [$lng - $dLng, $lng + $dLng]);
        if (self::supportsTrig($q)) {
            $q->whereRaw(self::distanceSql('pickup_lat', 'pickup_lng').' <= ?', [$lat, $lat, $lng, $radiusKm]);
        }
    }

    private static function supportsTrig(Builder $q): bool
    {
        return $q->getConnection()->getDriverName() !== 'sqlite';
    }

    private function applyWeightPrice(Builder $q, array $f, bool $priceNullable): void
    {
        if ($f['min_weight'] !== null) {
            $q->where('weight', '>=', $f['min_weight']);
        }
        if ($f['max_weight'] !== null) {
            $q->where(fn (Builder $w) => $w->where('weight', '<=', $f['max_weight'])->orWhereNull('weight'));
        }
        if ($f['min_price'] !== null) {
            $q->where('price', '>=', $f['min_price']);
        } elseif ($f['only_priced'] && $priceNullable) {
            $q->whereNotNull('price')->where('price', '>', 0);
        }
    }

    private function applySort(Builder $q, array $f, string $newestColumn): Builder
    {
        return match ($f['sort']) {
            'price_desc' => $q->orderByDesc('price')->latest('id'),
            'pickup_date' => $q->getModel()->getTable() === 'loads' ? $q->orderBy('pickup_date')->latest('id') : $q->latest('id'),
            'distance' => $f['near_radius_km'] !== null
                ? (self::supportsTrig($q)
                    ? $q->orderByRaw(self::distanceSql('pickup_lat', 'pickup_lng').' asc', [$f['near_lat'], $f['near_lat'], $f['near_lng']])->latest('id')
                    : $q->orderByRaw('((pickup_lat - ?) * (pickup_lat - ?) + (pickup_lng - ?) * (pickup_lng - ?)) asc', [$f['near_lat'], $f['near_lat'], $f['near_lng'], $f['near_lng']])->latest('id'))
                : $q->latest($newestColumn)->latest('id'),
            default => $q->latest($newestColumn)->latest('id'),
        };
    }

    /** Haversine (km); bağlamalar sırası: lat, lat, lng. Trigonometrik fonksiyon gerektirir (MySQL/MariaDB). */
    public static function distanceSql(string $latCol, string $lngCol): string
    {
        return "(6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS({$latCol} - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS({$latCol})) * POWER(SIN(RADIANS({$lngCol} - ?) / 2), 2))))";
    }
}
