<?php

namespace App\Support;

/**
 * NavlunIQ araç tipi taksonomisi. Kayıt, ilan, dış kaynak ayrıştırma ve filtreler aynı listeyi kullanır.
 */
final class VehicleTypes
{
    /** Sıra ağırdan hafife; anahtar sözcük eşlemesinde bu öncelik kullanılır. */
    public const TYPES = [
        'tir' => ['label' => 'TIR', 'group' => 'agir', 'capacity_kg' => 28000, 'icon' => 'tir',
            'keywords' => ['tır', 'tir ', 'tir,', 'tir.', 'çekici', 'cekici', 'dorse', 'tenteli', 'mega', 'lowbed', '13.60', '13,60', 'frigo dorse', 'kapalı kasa tır']],
        'kirkayak' => ['label' => 'Kırkayak', 'group' => 'agir', 'capacity_kg' => 22000, 'icon' => 'tir',
            'keywords' => ['kırkayak', 'kirkayak', '4 dingil', 'dört dingil']],
        '10_teker_kamyon' => ['label' => '10 Teker Kamyon', 'group' => 'agir', 'capacity_kg' => 16000, 'icon' => 'kamyon',
            'keywords' => ['10 teker', '10teker', 'on teker']],
        '8_teker_kamyon' => ['label' => '8 Teker Kamyon', 'group' => 'agir', 'capacity_kg' => 12000, 'icon' => 'kamyon',
            'keywords' => ['8 teker', '8teker', 'sekiz teker']],
        '6_teker_kamyon' => ['label' => '6 Teker Kamyon', 'group' => 'orta', 'capacity_kg' => 8000, 'icon' => 'kamyon',
            'keywords' => ['6 teker', '6teker', 'altı teker', 'alti teker']],
        'kamyonet' => ['label' => 'Kamyonet', 'group' => 'hafif', 'capacity_kg' => 3500, 'icon' => 'kamyonet',
            'keywords' => ['kamyonet', 'açık kasa', 'acik kasa', 'pikap', 'pick-up', 'pickup']],
        'uzun_panelvan' => ['label' => 'Uzun Panelvan', 'group' => 'hafif', 'capacity_kg' => 2500, 'icon' => 'panelvan',
            'keywords' => ['uzun panelvan', 'panelvan uzun', 'maxi', 'uzun şasi', 'uzun sasi', 'l3h2', 'l4h2', 'jumbo']],
        'orta_panelvan' => ['label' => 'Orta Panelvan', 'group' => 'hafif', 'capacity_kg' => 1500, 'icon' => 'panelvan',
            'keywords' => ['orta panelvan', 'panelvan', 'panel van', 'transit', 'sprinter', 'crafter', 'ducato', 'boxer', 'jumper', 'master', 'daily']],
        'minivan' => ['label' => 'Minivan', 'group' => 'hizli', 'capacity_kg' => 800, 'icon' => 'minivan',
            'keywords' => ['minivan', 'doblo', 'doblö', 'caddy', 'connect', 'kangoo', 'fiorino', 'combo', 'partner', 'berlingo', 'expert', 'vito', 'hafif ticari']],
        'otomobil' => ['label' => 'Otomobil', 'group' => 'hizli', 'capacity_kg' => 400, 'icon' => 'otomobil',
            'keywords' => ['otomobil', 'araba', 'binek', 'sedan', 'hatchback']],
    ];

    public const GROUPS = ['agir' => 'Ağır vasıta', 'orta' => 'Orta ticari', 'hafif' => 'Hafif ticari', 'hizli' => 'Hızlı teslimat'];

    /** Sektörün konuştuğu 5 araç sınıfı (filtre ve form kutuları); alt tipler ayrıntıdır. Sıra: hafiften ağıra. */
    public const CLASSES = [
        'panelvan' => ['label' => 'Panelvan', 'types' => ['otomobil', 'minivan', 'orta_panelvan', 'uzun_panelvan']],
        'kamyonet' => ['label' => 'Kamyonet', 'types' => ['kamyonet']],
        'kamyon' => ['label' => 'Kamyon', 'types' => ['6_teker_kamyon', '8_teker_kamyon', '10_teker_kamyon']],
        'kirkayak' => ['label' => 'Kırkayak', 'types' => ['kirkayak']],
        'tir' => ['label' => 'TIR', 'types' => ['tir']],
    ];

    /** Araç tipinin sınıfı (tır, kırkayak, kamyon, kamyonet, panelvan). */
    public static function classOf(?string $type): ?string
    {
        foreach (self::CLASSES as $class => $meta) {
            if (in_array($type, $meta['types'], true)) {
                return $class;
            }
        }

        return null;
    }

