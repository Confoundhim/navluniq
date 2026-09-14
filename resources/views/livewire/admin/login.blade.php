<?php

use App\Models\ActivityLog;
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

    #[Locked]
    public ?int $tempUserId = null;

    public function submitCredentials(): void
    {
        $this->validate([
            'identifier' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $identifier = trim($this->identifier);
        $key = 'admin-login:'.hash('sha256', Str::lower($identifier).'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('identifier', 'Çok fazla giriş denemesi yapıldı. Lütfen bir dakika bekleyin.');

            return;
        }

        $phone = Phone::normalize($identifier);
        $user = User::query()
            ->where(function ($q) use ($identifier, $phone): void {
                $q->where('email', Str::lower($identifier));
                if ($phone) {
                    $q->orWhereIn('phone', Phone::variants($phone));
                }
            })
            ->first();

        if (! $user || ! Hash::check($this->password, $user->password) || ! $user->isAdminPanelUser()) {
            RateLimiter::hit($key, 60);
            $this->addError('identifier', 'Giriş bilgileri hatalı veya bu alana erişim yetkiniz yok.');

            return;
        }

        RateLimiter::clear($key);

        $error = app(OtpService::class)->send($user, 'Yönetim paneline giriş yapmak', 'admin-login');
        if ($error) {
            $this->addError('identifier', $error);

            return;
        }

        $this->tempUserId = $user->id;
        $this->otp = '';
        $this->step = 2;
    }

    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|digits:6']);

        $user = $this->tempUserId ? User::find($this->tempUserId) : null;
        if (! $user || ! $user->isAdminPanelUser()) {
            $this->reset(['step', 'otp', 'tempUserId', 'password']);
            $this->addError('identifier', 'Oturum doğrulanamadı. Lütfen tekrar giriş yapın.');

            return;
        }

        $error = app(OtpService::class)->verify($user, $this->otp, 'admin-login');
        if ($error) {
            $this->addError('otp', $error);

            return;
        }

        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user);
        session()->regenerate();
        ActivityLog::record('admin.login', 'Yönetim paneline giriş yapıldı.', $user->id, $user);

        return $this->redirect(route('admin.dashboard'), navigate: true);
    }

    public function backToCredentials(): void
    {
        $this->reset(['step', 'otp', 'tempUserId', 'password']);
    }
}; ?>

<div class="min-h-screen flex items-center justify-center bg-neutral-100 dark:bg-neutral-900 transition-colors duration-300 relative">
    <div class="absolute top-6 right-6" x-data>
        <button @click="$store.darkMode.toggle()"
            class="p-2.5 rounded-full bg-white dark:bg-neutral-800 border border-neutral-200/50 dark:border-neutral-700/30 text-neutral-600 dark:text-neutral-300 hover:scale-105 transition-all duration-300 shadow-apple-sm">
            <svg x-show="!$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
            </svg>
            <svg x-show="$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
        </button>
    </div>

    <div class="w-full max-w-md p-8 apple-glass rounded-3xl mx-4 relative overflow-hidden">
        <div class="flex flex-col items-center mb-8">
            <div class="flex items-center space-x-2 text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
            </div>
            <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1.5 font-medium">Yönetim paneli girişi</p>
        </div>

        @if (session()->has('error'))
            <div class="mb-5 p-3.5 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center space-x-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($step === 1)
            <form wire:submit="submitCredentials" class="space-y-5">
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">E-posta veya telefon</label>
                    <input type="text" wire:model="identifier" autocomplete="username" placeholder="ornek@navluniq.com"
                        class="form-input">
                    @error('identifier') <span class="text-red-500 text-[11px] block mt-1 font-medium pl-1">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <div class="flex justify-between items-center">
                        <label class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Şifre</label>
                        <a href="{{ route('password.request') }}" class="text-[11px] text-neutral-400 dark:text-neutral-500 hover:text-brand-500 transition-colors duration-300">Şifremi unuttum</a>
                    </div>
                    <input type="password" wire:model="password" autocomplete="current-password" placeholder="••••••••"
                        class="form-input">
                    @error('password') <span class="text-red-500 text-[11px] block mt-1 font-medium pl-1">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full btn-apple-brand py-3.5 flex items-center justify-center space-x-2">
                    <span wire:loading.remove wire:target="submitCredentials">Devam et</span>
                    <span wire:loading wire:target="submitCredentials" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                </button>
            </form>
        @else
            <div class="space-y-6">
                <div class="text-center space-y-1">
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">E-posta doğrulama kodu</h3>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">E-posta adresinize gönderilen 6 haneli kodu girin. Kod {{ \App\Services\OtpService::TTL_MINUTES }} dakika geçerlidir.</p>
                </div>

                <form wire:submit="verifyOtp" class="space-y-5">
                    <div class="space-y-1.5">
                        <input type="text" wire:model="otp" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000"
                            class="form-input tracking-[0.5em] text-center text-lg font-bold">
                        @error('otp') <span class="text-red-500 text-[11px] block mt-1 text-center font-medium">{{ $message }}</span> @enderror
                        @error('identifier') <span class="text-red-500 text-[11px] block mt-1 text-center font-medium">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" class="w-full btn-apple-primary py-3.5 flex items-center justify-center space-x-2">
                        <span wire:loading.remove wire:target="verifyOtp">Oturum aç</span>
                        <span wire:loading wire:target="verifyOtp" class="w-5 h-5 border-2 border-neutral-500/30 border-t-neutral-800 rounded-full animate-spin"></span>
                    </button>
                </form>

                <button type="button" wire:click="backToCredentials"
                    class="w-full text-center text-xs text-neutral-400 dark:text-neutral-500 hover:text-neutral-600 dark:hover:text-neutral-300 font-medium transition-colors duration-300">
                    Giriş ekranına dön
                </button>
            </div>
        @endif
    </div>
</div>
