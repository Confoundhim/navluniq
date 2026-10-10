<?php

use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public string $identifier = '';

    public string $password = '';

    public string $otp = '';

    public int $step = 1;

    /** Bu cihazda oturumu açık tut (hatırlama çerezi). */
    public bool $remember = true;

    #[Locked]
    public ?int $tempUserId = null;

    public function submitCredentials(): void
    {
        $this->validate([
            'identifier' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $rawIdentifier = trim($this->identifier);
        $loginKey = 'login:'.hash('sha256', Str::lower($rawIdentifier).'|'.request()->ip());
        // Aynı IP'den çok sayıda farklı hesaba deneme (şifre serpme) için ikinci sayaç.
        $ipKey = 'login-ip:'.hash('sha256', (string) request()->ip());
        if (RateLimiter::tooManyAttempts($loginKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $this->addError('identifier', 'Çok fazla giriş denemesi yapıldı. Lütfen bir dakika bekleyin.');

            return;
        }

        $query = User::query()->where('email', mb_strtolower($rawIdentifier));
        if ($phone = Phone::normalize($rawIdentifier)) {
            $query->orWhereIn('phone', Phone::variants($phone));
        }
        $user = $query->first();

        // Hatalı şifre, yönetici hesabı, yasaklı ya da askıya alınmış hesap: hepsi aynı mesajı alır (hesap durumu sızmaz).
        if (
            ! $user || ! Hash::check($this->password, $user->password) ||
            in_array($user->current_role, ['admin', 'super_admin'], true) ||
            $user->banned_at !== null ||
            (! $user->is_active && $user->email_verified_at !== null)
        ) {
            RateLimiter::hit($loginKey, 60);
            RateLimiter::hit($ipKey, 60);
            $this->addError('identifier', 'Giriş bilgileri hatalı veya hesabın erişimi kapalı.');

            return;
        }

        RateLimiter::clear($loginKey);
        $this->tempUserId = $user->id;

        $error = app(OtpService::class)->send($user, 'NavlunIQ hesabınıza giriş yapmak', 'login');
        if ($error) {
            $this->addError('identifier', $error);

            return;
        }

        session()->flash('otp_message', 'Doğrulama kodu e-posta adresinize gönderildi.');
        $this->step = 2;
    }

    public function resendOtp(): void
    {
        $user = $this->tempUserId ? User::find($this->tempUserId) : null;
        if (! $user) {
            $this->backToCredentials();

            return;
        }

        $error = app(OtpService::class)->send($user, 'NavlunIQ hesabınıza giriş yapmak', 'login');
        if ($error) {
            $this->addError('otp', $error);

            return;
        }

        session()->flash('otp_message', 'Yeni doğrulama kodu gönderildi.');
    }

    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|digits:6']);

        $user = $this->tempUserId ? User::find($this->tempUserId) : null;
        if (! $user || $user->banned_at !== null || in_array($user->current_role, ['admin', 'super_admin'], true)) {
            $this->backToCredentials();
            $this->addError('identifier', 'Oturum doğrulaması başlatılamadı. Lütfen tekrar giriş yapın.');

            return;
        }

        if ($error = app(OtpService::class)->verify($user, $this->otp, 'login')) {
            $this->addError('otp', $error);

            return;
        }

        $user->forceFill([
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'last_login_at' => now(),
        ])->save();

        Auth::login($user, $this->remember);
        session()->regenerate();

        return $this->redirect(route('panel'), navigate: true);
    }

    public function backToCredentials(): void
    {
        $this->reset(['step', 'otp', 'tempUserId', 'password']);
    }
}; ?>


<div class="max-w-md mx-auto py-12 px-6 animate-fade-in">
    <div
        class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">

        <div class="flex flex-col items-center mb-6">
            <div class="flex items-center space-x-2 text-2xl font-black text-neutral-900 dark:text-white">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
            </div>
            <p class="text-xs text-neutral-400 mt-1 font-medium">Hesabınıza giriş yapın</p>
        </div>

        @if($step === 1)
            <form wire:submit.prevent="submitCredentials" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="form-label">Telefon Numarası veya E-Posta</label>
                    <input type="text" wire:model="identifier" autocomplete="username"
                        placeholder="05XXXXXXXXX veya e-posta"
                        class="form-input">
                    @error('identifier') <span class="form-error">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <div class="flex justify-between items-center">
                        <label class="form-label">Şifre</label>
                        <a href="{{ route('password.request') }}" wire:navigate class="text-[11px] text-neutral-400 hover:text-brand-500 transition-colors">Şifremi unuttum</a>
                    </div>
                    <input type="password" wire:model="password" autocomplete="current-password"
                        placeholder="••••••••"
                        class="form-input">
                    @error('password') <span class="form-error">{{ $message }}</span> @enderror
                </div>

                <label class="flex items-center gap-2 text-[11px] text-neutral-500 dark:text-neutral-400 select-none">
                    <input type="checkbox" wire:model="remember" class="rounded border-neutral-300 dark:border-neutral-700 text-brand-500 focus:ring-brand-500">
                    Bu cihazda oturumum açık kalsın
                </label>

                <button type="submit"
                    class="btn-primary w-full py-3">
                    <span wire:loading.remove wire:target="submitCredentials">Giriş yap</span>
                    <span wire:loading wire:target="submitCredentials">Kontrol Ediliyor...</span>
                </button>

                <div class="text-center pt-2 space-y-2">
                    <span class="text-neutral-400 text-[11px] block">Hesabınız yok mu?</span>
                    <div class="flex justify-center space-x-3 text-[11px]">
                        <a href="{{ route('register.cargo-owner') }}" class="text-brand-500 font-bold hover:underline">Yük
                            Sahibi Kaydı</a>
                        <span>•</span>
                        <a href="{{ route('register.driver') }}"
                            class="text-neutral-600 dark:text-neutral-300 font-bold hover:underline">Şoför Kaydı</a>
                    </div>
                </div>
            </form>
        @else
            <div class="space-y-5 animate-slide-up">
                <div class="text-center space-y-1">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Doğrulama Kodu</h3>
                    <p class="text-[11px] text-neutral-400">E-posta adresinize gönderilen 6 haneli güvenlik kodunu girin.</p>
                </div>

                <form wire:submit.prevent="verifyOtp" class="space-y-4">
                    @if(session()->has('otp_message'))
                        <div
                            class="bg-emerald-500/10 text-emerald-600 p-3 rounded-xl text-center border border-emerald-500/20 font-medium">
                            {{ session('otp_message') }}
                        </div>
                    @endif

                    <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="otp" maxlength="6" placeholder="000000"
                        class="form-input tracking-[0.5em] text-center text-lg font-bold">
                    @error('otp') <span class="form-error">{{ $message }}</span> @enderror

                    <button type="submit"
                        class="btn-primary w-full py-3">
                        <span wire:loading.remove wire:target="verifyOtp">Doğrula ve Oturumu Aç</span>
                        <span wire:loading wire:target="verifyOtp">Doğrulanıyor...</span>
                    </button>
                </form>

                <button type="button" wire:click="resendOtp"
                    class="w-full text-center text-[11px] text-brand-500 font-bold hover:underline">
                    Kodu tekrar gönder
                </button>

                <button wire:click="backToCredentials"
                    class="w-full text-center text-[11px] text-neutral-400 hover:text-neutral-600 transition-colors">
                    ← Giriş ekranına geri dön
                </button>
            </div>
        @endif

    </div>
</div>
