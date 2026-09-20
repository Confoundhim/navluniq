<?php

namespace App\Support;

use App\Models\CmsContent;
use Illuminate\Support\Facades\Crypt;

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
        'payment_vat_rate' => 20.0,             // Abonelik ve hizmet bedeli faturalarında KDV oranı (%)

        // Dış kaynak ilanları
        'scraper_free_delay_minutes' => 20,     // Onaylanan ilanın ücretsiz üyelere açılma gecikmesi (dk)
        'scraper_auto_approve' => 0,            // 1: kriterleri sağlayan adaylar her dakika otomatik onaylanır
        'scraper_auto_approve_require_price' => 0,
        'scraper_auto_approve_require_weight' => 0,
        'scraper_auto_approve_require_vehicle' => 0, // 1: araç tipi çözülemeyen aday otomatik onaylanmaz

        // Bildirim iletici (telefon) bağlantı anahtarı: boşsa .env SCRAPER_API_TOKEN; o da boşsa panel üretir
        'scraper_api_token' => '',
        'scraper_rejected_retention_days' => 7, // Reddedilen adaylar bu kadar gün sonra silinir

        // Yapay zeka ile ilan çözümleme
        'ai_parse_mode' => 'fill_gaps',         // off | fill_gaps (kural eksik bırakınca) | always (her ilanda)
        'ai_provider' => '',                    // tercih edilen sağlayıcı; boş: ücretsizden başlayan varsayılan sıra
        'ai_gemini_model' => 'gemini-2.5-flash-lite',
        'ai_gemini_key' => '',                  // şifreli; boşsa .env GEMINI_API_KEY
        'ai_groq_model' => 'llama-3.3-70b-versatile',
        'ai_groq_key' => '',
        'ai_cerebras_model' => 'llama-3.3-70b',
        'ai_cerebras_key' => '',
        'ai_openrouter_model' => 'meta-llama/llama-3.3-70b-instruct:free',
        'ai_openrouter_key' => '',
        'ai_mistral_model' => 'mistral-small-latest',
        'ai_mistral_key' => '',
        'ai_claude_model' => 'claude-opus-5',
        'ai_claude_key' => '',                  // şifreli; boşsa .env CLAUDE_API_KEY
        'scraper_setup_code' => '',             // herkese açık telefon kurulum sayfasının gizli kodu
        'macrodroid_template_token' => '',      // yüklenen .macro şablonundaki anahtar (indirmede güncel anahtarla değiştirilir)
        'macrodroid_template_at' => '',

        // Telegram kanalı
        'telegram_post_enabled' => 0,           // 1: ücretsiz üyelere açılan ilan kanala gönderilir
        'telegram_bot_token' => '',
        'telegram_channel_id' => '',            // @kanaladi veya -100... sayısal kimlik
        'telegram_show_full_phone' => 0,        // 0: maskeli numara + siteye bağlantı

        // Ödeme kuruluşu / mağaza incelemesi için test hesapları: listedeki e-postalar sabit kodla giriş yapar
        'review_login_emails' => '',            // virgülle ayrılmış e-postalar
        'review_login_code' => '',              // 6 haneli sabit kod; boşsa özellik kapalı

        // E-posta (SMTP) — panelden; boşsa .env MAIL_* kullanılır
        'mail_host' => 'mail.kurumsaleposta.com',
        'mail_port' => 587,
        'mail_encryption' => 'tls',             // tls (587) | ssl (465) | none
        'mail_username' => 'info@navluniq.com',
        'mail_password' => '',                  // şifreli saklanır
        'mail_from_address' => 'info@navluniq.com',
        'mail_from_name' => 'NavlunIQ',

        // Ödeme kuruluşu — panelden; boşsa .env PAYMENT_PROVIDER
        'payment_provider' => '',               // paytr | iyzico
        'iyzico_api_key' => '',
        'iyzico_secret_key' => '',              // şifreli saklanır
        'iyzico_sandbox' => 1,                  // 1: sandbox-api.iyzipay.com
        'iyzico_marketplace' => 0,              // 1: pazaryeri (alt üye işyeri) ürünü aktif
    ];

    /** Yalnız değeri gizlenerek günlüğe yazılacak ve veritabanında şifreli tutulacak anahtarlar. */
    public const SECRET_KEYS = ['telegram_bot_token', 'mail_password', 'iyzico_secret_key', 'ai_claude_key', 'ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key', 'scraper_api_token', 'scraper_setup_code', 'macrodroid_template_token'];

    /** Veritabanında şifreli saklanan anahtarlar (Crypt). */
    public const ENCRYPTED_KEYS = ['mail_password', 'iyzico_secret_key', 'ai_claude_key', 'ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = CmsContent::getVal($key);
        if (($value === null || $value === '') || ! in_array($key, self::ENCRYPTED_KEYS, true)) {
            return $value === null || $value === '' ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            return $default ?? self::DEFAULTS[$key] ?? null;
        }
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
        if ($value !== null && $value !== '' && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $value = Crypt::encryptString((string) $value);
        }
        CmsContent::setVal($key, $value === null ? null : (string) $value, $updatedBy);
    }
}
