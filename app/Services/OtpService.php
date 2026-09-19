<?php

namespace App\Services;

use App\Mail\UserOtpMail;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E-posta tabanlı tek kullanımlık kod üretimi ve doğrulaması.
 * Gönderim ve deneme sayıları IP + kullanıcı bazında sınırlanır.
 */
class OtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_SENDS_PER_MINUTE = 3;

    public const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * Kod üretir, hash'leyerek kullanıcıya yazar ve e-posta ile gönderir.
     * Başarısızlıkta kullanıcıya gösterilecek hata mesajını döner, başarıda null.
     */
    /** Yönetici panelinde tanımlı inceleme (test) hesabı mı: e-posta listede ve sabit kod tanımlı. */
    public static function isReviewAccount(User $user): bool
    {
        $code = Settings::string('review_login_code');
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $emails = array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', Settings::string('review_login_emails'))));

        return in_array(mb_strtolower((string) $user->email), $emails, true);
    }

    public function send(User $user, string $purpose, string $context = 'generic'): ?string
    {
        if (self::isReviewAccount($user)) {
            // İnceleme hesabı: e-posta gönderilmez, panelde tanımlı sabit kod kabul edilir.
            $user->forceFill(['otp_code' => Hash::make(Settings::string('review_login_code')), 'otp_expires_at' => now()->addMinutes(30)])->save();
            Log::info('İnceleme hesabı için sabit doğrulama kodu kullanıldı.', ['user_id' => $user->id, 'context' => $context]);

            return null;
        }

        $sendKey = "otp-send:{$context}:{$user->id}:".request()->ip();

        if (RateLimiter::tooManyAttempts($sendKey, self::MAX_SENDS_PER_MINUTE)) {
            return 'Çok fazla kod istendi. Lütfen bir dakika bekleyin.';
        }
        RateLimiter::hit($sendKey, 60);

        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ])->save();

        try {
            Mail::to($user->email)->send(new UserOtpMail($code, $purpose, $user->first_name));
        } catch (\Throwable $e) {
            $this->clear($user);
            Log::error('OTP e-postası gönderilemedi.', ['user_id' => $user->id, 'context' => $context, 'error' => $e->getMessage()]);

            return 'Doğrulama kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.';
        }

        return null;
    }

    /**
     * Kodu doğrular. Başarıda kodu temizler ve null döner, aksi halde hata mesajı döner.
     */
    public function verify(User $user, string $code, string $context = 'generic'): ?string
    {
        $verifyKey = "otp-verify:{$context}:{$user->id}:".request()->ip();

        if (RateLimiter::tooManyAttempts($verifyKey, self::MAX_VERIFY_ATTEMPTS)) {
            $this->clear($user);

            return 'Çok fazla hatalı deneme yapıldı. Lütfen yeni kod isteyin.';
        }

        $expired = ! $user->otp_code || ! $user->otp_expires_at || now()->greaterThan($user->otp_expires_at);

        if ($expired || ! Hash::check($code, $user->otp_code)) {
            RateLimiter::hit($verifyKey, 300);

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
