<?php

namespace App\Livewire\Concerns;

use App\Mail\SystemNoticeMail;
use App\Models\User;
use App\Services\AccountService;
use App\Services\MarketingConsentService;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Şoför ve yük sahibi profil sayfalarının ortak hesap güvenliği işlemleri.
 * - Ad/soyad serbest; e-posta ya da telefon değişikliği mevcut şifreyi ister.
 * - Yeni e-posta, o adrese gönderilen kodla doğrulanmadan hesaba yazılmaz; eski adrese haber gider.
 * - Şifre değişince diğer cihazlardaki oturumlar kapanır ve kullanıcıya bildirilir.
 */
trait ManagesAccountSecurity
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    /** E-posta/telefon değişikliğinde istenen mevcut şifre. */
    public string $contact_password = '';

    public string $email_change_otp = '';

    public bool $emailChangePending = false;

    /** Ticari elektronik ileti onayı (pazarlama e-postaları). */
    public bool $marketing_consent = false;

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    protected function fillAccountFields(User $user): void
    {
        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->phone = Phone::format($user->phone);
        $this->emailChangePending = $user->pending_email !== null;
        $this->marketing_consent = MarketingConsentService::hasConsent($user);
    }

    public function updateProfile(): void
    {
        $user = Auth::user();

        $this->validate([
            'first_name' => 'required|string|min:2|max:80',
            'last_name' => 'required|string|min:2|max:80',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', Phone::RULE],
        ], [
            'email.unique' => 'Bu e-posta adresi başka bir hesapta kullanılıyor.',
            'phone.regex' => 'Geçerli bir cep telefonu numarası girin.',
        ]);

        $phone = Phone::normalize($this->phone);
        $email = mb_strtolower(trim($this->email));
        $phoneChanged = $phone !== (Phone::normalize((string) $user->phone) ?: $user->phone); // eski kayıtlar başında 0 ile duruyor olabilir
        $emailChanged = $email !== mb_strtolower((string) $user->email);

        if ($phoneChanged && User::query()->whereKeyNot($user->id)->whereIn('phone', Phone::variants($phone))->exists()) {
            $this->addError('phone', 'Bu telefon numarası başka bir hesapta kullanılıyor.');

            return;
        }

        if ($phoneChanged || $emailChanged) {
            // İletişim bilgisi hesabın anahtarıdır: oturum çalınsa bile şifre bilinmeden değiştirilemez.
            $this->validate(['contact_password' => 'required|current_password'], [
                'contact_password.required' => 'E-posta ya da telefon değiştirmek için mevcut şifrenizi girin.',
                'contact_password.current_password' => 'Mevcut şifreniz hatalı.',
            ]);
        }

        $data = [
            'first_name' => trim($this->first_name),
            'last_name' => trim($this->last_name),
        ];
        if ($phoneChanged) {
            $data['phone'] = $phone;
            $data['phone_verified_at'] = null;
        }
        $user->update($data);

        // Pazarlama onayı: değişiklik varsa onay/ret kaydı (zaman, IP) tutulur.
        if ($this->marketing_consent !== MarketingConsentService::hasConsent($user)) {
            $this->marketing_consent ? app(MarketingConsentService::class)->grant($user) : app(MarketingConsentService::class)->revoke($user, 'profil');
        }

        if ($emailChanged) {
            $user->forceFill(['pending_email' => $email])->save();
            $error = app(OtpService::class)->send($user, 'Yeni e-posta adresinizi doğrulamak', 'email-change', $email);
            if ($error) {
                $user->forceFill(['pending_email' => null])->save();
                $this->email = $user->email;
                $this->addError('email', $error);

                return;
            }
            $this->emailChangePending = true;
            $this->contact_password = '';
            session()->flash('success_message', "Doğrulama kodu {$email} adresine gönderildi. Kodu girince yeni adres geçerli olur.");

            return;
        }

        $this->contact_password = '';
        session()->flash('success_message', 'Profil bilgileriniz güncellendi.');
    }

    public function confirmEmailChange(): void
    {
        $this->validate(['email_change_otp' => 'required|digits:6'], ['email_change_otp.digits' => 'Kod 6 haneli olmalıdır.']);
        $user = Auth::user();
        $newEmail = $user->pending_email;
        if (! $newEmail) {
            $this->emailChangePending = false;

            return;
        }
        if ($error = app(OtpService::class)->verify($user, $this->email_change_otp, 'email-change')) {
            $this->addError('email_change_otp', $error);

            return;
        }
        if (User::query()->whereKeyNot($user->id)->where('email', $newEmail)->exists()) {
            $user->forceFill(['pending_email' => null])->save();
            $this->emailChangePending = false;
            $this->addError('email', 'Bu e-posta adresi bu arada başka bir hesapta kullanılmaya başlandı.');

            return;
        }

        $oldEmail = $user->email;
        $user->forceFill(['email' => $newEmail, 'pending_email' => null, 'email_verified_at' => now()])->save();
        $this->email = $newEmail;
        $this->email_change_otp = '';
        $this->emailChangePending = false;

        app(NotificationService::class)->notify($user, 'E-posta adresiniz değiştirildi',
            ["Hesabınızın e-posta adresi {$oldEmail} yerine {$newEmail} olarak güncellendi (".now()->format('d.m.Y H:i').').',
                'Bu işlemi siz yapmadıysanız hemen şifrenizi değiştirin ve destek ekibimize yazın.'],
            null, null, 'security');
        if ($oldEmail && filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($oldEmail)->send(new SystemNoticeMail('E-posta adresiniz değiştirildi',
                    ["NavlunIQ hesabınızın e-posta adresi az önce {$newEmail} olarak değiştirildi (".now()->format('d.m.Y H:i').').',
                        'Bu işlemi siz yapmadıysanız hemen "Şifremi unuttum" ile yeni şifre belirleyin ve destek ekibimize ulaşın.'],
                    route('password.request'), 'Şifremi sıfırla', $user->first_name));
            } catch (\Throwable $e) {
                Log::warning('E-posta değişikliği bildirimi eski adrese gönderilemedi.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        session()->flash('success_message', 'E-posta adresiniz doğrulandı ve güncellendi.');
    }

    public function cancelEmailChange(): void
    {
        $user = Auth::user();
        $user->forceFill(['pending_email' => null])->save();
        app(OtpService::class)->clear($user);
        $this->email = $user->email;
        $this->email_change_otp = '';
        $this->emailChangePending = false;
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|string|min:12|max:255|confirmed',
        ], [
            'current_password.current_password' => 'Mevcut şifreniz hatalı.',
            'new_password.confirmed' => 'Yeni şifreler birbiriyle eşleşmiyor.',
        ]);

        $user = Auth::user();
        $user->forceFill(['password' => $this->new_password])->save();
        self::endOtherSessions($user, $this->new_password);

        $this->reset(['current_password', 'new_password', 'new_password_confirmation']);
        app(NotificationService::class)->notify($user, 'Şifreniz değiştirildi',
            ['Hesabınızın şifresi '.now()->format('d.m.Y H:i').' tarihinde değiştirildi; diğer cihazlardaki oturumlar kapatıldı.',
                'Bu işlemi siz yapmadıysanız hemen "Şifremi unuttum" ile yeni şifre belirleyin ve destek ekibimize yazın.'],
            null, null, 'security');
        session()->flash('success_message', 'Şifreniz güncellendi; diğer cihazlardaki oturumlar kapatıldı.');
    }

    /** Diğer cihazlardaki oturumları kapatır: hatırlama anahtarı yenilenir, veritabanındaki oturum kayıtları silinir. */
    public static function endOtherSessions(User $user, string $plainPassword): void
    {
        if (Auth::id() === $user->id) {
            Auth::logoutOtherDevices($plainPassword);
        } else {
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
        // Oturum tablosu varsa (canlıda database sürücüsü) diğer cihazların kayıtları hemen silinir.
        $table = (string) config('session.table', 'sessions');
        if (Schema::hasTable($table)) {
            $q = DB::table($table)->where('user_id', $user->id);
            if (Auth::id() === $user->id && session()->getId()) {
                $q->where('id', '!=', session()->getId());
            }
            $q->delete();
        }
    }

    /** Kullanıcının kendi verilerini JSON olarak indirmesi (KVKK bilgi edinme hakkı). */
    public function exportMyData()
    {
        $data = app(AccountService::class)->export(Auth::user());

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'navluniq-verilerim-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }
}
