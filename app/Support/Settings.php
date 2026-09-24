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
        'return_load_radius_km' => 150,         // Dönüş yükü: varış noktasına bu kadar km içinden çıkan ilanlar bildirilir (aynı il her zaman)
        'return_load_mail_hours' => 3,          // Dönüş yükü e-postası sefer başına en çok bu kadar saatte bir (uygulama içi bildirim her zaman)
        'trip_auto_close_days' => 3,            // Teslim edildikten bu kadar gün sonra sefer kendiliğinden kapanır
        'payment_vat_rate' => 20.0,             // Abonelik ve hizmet bedeli faturalarında KDV oranı (%)

        // Dış kaynak ilanları
        'scraper_free_delay_minutes' => 20,     // Onaylanan ilanın ücretsiz üyelere açılma gecikmesi (dk)
        'scraper_list_days' => 14,              // Yayınlanan dış kaynak ilanı bu kadar gün listede kalır; sonra arşivlenir (silinmez, sayaçta kalır)
        'scraper_auto_approve' => 0,            // 1: kriterleri sağlayan adaylar her dakika otomatik onaylanır
        'scraper_auto_approve_require_price' => 0,
        'scraper_auto_approve_require_weight' => 0,
        // Şoför dış kaynak ilanı için WhatsApp'ı açınca hazır gelen mesaj; {rota} {yuk} {arac} {ad} yer tutucuları
        'scraper_contact_message' => 'Merhaba, NavlunIQ platformunda belgeleri onaylanmış bir şoförüm. {rota} ilanınız ({yuk}) için size ulaşıyorum. Yük hâlâ uygunsa detayları konuşabilir miyiz? Teşekkürler, {ad}',
        'scraper_auto_approve_require_vehicle' => 0, // 1: araç tipi çözülemeyen aday otomatik onaylanmaz
        'scraper_auto_approve_require_ai' => 1,      // 1: yapay zeka bakmadan / yeterli güven vermeden aday otomatik onaylanmaz
        'scraper_auto_approve_min_confidence' => 75, // karar puanı (yüzde) bu değerin üstündeyse otomatik yayın
        'scraper_auto_reject_max_score' => 25,       // karar puanı (yüzde) bu değerin altındaysa otomatik ret
        'scraper_incomplete_publish' => 1,           // 1: ret ile eksik-üst-sınır arasındaki, rotası ve telefonu belli adaylar "eksik bilgili" yayınlanır
        'scraper_incomplete_max_score' => 60,        // karar puanı (yüzde) bu değere kadar eksik bilgili yayın; üstü onay kuyruğu (sistemi eğitir)
        'scraper_queue_max_age_hours' => 48,         // kuyrukta bu kadar saatten uzun bekleyen aday kendiliğinden reddedilir
        'scraper_ai_wait_minutes' => 15,             // yapay zeka zorunluyken cevap için en çok bu kadar beklenir
        'scraper_local_enabled' => 1,                // 1: yerel öğrenen sınıflandırıcı (dış servisten bağımsız) devrede
        'scraper_local_min_confidence' => 90,        // dış yapay zeka ulaşılamazsa yerel güven (yüzde) bu değerin üstündeyse otomatik onay
        'ai_local_docs_load' => 0,                   // yerel sınıflandırıcı: öğrenilen ilan örneği sayısı
        'ai_local_docs_other' => 0,                  // yerel sınıflandırıcı: öğrenilen "ilan değil" örneği sayısı

        // Bildirim iletici (telefon) bağlantı anahtarı: boşsa .env SCRAPER_API_TOKEN; o da boşsa panel üretir
        'scraper_api_token' => '',
        'scraper_rejected_retention_days' => 7, // Reddedilen adaylar bu kadar gün sonra silinir

        // Yapay zeka ile ilan çözümleme
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

    /** Veritabanında şifreli saklanan anahtarlar (Crypt). */
    public const ENCRYPTED_KEYS = ['mail_password', 'iyzico_secret_key', 'ai_claude_key', 'ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key', 'ai_openai_key', 'ai_xai_key', 'ai_kimi_key'];

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
