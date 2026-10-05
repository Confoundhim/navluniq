<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\URL;

/**
 * Ticari elektronik ileti onayı (6563 sayılı Kanun / İYS): kayıtta ayrı ve işaretlenmemiş kutu, her zaman geri alınabilir,
 * her pazarlama iletisinde tek tıkla çıkış bağlantısı. Onay ve ret kayıtları (zaman, IP, tarayıcı) UserConsent'te tutulur;
 * İYS'ye yükleme (3 iş günü) işletmenin yükümlülüğüdür — kayıtlar panelden dışa aktarılabilir.
 */
class MarketingConsentService
{
    public const TYPE = 'marketing';

    public static function hasConsent(User $user): bool
    {
        return $user->marketing_consent_at !== null && $user->marketing_consent_revoked_at === null;
    }

    public function grant(User $user): void
    {
        if (self::hasConsent($user)) {
            return;
        }
        $user->forceFill(['marketing_consent_at' => now(), 'marketing_consent_revoked_at' => null])->save();
        UserConsent::create([
            'user_id' => $user->id, 'consent_type' => self::TYPE, 'document_version' => UserConsent::currentVersion(),
            'granted' => true, 'recorded_at' => now(), 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
        ]);
    }

    public function revoke(User $user, string $via = 'profil'): void
    {
        if (! self::hasConsent($user)) {
            return;
        }
        $user->forceFill(['marketing_consent_revoked_at' => now()])->save();
        UserConsent::query()->where('user_id', $user->id)->where('consent_type', self::TYPE)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        UserConsent::create([
            'user_id' => $user->id, 'consent_type' => self::TYPE, 'document_version' => UserConsent::currentVersion(),
            'granted' => false, 'recorded_at' => now(), 'ip_address' => request()->ip(), 'user_agent' => mb_substr('ret: '.$via.' · '.(string) request()->userAgent(), 0, 1000),
        ]);
    }

    /** Pazarlama iletilerinin altına konan, giriş gerektirmeyen tek tıklık çıkış bağlantısı (imzalı, süresiz). */
    public static function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['user' => $user->public_id ?? $user->id]);
    }
}
