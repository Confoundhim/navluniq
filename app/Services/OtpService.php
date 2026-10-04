<?php

namespace App\Services;

use App\Mail\UserOtpMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E-posta tabanlı tek kullanımlık kod üretimi ve doğrulaması.
 * Gönderim IP + kullanıcı bazında, doğrulama denemesi kullanıcı bazında sınırlanır (IP değiştirerek kod tahmin edilemez).
 */
class OtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_SENDS_PER_MINUTE = 3;

    /** Aynı kullanıcıya 10 dakikada en çok bu kadar kod gönderilir (IP değişse de). */
    public const MAX_SENDS_PER_USER_10MIN = 6;

    public const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * Yönetim panelinde tanımlı inceleme (test) hesabı mı: e-posta listede ve sabit kod tanımlı.
     * Yönetici hesapları canlı ortamda hiç inceleme hesabı sayılmaz (yalnız yerel/test ortamı ya da `deneme:izole`
     * ile işaretlenmiş deneme kopyası); süre (`review_login_until`) dolduysa özellik kendiliğinden kapanır.
     */
    public static function isReviewAccount(User $user): bool
    {
        if (! self::reviewLoginActive()) {
            return false;
        }
        if ($user->isAdminPanelUser() && ! self::adminReviewAllowed()) {
            return false;
        }
        $emails = array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', Settings::string('review_login_emails'))));

        return in_array(mb_strtolower((string) $user->email), $emails, true);
    }

    /** Sabit kodla giriş özelliği tanımlı ve süresi geçmemiş mi. */
    public static function reviewLoginActive(): bool
    {
        if (! preg_match('/^\d{6}$/', Settings::string('review_login_code'))) {
            return false;
        }
        $until = Settings::string('review_login_until');
        if ($until !== '') {
            try {
                if (now()->greaterThan(Carbon::parse($until))) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Yöneticiler sabit kodla yalnız yerel/test ortamında ya da yalıtılmış deneme kopyasında girebilir. */
    public static function adminReviewAllowed(): bool
    {
        return app()->environment('local') || Settings::bool('deneme_mode');
    }

    /**
     * Kod üretir, hash'leyerek kullanıcıya yazar ve e-posta ile gönderir. $to verilirse kod o adrese gider
     * (e-posta değişikliğinde yeni adres doğrulanır). Başarısızlıkta kullanıcıya gösterilecek hata mesajını döner, başarıda null.
     */
    public function send(User $user, string $purpose, string $context = 'generic', ?string $to = null): ?string
    {
        if ($to === null && self::isReviewAccount($user)) {
            // İnceleme hesabı: e-posta gönderilmez, panelde tanımlı sabit kod kabul edilir.
            $user->forceFill(['otp_code' => Hash::make(Settings::string('review_login_code')), 'otp_expires_at' => now()->addMinutes(30)])->save();
            Log::warning('İnceleme hesabı için sabit doğrulama kodu kullanıldı.', ['user_id' => $user->id, 'context' => $context, 'ip' => request()->ip()]);
            ActivityLog::record('auth.review_code', 'Sabit doğrulama kodu kullanıldı ('.$context.').', $user->id, $user, ['ip' => request()->ip()]);

            return null;
        }

        $sendKey = "otp-send:{$context}:{$user->id}:".request()->ip();
        $userKey = "otp-send-user:{$user->id}";

        if (RateLimiter::tooManyAttempts($sendKey, self::MAX_SENDS_PER_MINUTE)) {
            return 'Çok fazla kod istendi. Lütfen bir dakika bekleyin.';
        }
        if (RateLimiter::tooManyAttempts($userKey, self::MAX_SENDS_PER_USER_10MIN)) {
            return 'Bu hesap için çok fazla kod istendi. Lütfen 10 dakika sonra tekrar deneyin.';
        }
        RateLimiter::hit($sendKey, 60);
        RateLimiter::hit($userKey, 600);

        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ])->save();
        // Yeni kod üretildi: önceki kodun deneme sayacı sıfırlanır.
        RateLimiter::clear("otp-verify:{$context}:{$user->id}");

        try {
            Mail::to($to ?? $user->email)->send(new UserOtpMail($code, $purpose, $user->first_name));
        } catch (\Throwable $e) {
            $this->clear($user);
            Log::error('OTP e-postası gönderilemedi.', ['user_id' => $user->id, 'context' => $context, 'error' => $e->getMessage()]);

            return 'Doğrulama kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.';
        }

        return null;
    }

    /**
     * Kodu doğrular. Başarıda kodu temizler ve null döner, aksi halde hata mesajı döner.
     * Deneme sayacı kullanıcıya bağlıdır: bir kod toplam en çok 5 yanlış deneme yaşar, IP değiştirmek işe yaramaz.
     */
    public function verify(User $user, string $code, string $context = 'generic'): ?string
    {
        $verifyKey = "otp-verify:{$context}:{$user->id}";

        if (RateLimiter::tooManyAttempts($verifyKey, self::MAX_VERIFY_ATTEMPTS)) {
            $this->clear($user);

            return 'Çok fazla hatalı deneme yapıldı. Lütfen yeni kod isteyin.';
        }

        $expired = ! $user->otp_code || ! $user->otp_expires_at || now()->greaterThan($user->otp_expires_at);

        if ($expired || ! Hash::check($code, $user->otp_code)) {
            RateLimiter::hit($verifyKey, 300);
            if (! $expired && RateLimiter::tooManyAttempts($verifyKey, self::MAX_VERIFY_ATTEMPTS)) {
                $this->clear($user); // beşinci yanlış denemede kod geçersizleşir

                return 'Çok fazla hatalı deneme yapıldı. Lütfen yeni kod isteyin.';
            }

            return $expired ? 'Kodun süresi dolmuş. Lütfen yeni kod isteyin.' : 'Girdiğiniz doğrulama kodu hatalı.';
        }

        RateLimiter::clear($verifyKey);
        $this->clear($user);

        return null;
    }

    public function clear(User $user): void
    {
        $user->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();
    }
}
