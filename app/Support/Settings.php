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

        // Dış kaynak ilanları
        'scraper_free_delay_minutes' => 20,     // Onaylanan ilanın ücretsiz üyelere açılma gecikmesi (dk)
        'scraper_auto_approve' => 0,            // 1: kriterleri sağlayan adaylar her dakika otomatik onaylanır
        'scraper_auto_approve_require_price' => 0,
        'scraper_auto_approve_require_weight' => 0,

        // Telegram kanalı
        'telegram_post_enabled' => 0,           // 1: ücretsiz üyelere açılan ilan kanala gönderilir
        'telegram_bot_token' => '',
        'telegram_channel_id' => '',            // @kanaladi veya -100... sayısal kimlik
        'telegram_show_full_phone' => 0,        // 0: maskeli numara + siteye bağlantı
    ];

    /** Yalnız değeri gizlenerek günlüğe yazılacak anahtarlar. */
    public const SECRET_KEYS = ['telegram_bot_token'];

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

    public static function bool(string $key): bool
    {
        return in_array(strtolower(trim((string) self::get($key))), ['1', 'true', 'on', 'yes', 'evet'], true);
    }

    public static function string(string $key): string
    {
        return trim((string) self::get($key));
    }

    public static function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        CmsContent::setVal($key, $value === null ? null : (string) $value, $updatedBy);
    }
}
