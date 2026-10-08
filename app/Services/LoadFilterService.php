<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Load;
use App\Support\BodyTypes;
use App\Support\Geo;
use App\Support\GoodsCatalog;
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

    /** Paylaşım tazeliği (dış kaynak: son görülme; NavlunIQ ilanı: yayın anı). */
    public const SEEN_WITHIN_HOURS = ['' => 'Fark etmez', '1' => 'Son 1 saat', '6' => 'Son 6 saat', '24' => 'Bugün (24 saat)', '72' => 'Son 3 gün'];

    /** Dorse boyu süzgeci: ilan boy yazmıyorsa gizlenmez. */
    public const TRAILER_LENGTHS = ['' => 'Fark etmez', 'kisa' => 'Kısa dorse', 'uzun' => 'Uzun dorse (13.60)'];

    /** Hızlı tonaj aralıkları (kg). */
    public const WEIGHT_PRESETS = ['0-3500' => '≤ 3,5 ton', '3500-10000' => '3,5–10 ton', '10000-18000' => '10–18 ton', '18000-' => '18 ton ve üstü'];

    /**
     * Ton başı fiyatlı ilanın araç başı karşılığı (SQL): fiyat × tonaj; tonaj yazmıyorsa 24 ton sayılır. Süzgeç ve sıralama bu değere bakar
     * ki "2.450 ₺/ton" yazan dökme ilanı "en az 20.000 ₺" süzgecinde kaybolmasın ve fiyat sıralamasında dibe batmasın.
     */
    public const EFFECTIVE_PRICE_SQL = "CASE WHEN price_unit = 'per_ton' THEN price * COALESCE(weight, 24000) / 1000 ELSE price END";

    public static function defaults(): array
    {
        return [
            'vehicle_mode' => 'mine',        // mine: aracımın taşıyabildikleri · any: tümü · custom: seçtiklerim
            'vehicle_types' => [],
            'body_types' => [],              // seçili kasa tipleri (boş: hepsi); ilan kasa belirtmemişse gizlenmez
            'load_kind' => '',               // '' hepsi · komple · parca
            'pickup_provinces' => [],
            'delivery_provinces' => [],
            'pickup_districts' => [],        // il kodu → ilçe adları (boş: ilin tamamı)
            'delivery_districts' => [],
            'goods_keywords' => '',
            'min_weight' => null,
            'max_weight' => null,
            'min_price' => null,
            'max_price' => null,
            'only_priced' => false,
            'urgent' => false,               // yalnız "acil" yazan ilanlar
            'trailer_length' => '',          // '' fark etmez · kisa · uzun (boy yazmayan ilan gizlenmez)
            'seen_within_hours' => '',       // paylaşım tazeliği
            'goods_categories' => [],        // yük kataloğu etiketleri ("Kömür", "Paletli yük"); goods_type birebir
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
        foreach (['pickup', 'delivery'] as $side) {
            $out[$side.'_provinces'] = array_values(array_unique(array_filter(array_map('intval', (array) ($input[$side.'_provinces'] ?? [])), fn ($c) => $c >= 1 && $c <= 81)));
            // İlçeler yalnız seçili illerde ve katalogda olanlar; "İl İlçe" adı değil salt ilçe adı saklanır
            $districts = [];
            foreach ((array) ($input[$side.'_districts'] ?? []) as $code => $names) {
                $code = (int) $code;
                if (! in_array($code, $out[$side.'_provinces'], true)) {
                    continue;
                }
                $valid = TurkishLocations::districtsOf($code);
                $keep = array_values(array_unique(array_filter(array_map(fn ($n) => is_string($n) ? trim($n) : '', (array) $names), fn ($n) => $n !== '' && in_array($n, $valid, true))));
                if ($keep !== []) {
                    $districts[$code] = $keep;
                }
            }
            $out[$side.'_districts'] = $districts;
        }
        $out['goods_keywords'] = mb_substr(trim((string) ($input['goods_keywords'] ?? '')), 0, 120);
        foreach (['min_weight', 'max_weight', 'min_price', 'max_price'] as $key) {
            $v = $input[$key] ?? null;
            $out[$key] = ($v === null || $v === '' || ! is_numeric($v)) ? null : max(0, (int) $v);
        }
        if ($out['min_price'] !== null && $out['max_price'] !== null && $out['max_price'] < $out['min_price']) {
            $out['max_price'] = null;
        }
        $out['only_priced'] = filter_var($input['only_priced'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $out['urgent'] = filter_var($input['urgent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $length = (string) ($input['trailer_length'] ?? '');
        $out['trailer_length'] = array_key_exists($length, self::TRAILER_LENGTHS) ? $length : '';
        $seen = (string) ($input['seen_within_hours'] ?? '');
        $out['seen_within_hours'] = array_key_exists($seen, self::SEEN_WITHIN_HOURS) ? $seen : '';
        $labels = GoodsCatalog::labels();
        $out['goods_categories'] = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', (array) ($input['goods_categories'] ?? [])), fn ($v) => $v !== '' && in_array($v, $labels, true))));
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
        $n += ($f['min_price'] !== null || $f['max_price'] !== null || $f['only_priced']) ? 1 : 0;
        $n += $f['pickup_within_days'] !== '' ? 1 : 0;
        $n += $f['near_radius_km'] !== null ? 1 : 0;
        $n += $f['urgent'] ? 1 : 0;
        $n += $f['trailer_length'] !== '' ? 1 : 0;
        $n += $f['seen_within_hours'] !== '' ? 1 : 0;
        $n += $f['goods_categories'] !== [] ? 1 : 0;

        return $n;
    }

    /** Ekranda gösterilecek kısa özet rozetleri (metin listesi; kaldırılabilir biçim için chipItems). */
    public static function chips(array $f): array
    {
        return array_column(self::chipItems($f), 'label');
    }

    /**
     * Rozetler anahtarıyla: ekranda "×" ile tek dokunuşta kaldırılır (without). İlk rozet her zaman araç kipidir.
     *
     * @return list<array{key:string,label:string}>
     */
    public static function chipItems(array $f): array
    {
        $chips = [];
        $chips[] = ['key' => 'vehicle', 'label' => match ($f['vehicle_mode']) {
            'any' => 'Tüm araç tipleri',
            'custom' => implode(', ', array_map(fn ($t) => VehicleTypes::label($t), $f['vehicle_types'])) ?: 'Araç tipi seçilmedi',
            default => 'Aracıma uygun',
        }];
        if ($f['body_types'] !== []) {
            $chips[] = ['key' => 'body_types', 'label' => 'Kasa: '.implode(' / ', array_map(fn ($b) => BodyTypes::short($b), $f['body_types']))];
        }
        if ($f['trailer_length'] !== '') {
            $chips[] = ['key' => 'trailer_length', 'label' => self::TRAILER_LENGTHS[$f['trailer_length']]];
        }
        if ($f['load_kind'] !== '') {
            $chips[] = ['key' => 'load_kind', 'label' => BodyTypes::LOAD_KINDS[$f['load_kind']]];
        }
        if ($f['pickup_provinces'] !== []) {
            $chips[] = ['key' => 'pickup', 'label' => 'Çıkış: '.self::placesLabel($f['pickup_provinces'], $f['pickup_districts'])];
        }
        if ($f['delivery_provinces'] !== []) {
            $chips[] = ['key' => 'delivery', 'label' => 'Varış: '.self::placesLabel($f['delivery_provinces'], $f['delivery_districts'])];
        }
        if ($f['near_radius_km'] !== null) {
            $chips[] = ['key' => 'near', 'label' => ($f['near_label'] !== '' ? $f['near_label'] : 'Konumum').' çevresi '.$f['near_radius_km'].' km'];
        }
        if ($f['min_weight'] !== null || $f['max_weight'] !== null) {
            $chips[] = ['key' => 'weight', 'label' => 'Tonaj '.($f['min_weight'] !== null ? number_format($f['min_weight'] / 1000, 1, ',', '.') : '0').'–'.($f['max_weight'] !== null ? number_format($f['max_weight'] / 1000, 1, ',', '.') : '∞').' ton'];
        }
        if ($f['min_price'] !== null || $f['max_price'] !== null) {
            $chips[] = ['key' => 'price', 'label' => ($f['min_price'] !== null ? 'En az '.number_format($f['min_price'], 0, ',', '.').' ₺' : '').($f['min_price'] !== null && $f['max_price'] !== null ? ' · ' : '').($f['max_price'] !== null ? 'En çok '.number_format($f['max_price'], 0, ',', '.').' ₺' : '')];
        } elseif ($f['only_priced']) {
            $chips[] = ['key' => 'price', 'label' => 'Yalnız fiyatlı'];
        }
        if ($f['urgent']) {
            $chips[] = ['key' => 'urgent', 'label' => 'Acil'];
        }
        if ($f['seen_within_hours'] !== '') {
            $chips[] = ['key' => 'seen', 'label' => 'Paylaşım: '.self::SEEN_WITHIN_HOURS[$f['seen_within_hours']]];
        }
        if ($f['pickup_within_days'] !== '') {
            $chips[] = ['key' => 'within', 'label' => 'Yükleme: '.self::WITHIN_DAYS[$f['pickup_within_days']]];
        }
        if ($f['goods_categories'] !== []) {
            $chips[] = ['key' => 'goods_categories', 'label' => 'Yük: '.implode(', ', $f['goods_categories'])];
        }
        if ($f['goods_keywords'] !== '') {
            $chips[] = ['key' => 'goods_keywords', 'label' => 'Yük sözcüğü: '.$f['goods_keywords']];
        }

        return $chips;
    }

    /** Rozetteki "×": o süzgeci varsayılana döndürür. */
    public static function without(array $f, string $key): array
    {
        $d = self::defaults();
        $reset = match ($key) {
            'vehicle' => ['vehicle_mode', 'vehicle_types'],
            'pickup' => ['pickup_provinces', 'pickup_districts'],
            'delivery' => ['delivery_provinces', 'delivery_districts'],
            'near' => ['near_radius_km', 'near_lat', 'near_lng', 'near_label'],
            'weight' => ['min_weight', 'max_weight'],
            'price' => ['min_price', 'max_price', 'only_priced'],
            'seen' => ['seen_within_hours'],
            'within' => ['pickup_within_days'],
            default => array_key_exists($key, $d) ? [$key] : [],
        };
        foreach ($reset as $k) {
            $f[$k] = $d[$k];
        }

        return self::normalize($f);
    }

    /** "Adana (Ceyhan, Kozan), Aydın" gibi il + seçili ilçe etiketi; hiç il yoksa "Her yer". */
    public static function placesLabel(array $codes, array $districts = []): string
    {
        if ($codes === []) {
            return 'Her yer';
        }

        return implode(', ', array_map(function ($c) use ($districts) {
            $name = TurkishLocations::province((int) $c)['name'] ?? (string) $c;
            $d = $districts[(int) $c] ?? [];

            return $d !== [] ? $name.' ('.implode(', ', $d).')' : $name;
        }, $codes));
    }

    /** İlanın araç tipi kesin: açık ad, yönetici, yapay zeka, gönderen şablonu ya da şoförün "Aradım, araç:" cevabı. */
    public const EXACT_VEHICLE_SOURCES = ['keyword', 'admin', 'ai', 'template', 'driver'];

    /** İlanın kasa tipi kesin: açık sözcük, sözlük, yönetici, yapay zeka (yükten çıkarılan kasa şoförü eleyemez). */
    public const EXACT_BODY_SOURCES = ['keyword', 'lexicon', 'admin', 'ai'];

    /** Aracıma uygun tipler: aktif aracın kapasitesini aşmayan tüm tipler (araç tipi tahminle çıkarılmış ilanlar için). */
    public static function typesForVehicle(?string $vehicleType): array
    {
        if (! VehicleTypes::isValid($vehicleType)) {
            return array_keys(VehicleTypes::TYPES);
        }

        return array_values(array_filter(array_keys(VehicleTypes::TYPES), fn ($t) => VehicleTypes::canCarry($vehicleType, $t)));
    }

    /**
     * İlan açıkça araç tipi yazıyorsa: aynı sınıf ya da bir alt sınıf. TIR şoförüne "kamyonet" yazan ilan gösterilmez (fiyatı ve
     * yükü ona göre değil); "tır" yazana kırkayak bakabilir. Tahminle çıkarılan araç (yük/tonaj/ipucu) için typesForVehicle geçerlidir.
     */
    public static function exactTypesForVehicle(?string $vehicleType): array
    {
        if (! VehicleTypes::isValid($vehicleType)) {
            return array_keys(VehicleTypes::TYPES);
        }
        $ordered = array_keys(VehicleTypes::TYPES); // kapasiteye göre büyükten küçüğe
        $index = array_search($vehicleType, $ordered, true);

        return array_values(array_slice($ordered, $index, 2));
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

        return $this->applySort($q, $f, 'last_seen_at'); // yeniden paylaşılan dış kaynak ilanı öne gelir
    }

    private function applyCommon(Builder $q, array $f, ?DriverProfile $profile, bool $allowUnknownVehicle): void
    {
        $isScraped = $q->getModel()->getTable() === 'scraped_loads';
        $driverType = $f['vehicle_mode'] === 'mine' ? $profile?->activeVehicle()->value('vehicle_type') : null;
        $types = match ($f['vehicle_mode']) {
            'any' => null,
            'custom' => $f['vehicle_types'] ?: null,
            default => self::typesForVehicle($driverType),
        };
        if ($types !== null) {
            $exact = $f['vehicle_mode'] === 'mine' ? self::exactTypesForVehicle($driverType) : $types;
            $q->where(function (Builder $w) use ($types, $exact, $allowUnknownVehicle, $isScraped): void {
                if ($isScraped && $exact !== $types) {
                    // Açık araç adı yazan ilan: aynı sınıf ya da bir alt sınıf; tahminle çıkarılan (yük/tonaj/ipucu): taşıyabildiği her tip
                    $w->where(fn (Builder $x) => $x->whereIn('vehicle_type', $exact)->whereIn('vehicle_type_source', self::EXACT_VEHICLE_SOURCES))
                        ->orWhere(fn (Builder $x) => $x->whereIn('vehicle_type', $types)->where(fn (Builder $y) => $y->whereNull('vehicle_type_source')->orWhereNotIn('vehicle_type_source', self::EXACT_VEHICLE_SOURCES)));
                } else {
                    $w->whereIn('vehicle_type', $types); // platform ilanı / seçtiklerim: kapasiteye göre
                }
                if ($allowUnknownVehicle) {
                    $w->orWhereNull('vehicle_type'); // dış kaynakta tip çözülemediyse gizlenmez
                }
            });
        }
        // Kasa: "Aracıma uygun" kipinde şoförün aracının kasası; "Seçtiklerim"de seçilen kasalar. Kasa belirtmeyen ilan gizlenmez;
        // dış kaynakta yükten çıkarılan kasa (body_type_source goods) da elemez, yalnız açıkça yazılan kasa eler.
        // JSON listede arama LIKE ile yapılır ('"tenteli"'), her veritabanında çalışır.
        $vehicle = $f['vehicle_mode'] === 'mine' ? $profile?->activeVehicle()->first() : null;
        $softBody = fn (Builder $w) => $isScraped ? $w->orWhereNotIn('body_type_source', self::EXACT_BODY_SOURCES)->orWhereNull('body_type_source') : $w;
        if ($vehicle && $vehicle->body_type && BodyTypes::isValid($vehicle->body_type)) {
            $body = $vehicle->body_type;
            $length = $vehicle->trailer_length;
            $q->where(function (Builder $w) use ($body, $softBody): void {
                $w->whereNull('body_types')->orWhere('body_types', 'like', '%"'.$body.'"%');
                $softBody($w);
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
        // Kuyruk lifti: şoför "liftim yok" dediyse lift isteyen ilanlar gizlenir (belirtmediyse gösterilir)
        if ($vehicle && $vehicle->has_lift === false) {
            $q->where(fn (Builder $w) => $w->whereNull('body_types')->orWhere('body_types', 'not like', '%"liftli"%'));
        }
        if ($f['body_types'] !== []) {
            $q->where(function (Builder $w) use ($f, $softBody): void {
                $w->whereNull('body_types');
                foreach ($f['body_types'] as $b) {
                    $w->orWhere('body_types', 'like', '%"'.$b.'"%');
                }
                $softBody($w);
            });
        }
        if ($f['load_kind'] !== '') {
            $q->where(fn (Builder $w) => $w->where('load_kind', $f['load_kind'])->orWhereNull('load_kind'));
        }
        // İl + ilçe: il seçili ve ilçe seçilmişse yalnız o ilçeler; ilçe seçilmemişse ilin tamamı
        foreach (['pickup', 'delivery'] as $side) {
            if ($f[$side.'_provinces'] === []) {
                continue;
            }
            $q->where(function (Builder $w) use ($f, $side): void {
                foreach ($f[$side.'_provinces'] as $code) {
                    $names = $f[$side.'_districts'][$code] ?? [];
                    $w->orWhere(function (Builder $p) use ($side, $code, $names): void {
                        $p->where($side.'_province_code', $code);
                        if ($names !== []) {
                            $p->whereIn($side.'_district', $names);
                        }
                    });
                }
            });
        }
        // Dorse boyu: yalnız öbür boyu açıkça yazan ilan elenir ("13.60" yazan ilan kısa dorseye, "kısa dorse" yazan uzuna kapalı); boy yazmayan görünür
        if ($f['trailer_length'] !== '') {
            $other = $f['trailer_length'] === 'kisa' ? 'uzun_dorse' : 'kisa_dorse';
            $mine = $f['trailer_length'] === 'kisa' ? 'kisa_dorse' : 'uzun_dorse';
            $q->where(fn (Builder $w) => $w->whereNull('body_types')->orWhere('body_types', 'not like', '%"'.$other.'"%')->orWhere('body_types', 'like', '%"'.$mine.'"%'));
        }
        if ($isScraped && $f['urgent']) {
            $q->where('parse_metadata->urgent', true);
        }
        if ($f['seen_within_hours'] !== '') {
            $since = now()->subHours((int) $f['seen_within_hours']);
            $isScraped ? $q->where('last_seen_at', '>=', $since) : $q->where('published_at', '>=', $since);
        }
        if ($f['goods_categories'] !== []) {
            $q->whereIn('goods_type', $f['goods_categories']);
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

    /**
     * Çıkış yeri verilen ilin içinde YA DA verilen noktaya yarıçap (km) içinde olan ilanlar
     * (dönüş yükü taraması: seferin varış yeri çevresi).
     */
    public function applyPickupAround(Builder $q, ?int $provinceCode, ?float $lat, ?float $lng, int $radiusKm): Builder
    {
        return $q->where(function (Builder $w) use ($provinceCode, $lat, $lng, $radiusKm): void {
            if ($provinceCode) {
                $w->where('pickup_province_code', $provinceCode);
            }
            if ($lat !== null && $lng !== null && $radiusKm > 0) {
                $w->orWhere(fn (Builder $n) => $this->applyNear($n, $lat, $lng, $radiusKm));
            }
            if (! $provinceCode && ($lat === null || $lng === null)) {
                $w->whereRaw('1 = 0');
            }
        });
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
            $q->where(fn (Builder $w) => $w->where('weight', '>=', $f['min_weight'])->orWhereNull('weight')); // tonajı yazılmamış ilan elenmez (max ile aynı)
        }
        if ($f['max_weight'] !== null) {
            $q->where(fn (Builder $w) => $w->where('weight', '<=', $f['max_weight'])->orWhereNull('weight'));
        }
        // Dış kaynakta ton başı fiyat araç başı karşılığıyla karşılaştırılır (EFFECTIVE_PRICE_SQL); NavlunIQ ilanı hep araç başı
        $priceExpr = $priceNullable ? self::EFFECTIVE_PRICE_SQL : 'price';
        if ($f['min_price'] !== null) {
            $q->whereRaw($priceExpr.' >= ?', [$f['min_price']]);
        } elseif ($f['only_priced'] && $priceNullable) {
            $q->whereNotNull('price')->where('price', '>', 0);
        }
        if ($f['max_price'] !== null) {
            $q->where(fn (Builder $w) => $w->whereRaw($priceExpr.' <= ?', [$f['max_price']])->orWhereNull('price')); // fiyatsız ilan "en çok" ile elenmez
        }
    }

    private function applySort(Builder $q, array $f, string $newestColumn): Builder
    {
        $scraped = $q->getModel()->getTable() === 'scraped_loads';

        return match ($f['sort']) {
            'price_desc' => $scraped ? $q->orderByRaw(self::EFFECTIVE_PRICE_SQL.' desc')->latest('id') : $q->orderByDesc('price')->latest('id'),
            'pickup_date' => ! $scraped ? $q->orderBy('pickup_date')->latest('id') : $q->latest($newestColumn)->latest('id'),
            'distance' => $f['near_radius_km'] !== null
                ? (self::supportsTrig($q)
                    ? $q->orderByRaw(self::distanceSql('pickup_lat', 'pickup_lng').' asc', [$f['near_lat'], $f['near_lat'], $f['near_lng']])->latest('id')
                    : $q->orderByRaw('((pickup_lat - ?) * (pickup_lat - ?) + (pickup_lng - ?) * (pickup_lng - ?)) asc', [$f['near_lat'], $f['near_lat'], $f['near_lng'], $f['near_lng']])->latest('id'))
                : $q->latest($newestColumn)->latest('id'),
            default => $q->latest($newestColumn)->latest('id'),
        };
    }

    /** Haversine (km); bağlamalar sırası: lat, lat, lng. Formül tek yerde: App\Support\Geo. */
    public static function distanceSql(string $latCol, string $lngCol): string
    {
        return Geo::distanceSql($latCol, $lngCol);
    }

    /**
     * Tek ilan şoförün süzgecine uyuyor mu? (bildirimde kayıtlı varsayılan filtre: il/ilçe, kasa, tonaj, fiyat, araç).
     * İlan sorguya alınır (whereKey) ve aynı süzgeç uygulanır; böylece liste ile bildirim hiç ayrışmaz.
     */
    public function loadMatches(Load $load, array $filters, ?DriverProfile $profile): bool
    {
        $q = Load::query()->whereKey($load->id);

        return $this->applyToLoads($q, self::normalize($filters), $profile)->exists();
    }
}