    public static function classLabel(?string $class): string
    {
        return self::CLASSES[$class ?? '']['label'] ?? (string) $class;
    }

    /** @return array<string, string> sınıf → etiket */
    public static function classLabels(): array
    {
        return array_map(fn ($c) => $c['label'], self::CLASSES);
    }

    /** Kayıt ve ilan formlarındaki sıra: hafiften ağıra. */
    public const FORM_ORDER = ['otomobil', 'minivan', 'orta_panelvan', 'uzun_panelvan', 'kamyonet', '6_teker_kamyon', '8_teker_kamyon', '10_teker_kamyon', 'kirkayak', 'tir'];

    /** @return array<string, string> anahtar → etiket, form sırasında */
    public static function labels(): array
    {
        $out = [];
        foreach (self::FORM_ORDER as $key) {
            $out[$key] = self::TYPES[$key]['label'];
        }

        return $out;
    }

    public static function label(?string $key): string
    {
        return self::TYPES[$key ?? '']['label'] ?? (string) $key;
    }

    public static function group(?string $key): ?string
    {
        return self::TYPES[$key ?? '']['group'] ?? null;
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && isset(self::TYPES[$key]);
    }

    /** SVG içi çizim (24x24 viewBox, stroke kullanır). */
    public static function iconPath(string $key): string
    {
        $icons = [
            'tir' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2 7h11v9H2zM13 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="10" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
            'kamyon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h10v9H3zM13 11h4l3 3v2h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="16.5" cy="18" r="1.6"/>',
            'kamyonet' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 9h9v7H3zM12 12h4l2 2v2h-6z"/><circle cx="6.5" cy="18" r="1.5"/><circle cx="15.5" cy="18" r="1.5"/>',
            'panelvan' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 9a1 1 0 011-1h11l4 4v4H3z"/><path stroke-linecap="round" d="M15 8v4h4"/><circle cx="7" cy="17" r="1.5"/><circle cx="16" cy="17" r="1.5"/>',
            'minivan' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 10l2-3h9l4 3v5H4z"/><path stroke-linecap="round" d="M9 7v3M14 7v3"/><circle cx="8" cy="16" r="1.5"/><circle cx="16" cy="16" r="1.5"/>',
            'otomobil' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 11l1.5-4h11L19 11M4 11h16v4H4z"/><circle cx="7.5" cy="16" r="1.5"/><circle cx="16.5" cy="16" r="1.5"/>',
        ];

        return $icons[self::TYPES[$key]['icon'] ?? 'kamyon'] ?? $icons['kamyon'];
    }

    /**
     * Serbest metinden araç tipini çıkarır (VehicleClassifier: araç adı → kasa ipucu → tonaj/palet/hacim).
     *
     * @return array{type:?string, source:?string, confidence:?string}
     */
    public static function detect(string $text, ?int $weightKg = null): array
    {
        $r = VehicleClassifier::analyze($text, $weightKg);

        return ['type' => $r['type'], 'source' => $r['source'], 'confidence' => $r['confidence']];
    }

    /** Tonaja göre en küçük uygun araç. $truckOnly: yalnız kamyon sınıfları arasından seçer. */
    public static function byWeight(int $kg, bool $truckOnly = false): string
    {
        if ($truckOnly) {
            return $kg > 12000 ? '10_teker_kamyon' : ($kg > 8000 ? '8_teker_kamyon' : '6_teker_kamyon');
        }

        return match (true) {
            $kg >= 23000 => 'tir', // 23-24 ton Türkiye'de standart komple tır yüküdür
            $kg > 16000 => 'kirkayak',
            $kg > 12000 => '10_teker_kamyon',
            $kg > 8000 => '8_teker_kamyon',
            $kg > 3500 => '6_teker_kamyon',
            $kg > 2500 => 'kamyonet',
            $kg > 1500 => 'uzun_panelvan',
            $kg > 800 => 'orta_panelvan',
            $kg > 400 => 'minivan',
            default => 'otomobil',
        };
    }

    /** Bir şoförün aracı, ilanın istediği tipi karşılar mı (aynı tip ya da bir üst kapasite sınıfı)? */
    public static function canCarry(string $driverType, string $requiredType): bool
    {
        $d = self::TYPES[$driverType]['capacity_kg'] ?? 0;
        $r = self::TYPES[$requiredType]['capacity_kg'] ?? PHP_INT_MAX;

        return $driverType === $requiredType || $d >= $r;
    }
}
