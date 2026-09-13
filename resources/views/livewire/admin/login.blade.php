<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Locked;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Mail\AdminOtpMail;

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
        $key = 'admin-login:' . hash('sha256', Str::lower($identifier) . '|' . request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('identifier', 'Çok fazla giriş denemesi yapıldı. Lütfen bir dakika bekleyin.');
            return;
        }

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (!$user || !Hash::check($this->password, $user->password) ||
            $user->current_role !== 'admin' || !$user->hasRole('super_admin') ||
            !$user->is_active || $user->banned_at !== null) {
            RateLimiter::hit($key, 60);
            $this->addError('identifier', 'Giriş bilgileri hatalı veya bu alana erişim yetkiniz yok.');
            return;
        }

        RateLimiter::clear($key);
        $this->tempUserId = $user->id;
        $otpCode = (string) random_int(100000, 999999);
        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
        } catch (\Throwable $e) {
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            Log::error('Yönetici OTP e-postası gönderilemedi.', ['user_id' => $user->id]);
            $this->addError('identifier', 'Doğrulama kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.');
            return;
        }

        $this->step = 2;
    }

    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|numeric|digits:6']);
        $user = $this->tempUserId ? User::find($this->tempUserId) : null;
        if (!$user) {
            abort(403);
        }

        $key = 'admin-otp:' . $user->id . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            $this->addError('otp', 'Çok fazla hatalı deneme yapıldı. Baştan giriş yapın.');
            return;
        }

        if (!$user->otp_code || !$user->otp_expires_at || now()->greaterThan($user->otp_expires_at) ||
            !Hash::check($this->otp, $user->otp_code)) {
            RateLimiter::hit($key, 300);
            $this->addError('otp', 'Kod hatalı veya süresi dolmuş.');
            return;
        }

        if ($user->current_role !== 'admin' || !$user->hasRole('super_admin') ||
            !$user->is_active || $user->banned_at !== null) {
            abort(403);
        }

        RateLimiter::clear($key);
        $user->update(['otp_code' => null, 'otp_expires_at' => null]);
        Auth::login($user, true);
        request()->session()->regenerate();
        return $this->redirect(route('admin.dashboard'), navigate: true);
    }

    public function backToCredentials(): void
    {
        $this->reset(['step', 'otp', 'tempUserId', 'password']);
    }
}; ?>

<div
    class="min-h-screen flex items-center justify-center bg-neutral-100 dark:bg-neutral-900 transition-colors duration-300 relative">
    <!-- Sağ Üst Köşe - Minimalist Koyu/Açık Tema Switcher -->
    <div class="absolute top-6 right-6" x-data>
        <button @click="$store.darkMode.toggle()"
            class="p-2.5 rounded-full bg-white dark:bg-neutral-800 border border-neutral-200/50 dark:border-neutral-700/30 text-neutral-600 dark:text-neutral-300 hover:scale-105 transition-all duration-300 shadow-apple-sm">
            <!-- Açık Tema İkonu -->
            <svg x-show="!$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
            </svg>
            <!-- Koyu Tema İkonu -->
            <svg x-show="$store.darkMode.on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                style="display: none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
        </button>
    </div>

    <!-- Giriş Kartı -->
    <div class="w-full max-w-md p-8 apple-glass rounded-3xl mx-4 relative overflow-hidden">

        <!-- Üst Marka Alanı -->
        <div class="flex flex-col items-center mb-8">
            <div class="flex items-center space-x-2 text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
            </div>
            <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1.5 font-medium">Yönetici Giriş Kapısı</p>
        </div>

        @if (session()->has('error'))
            <div
                class="mb-5 p-3.5 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center space-x-2 animate-fade-in">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($step === 1)
            <!-- AŞAMA 1: Giriş Bilgileri Formu -->
            <form wire:submit.prevent="submitCredentials" class="space-y-5 animate-fade-in">

                <!-- Giriş Tipi Input (autocomplete="username" eklendi) -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Yönetici Kimliği</label>
                    <input type="text" wire:model="identifier" autocomplete="username"
                        placeholder="Kullanıcı adı veya e-posta"
                        class="w-full px-4 py-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-sm rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-all duration-300">
                    @error('identifier') <span
                    class="text-red-500 text-[11px] block mt-1 font-medium pl-1">{{ $message }}</span> @enderror
                </div>

                <!-- Şifre Input (autocomplete="current-password" eklendi) -->
                <div class="space-y-1.5">
                    <div class="flex justify-between items-center">
                        <label class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Şifre</label>
                        <a href="#"
                            class="text-[11px] text-neutral-400 dark:text-neutral-500 hover:text-brand-500 dark:hover:text-brand-500 transition-colors duration-300">Şifremi
                            Unuttum?</a>
                    </div>
                    <input type="password" wire:model="password" autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full px-4 py-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-sm rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-all duration-300">
                    @error('password') <span
                    class="text-red-500 text-[11px] block mt-1 font-medium pl-1">{{ $message }}</span> @enderror
                </div>

                <!-- Giriş Yap Butonu -->
                <button type="submit" class="w-full btn-apple-brand py-3.5 flex items-center justify-center space-x-2">
                    <span wire:loading.remove wire:target="submitCredentials">Kimliği Doğrula</span>
                    <span wire:loading wire:target="submitCredentials"
                        class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                </button>
            </form>
        @else
            <!-- AŞAMA 2: OTP Doğrulama Formu -->
            <div class="space-y-6 animate-slide-up">

                <div class="text-center space-y-1">
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">Tek Kullanımlık Şifre (OTP)
                    </h3>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Güvenlik kodunuz e-posta adresinize
                        gönderilmiştir. Lütfen gelen 6 haneli kodu girin.</p>
                </div>

                <form wire:submit.prevent="verifyOtp" class="space-y-5">

                    <!-- OTP Giriş Alanı -->
                    <div class="space-y-1.5">
                        <input type="text" wire:model="otp" maxlength="6" placeholder="000000"
                            class="w-full tracking-[0.5em] text-center px-4 py-3.5 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-lg font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-all duration-300">
                        @error('otp') <span
                            class="text-red-500 text-[11px] block mt-1 text-center font-medium">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Doğrula Butonu -->
                    <button type="submit"
                        class="w-full btn-apple-primary py-3.5 flex items-center justify-center space-x-2">
                        <span wire:loading.remove wire:target="verifyOtp">Oturum Aç</span>
                        <span wire:loading wire:target="verifyOtp"
                            class="w-5 h-5 border-2 border-neutral-500/30 border-t-neutral-800 rounded-full animate-spin"></span>
                    </button>
                </form>

                <!-- Geri Dön Linki -->
                <button wire:click="backToCredentials"
                    class="w-full text-center text-xs text-neutral-400 dark:text-neutral-500 hover:text-neutral-600 dark:hover:text-neutral-300 font-medium transition-colors duration-300">
                    ← Giriş ekranına geri dön
                </button>
            </div>
        @endif
    </div>
</div>
