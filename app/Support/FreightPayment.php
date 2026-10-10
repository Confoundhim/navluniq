<?php

namespace App\Support;

/**
 * Navlun (taşıma bedeli) ödeme yolu. Osman'ın kararı (2026-10-10): pazaryeri ürünü olan bir ödeme kuruluşu bulunana kadar navlun
 * bedeli yük sahibi ile şoför arasında DOĞRUDAN ödenir; NavlunIQ tahsilat yapmaz, para tutmaz, komisyon almaz, ödemeye taraf olmaz.
 * Pazaryeri açılınca panelden "platform" seçilir: teklif kabulünden sonra yük sahibi ödeme kuruluşunda öder, teslimat onayıyla şoföre aktarılır.
 *
 * Tek kaynak: akış (teklif kabulü, yola çıkış, teslim onayı, uyuşmazlık, iptal), ekran metinleri ve sözleşme yer tutucuları buradan okur.
 * Kip yalnız ayardan okunur; "platform" seçiliyken ödeme kuruluşu navlun tahsilatına kapalıysa sağlık ekranı ve hazırlık listesi uyarır
 * (platformBlocker). Teklif kabulünde o anki kip ilana yazılır (escrow_status = direct_payment); eski ilanlar kendi kipinde yürür.
 */
final class FreightPayment
{
    public const MODE_DIRECT = 'direct';

    public const MODE_PLATFORM = 'platform';

    public const LABELS = [
        self::MODE_DIRECT => 'Doğrudan: navlun yük sahibi ile şoför arasında ödenir (platform tahsilat yapmaz, komisyon almaz)',
        self::MODE_PLATFORM => 'Platform: yük sahibi ödeme kuruluşunda öder, teslimat onayıyla şoföre aktarılır (pazaryeri gerekir)',
    ];

    /** Paneldeki seçim (ham). */
    public static function mode(): string
    {
        $mode = Settings::string('freight_payment_mode');

        return array_key_exists($mode, self::LABELS) ? $mode : self::MODE_DIRECT;
    }

    /** Navlun doğrudan taraflar arasında mı ödeniyor? */
    public static function direct(): bool
    {
        return self::mode() !== self::MODE_PLATFORM;
    }

    public static function platform(): bool
    {
        return ! self::direct();
    }

    /** Platform seçiliyken fiilen doğrudan kipe düşüldüyse nedeni (sağlık ekranı); yoksa null. */
    public static function platformBlocker(): ?string
    {
        if (self::mode() !== self::MODE_PLATFORM) {
            return null;
        }

        return PaymentReadiness::escrowBlocker();
    }

    /** Ekranlarda ve bildirimlerde tek cümlelik açıklama. */
    public static function notice(): string
    {
        return self::direct()
            ? 'Navlun bedeli yük sahibi ile şoför arasında doğrudan ödenir; NavlunIQ tahsilat yapmaz, komisyon almaz ve ödemeye taraf olmaz.'
            : 'Navlun bedeli ödeme kuruluşunda güvenle tahsil edilir, teslimat onayıyla şoföre aktarılır.';
    }
}
