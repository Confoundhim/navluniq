<?php

namespace App\Support;

use App\Models\CmsContent;

/**
 * Yönetim panelinden düzenlenen çalışma zamanı ayarları (komisyon oranları, kaynak izinleri vb.).
 */
final class Settings
{
    public const DEFAULTS = [
        'commission_standard_driver' => 5.0,   // Standart şoför komisyonu (%)
        'commission_cargo_owner' => 0.0,        // Yük sahibi hizmet bedeli (%)
        'delivery_auto_approval_hours' => 72,   // Teslimat sonrası otomatik onay süresi (saat)
        'offer_validity_days' => 2,             // Teklif geçerlilik süresi (gün)
        'premium_monthly_price' => 900.0,       // Premium abonelik aylık ücreti (₺)
        'min_load_price' => 500.0,              // İlan için asgari navlun bedeli (₺)
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = CmsContent::getVal($key);

        return $value === null || $value === '' ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
    }

    public static function float(string $key): float
    {
        return (float) str_replace(',', '.', (string) self::get($key));
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        CmsContent::setVal($key, $value === null ? null : (string) $value, $updatedBy);
    }
}
