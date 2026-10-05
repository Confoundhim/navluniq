<?php

namespace App\Support;

use App\Models\CmsContent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        'offer_payment_hours' => 24,            // Teklif kabulünden sonra yük sahibinin ödeme süresi (saat); dolunca ilan yeniden havuza döner
        'load_expiry_grace_days' => 1,          // Yükleme tarihi bu kadar gün geçmiş, hâlâ teklif bekleyen ilan kapatılır
        'premium_monthly_price' => 900.0,       // Premium abonelik aylık ücreti (₺)
        'min_load_price' => 500.0,              // İlan için asgari navlun bedeli (₺)
        'return_load_radius_km' => 150,         // Dönüş yükü: varış noktasına bu kadar km içinden çıkan ilanlar bildirilir (aynı il her zaman)
        'return_load_mail_hours' => 3,          // Dönüş yükü e-postası sefer başına en çok bu kadar saatte bir (uygulama içi bildirim her zaman)
        'trip_auto_close_days' => 3,            // Teslim edildikten bu kadar gün sonra sefer kendiliğinden kapanır
        'payment_vat_rate' => 20.0,             // Abonelik ve hizmet bedeli faturalarında KDV oranı (%)

        // Dış kaynak ilanları
        'scraper_free_delay_minutes' => 20,     // Onaylanan ilanın ücretsiz üyelere açılma gecikmesi (dk)
        'scraper_list_days' => 7,               // Yayınlanan dış kaynak ilanı bu kadar gün listede kalır; sonra arşivlenir (silinmez, sayaçta kalır)
        'scraper_auto_approve' => 0,            // 1: kriterleri sağlayan adaylar her dakika otomatik onaylanır
        'scraper_auto_approve_require_price' => 0,
        'scraper_auto_approve_require_weight' => 0,
        // Şoför dış kaynak ilanı için WhatsApp'ı açınca hazır gelen mesaj; {rota} {yuk} {arac} {ad} yer tutucuları
        'scraper_contact_message' => 'Merhaba, NavlunIQ platformunda belgeleri onaylanmış bir şoförüm. {rota} ilanınız ({yuk}) için size ulaşıyorum. Yük hâlâ uygunsa detayları konuşabilir miyiz? Teşekkürler, {ad}',
        'scraper_auto_approve_require_vehicle' => 0, // 1: araç tipi çözülemeyen aday otomatik onaylanmaz
        'scraper_auto_approve_require_ai' => 1,      // 1: yapay zeka bakmadan / yeterli güven vermeden aday otomatik onaylanmaz
        'scraper_auto_approve_min_confidence' => 75, // karar puanı (yüzde) bu değerin üstündeyse otomatik yayın
        'scraper_auto_reject_max_score' => 25,       // karar puanı (yüzde) bu değerin altındaysa otomatik ret
        'scraper_incomplete_publish' => 1,           // 1: ret sınırı ile otomatik yayın eşiği arasındaki, rotası ve telefonu belli adaylar "eksik bilgili" yayınlanır (şoför "Aradım, araç:" ile tamamlar)
        'scraper_incomplete_max_score' => 60,        // eksik bilgili yayın bandının üst sınırı YALNIZ yayın eşiğinden (min_confidence) yüksekse etkilidir; aksi halde bant yayın eşiğine kadar uzanır (2026-10-04: %60-75 bandı kuyrukta bekleyip 48 saatte reddediliyordu)
        'scraper_queue_max_age_hours' => 48,         // kuyrukta bu kadar saatten uzun bekleyen ve rotası/ili çözülemeyen aday kendiliğinden reddedilir (puan bandı / yapay zeka beklemesi yüzünden bekleyen reddedilmez)
        'scraper_ai_wait_minutes' => 15,             // yapay zeka zorunluyken cevap için en çok bu kadar beklenir
        'scraper_local_enabled' => 1,                // 1: yerel öğrenen sınıflandırıcı (dış servisten bağımsız) devrede
        'scraper_local_min_confidence' => 90,        // dış yapay zeka ulaşılamazsa yerel güven (yüzde) bu değerin üstündeyse otomatik onay
        'ai_local_docs_load' => 0,                   // yerel sınıflandırıcı: öğrenilen ilan örneği sayısı
        'ai_local_docs_other' => 0,                  // yerel sınıflandırıcı: öğrenilen "ilan değil" örneği sayısı

        // Bildirim iletici (telefon) bağlantı anahtarı: boşsa .env SCRAPER_API_TOKEN; o da boşsa panel üretir
        'scraper_api_token' => '',
        'scraper_rejected_retention_days' => 7, // Reddedilen adaylar bu kadar gün sonra silinir
        'intake_event_days' => 7,               // Canlı akış kayıtları (telefondan gelen her isteğin sonucu, alıntı maskeli) bu kadar gün sonra silinir (KVKK)

        // Yapay zeka ile ilan çözümleme
        'ai_suggest_auto_approve_hits' => 0,    // öğrenme çemberi: aynı öneri bu kadar ayrı ilanda görülürse kendiliğinden sözlüğe girer (0: yalnız elle onay)
        'ai_audit_daily_count' => 5,            // öğrenme çemberi: günde bu kadar kuralla çözülmüş ilan yapay zekaya denetletilir (0: kapalı)
        'ai_parse_mode' => 'always',            // off | fill_gaps (kural eksik bırakınca) | always (her ilanda; yapay zeka öncelikli)
        'ai_provider' => '',                    // tercih edilen sağlayıcı; boş: ücretsizden başlayan varsayılan sıra
        'ai_gemini_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_gemini_key' => '',                  // şifreli; boşsa .env GEMINI_API_KEY
        'ai_groq_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_groq_key' => '',
        'ai_cerebras_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_cerebras_key' => '',
        'ai_openrouter_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_openrouter_key' => '',
        'ai_mistral_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_mistral_key' => '',
        'ai_claude_model' => '', // boş: sağlayıcının güncel listesinden otomatik seçilir
        'ai_openai_model' => '',
        'ai_openai_key' => '',
        'ai_xai_model' => '',
        'ai_xai_key' => '',
        'ai_kimi_model' => '',
        'ai_kimi_key' => '',
        'ai_ollama_enabled' => 0,                    // 1: sunucudaki yerel model (Ollama) zincirin başında kullanılır
        'ai_ollama_base' => 'http://127.0.0.1:11434/v1', // Ollama OpenAI uyumlu adres
        'ai_ollama_model' => '',                     // boş: otomatik (qwen3 > qwen2.5 > gemma3 > llama3)
        'ai_claude_key' => '',                  // şifreli; boşsa .env CLAUDE_API_KEY

        // Telegram kanalı
        'telegram_post_enabled' => 0,           // 1: herkese açılan SİSTEM ilanları kanala gönderilir (dış kaynak gönderilmez)
        'telegram_bot_token' => '',
        'telegram_channel_id' => '',            // @kanaladi veya -100... sayısal kimlik

        // Ödeme kuruluşu / mağaza incelemesi için test hesapları: listedeki e-postalar sabit kodla giriş yapar
        'review_login_emails' => '',            // virgülle ayrılmış e-postalar
        'review_login_code' => '',              // 6 haneli sabit kod; boşsa özellik kapalı
        'review_login_until' => '',             // Y-m-d H:i:s; geçince sabit kod kendiliğinden kapanır (review:accounts 30 gün yazar)
        'deneme_mode' => 0,                     // 1: yalıtılmış deneme kopyası (deneme:izole yazar); yöneticiler sabit kodla girebilir
        'legal_document_version' => '',         // Sözleşme/KVKK metni sürümü (boşsa config/company LEGAL_DOCUMENT_VERSION); artınca kullanıcılar panelde yeniden onaylar
        'legal_effective_date' => '',           // Sürümün yürürlük tarihi (Y-m-d)

        // E-posta (SMTP) — panelden; boşsa .env MAIL_* kullanılır
        'mail_host' => 'mail.kurumsaleposta.com',
        'mail_port' => 587,
        'mail_encryption' => 'tls',             // tls (587) | ssl (465) | none
        'mail_username' => 'info@navluniq.com',
        'mail_password' => '',                  // şifreli saklanır
        'mail_from_address' => 'info@navluniq.com',
        'mail_from_name' => 'NavlunIQ',
        'mail_embed_images' => 0,               // 1: logolar iletiye gömülür (ek olarak); 0: siteden yüklenen adres. Bazı barındırıcılar ekli iletileri düşürür.

        // Ödeme kuruluşu — panelden; boşsa .env PAYMENT_PROVIDER
        'payment_provider' => '',               // paytr | iyzico
        'iyzico_api_key' => '',
        'iyzico_secret_key' => '',              // şifreli saklanır
        'iyzico_sandbox' => 1,                  // 1: sandbox-api.iyzipay.com
        'iyzico_marketplace' => 0,              // 1: pazaryeri (alt üye işyeri) ürünü aktif
    ];

    /** Yalnız değeri gizlenerek günlüğe yazılacak ve veritabanında şifreli tutulacak anahtarlar. */
    public const SECRET_KEYS = ['telegram_bot_token', 'mail_password', 'iyzico_secret_key', 'ai_claude_key', 'ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key', 'ai_openai_key', 'ai_xai_key', 'ai_kimi_key', 'scraper_api_token'];

    /**
     * Veritabanında şifreli saklanan anahtarlar (Crypt). Telegram ve telefon anahtarı 2026-10-05'te eklendi
     * (`0001_01_50` eski düz değerleri şifreler); get() düz kalan eski değeri de okur.
     */
    public const ENCRYPTED_KEYS = ['mail_password', 'iyzico_secret_key', 'ai_claude_key', 'ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key', 'ai_openai_key', 'ai_xai_key', 'ai_kimi_key', 'telegram_bot_token', 'scraper_api_token'];

    // Yönetici güvenliği (2026-10-05)
    /**
     * Revizyon geçmişinden "eski değere dön" ile geri alınamayan anahtarlar: gizli anahtarlar revizyona maskeli yazılır
     * (geri alma maskeyi gerçek anahtar yapardı), sözleşme sürümü yeniden onay akışını tetikler, sabit kod ve telefon
     * anahtarı güvenlik kapısıdır. SECRET_KEYS de geri alınamaz (bkz. isRollbackable).
     */
    public const NON_ROLLBACK_KEYS = ['legal_document_version', 'legal_effective_date', 'scraper_api_token', 'review_login_emails', 'review_login_code', 'review_login_until'];

    /** Değişimi şifre ile yeniden doğrulama ve diğer yöneticilere bildirim isteyen ödeme ayarları (gizli anahtarlar da ister). */
    public const REAUTH_KEYS = ['payment_provider', 'iyzico_sandbox', 'iyzico_marketplace'];

    /** Yalnız "manage system" izniyle düzenlenebilen genel ayarlar (sabit kodla giriş). */
    public const SYSTEM_ONLY_KEYS = ['review_login_emails', 'review_login_code', 'review_login_until'];

    public static function isRollbackable(string $key): bool
    {
        return ! in_array($key, self::SECRET_KEYS, true) && ! in_array($key, self::NON_ROLLBACK_KEYS, true);
    }

    /** Gizli anahtar ya da ödeme ayarı mı: kaydı şifre doğrulaması ve yönetici bildirimi ister. */
    public static function requiresReauth(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true) || in_array($key, self::REAUTH_KEYS, true);
    }

    /** Değer Laravel Crypt çıktısı gibi görünüyor mu (base64 içinde iv/value/mac taşıyan JSON)? Düz eski değerlerle ayırt etmek için. */
    public static function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = CmsContent::getVal($key);
        if (($value === null || $value === '') || ! in_array($key, self::ENCRYPTED_KEYS, true)) {
            return $value === null || $value === '' ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            // Şifreli görünmeyen değer: anahtar şifreli listeye sonradan girmiş, eski düz değer henüz dönüştürülmemiş.
            if (! self::looksEncrypted((string) $value)) {
                return $value;
            }

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
        if (str_starts_with($key, 'scraper_') || str_starts_with($key, 'ai_')) {
            // Otomatik onay taraması: eşik / kural değişince bekleyen adaylar ilk taramada yeniden değerlendirilir
            Cache::put('scraper.settings_changed_at', now()->timestamp, now()->addDays(30));
        }
    }

    /** Dış kaynak / yapay zeka ayarlarının son değiştiği an (otomatik onay taramasının "yeniden bak" eşiği). */
    public static function scraperSettingsChangedAt(): ?Carbon
    {
        $ts = Cache::get('scraper.settings_changed_at');

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;
    }
}
