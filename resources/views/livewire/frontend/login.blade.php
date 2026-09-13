<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Locked;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
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

    public bool $isNetgsmActive = false;

    public function mount(): void
    {
        // Gerçek WhatsApp OTP sağlayıcısı doğrulanana kadar yalnız e-posta kullanılır.
        $this->isNetgsmActive = false;
    }

    public function submitCredentials(): void
    {
        $this->validate([
            'identifier' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $rawIdentifier = trim($this->identifier);
        $loginKey = 'login:' . hash('sha256', Str::lower($rawIdentifier) . '|' . request()->ip());
        if (RateLimiter::tooManyAttempts($loginKey, 5)) {
            $this->addError('identifier', 'Çok fazla giriş denemesi yapıldı. Lütfen bir dakika bekleyin.');
            return;
        }

        $digitsOnly = preg_replace('/[^0-9]/', '', $rawIdentifier);
        $cleanPhone10 = ltrim($digitsOnly, '0');
        if (str_starts_with($cleanPhone10, '90') && strlen($cleanPhone10) === 12) {
            $cleanPhone10 = substr($cleanPhone10, 2);
        }

        $user = User::query()
            ->where('email', $rawIdentifier)
            ->orWhere('phone', $rawIdentifier)
            ->orWhere('phone', $cleanPhone10)
            ->orWhere('phone', '0' . $cleanPhone10)
            ->first();

        if (
            !$user || !Hash::check($this->password, $user->password) ||
            in_array($user->current_role, ['admin', 'super_admin'], true) ||
            !$user->is_active || $user->banned_at !== null
        ) {
            RateLimiter::hit($loginKey, 60);
            $this->addError('identifier', 'Giriş bilgileri hatalı veya hesabın erişimi kapalı.');
            return;
        }

        RateLimiter::clear($loginKey);
        $this->tempUserId = $user->id;
        if ($this->generateAndSendOtp($user)) {
            $this->step = 2;
        }
    }

    private function generateAndSendOtp(User $user): bool
    {
        $sendKey = 'login-otp-send:' . $user->id . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            $this->addError('identifier', 'Çok fazla kod istendi. Lütfen bir dakika bekleyin.');
            return false;
        }
        RateLimiter::hit($sendKey, 60);

        $otpCode = (string) random_int(100000, 999999);
        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
        } catch (\Throwable $e) {
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            Log::error('Kullanıcı OTP e-postası gönderilemedi.', ['user_id' => $user->id]);
            $this->addError('identifier', 'Doğrulama kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.');
            return false;
        }

        session()->flash('otp_message', 'Doğrulama kodu e-posta adresinize gönderildi.');
        return true;
    }

    public function requestWhatsappOtp(): void
    {
        $this->addError('otp', 'WhatsApp OTP henüz güvenli sağlayıcıya bağlanmadı. E-posta kodunu kullanın.');
    }

    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|numeric|digits:6']);
        $user = $this->tempUserId ? User::find($this->tempUserId) : null;
        if (!$user) {
            abort(403);
        }

        $verifyKey = 'login-otp-verify:' . $user->id . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($verifyKey, 5)) {
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            $this->addError('otp', 'Çok fazla hatalı deneme yapıldı. Baştan giriş yapın.');
            return;
        }

        if (
            !$user->otp_code || !$user->otp_expires_at || now()->greaterThan($user->otp_expires_at) ||
            !Hash::check($this->otp, $user->otp_code)
        ) {
            RateLimiter::hit($verifyKey, 300);
            $this->addError('otp', 'Kod hatalı veya süresi dolmuş.');
            return;
        }

        if (!$user->is_active || $user->banned_at !== null || in_array($user->current_role, ['admin', 'super_admin'], true)) {
            abort(403);
        }

        RateLimiter::clear($verifyKey);
        $user->update(['otp_code' => null, 'otp_expires_at' => null]);
        Auth::login($user, true);
        request()->session()->regenerate();
        return $this->redirect('/panel', navigate: true);
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
            <p class="text-xs text-neutral-400 mt-1 font-medium">Kullanıcı Giriş Kapısı</p>
        </div>

        @if($step === 1)
            <form wire:submit.prevent="submitCredentials" class="space-y-4 text-xs">
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Telefon Numarası veya E-Posta</label>
                    <input type="text" wire:model.defer="identifier" autocomplete="username"
                        placeholder="05XXXXXXXXX veya e-posta"
                        class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                    @error('identifier') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <div class="flex justify-between items-center">
                        <label class="font-semibold text-neutral-500">Şifre</label>
                        <a href="#" class="text-[11px] text-neutral-400 hover:text-brand-500 transition-colors">Şifremi
                            Unuttum?</a>
                    </div>
                    <input type="password" wire:model.defer="password" autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                    @error('password') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                </div>

                <button type="submit"
                    class="w-full btn-apple-brand py-3.5 text-xs font-bold shadow-apple-md flex justify-center items-center">
                    <span wire:loading.remove wire:target="submitCredentials">Giriş Yap ve OTP İste</span>
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
            <div class="space-y-5 animate-slide-up text-xs">
                <div class="text-center space-y-1">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Doğrulama Kodu (OTP)</h3>
                    <p class="text-[11px] text-neutral-400">Telefonunuza veya e-postanıza gönderilen 6 haneli güvenlik
                        kodunu girin.</p>
                </div>

                <form wire:submit.prevent="verifyOtp" class="space-y-4">
                    @if(session()->has('otp_message'))
                        <div
                            class="bg-emerald-500/10 text-emerald-600 p-3 rounded-xl text-center border border-emerald-500/20 font-medium">
                            {{ session('otp_message') }}
                        </div>
                    @endif

                    <input type="text" wire:model.defer="otp" maxlength="6" placeholder="000000"
                        class="w-full tracking-[0.5em] text-center p-3.5 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 text-neutral-900 dark:text-white text-lg font-bold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                    @error('otp') <span class="text-red-500 text-[10px] text-center block">{{ $message }}</span> @enderror

                    <button type="submit"
                        class="w-full btn-apple-primary py-3.5 text-xs font-bold flex justify-center items-center">
                        <span wire:loading.remove wire:target="verifyOtp">Doğrula ve Oturumu Aç</span>
                        <span wire:loading wire:target="verifyOtp">Doğrulanıyor...</span>
                    </button>

                    @if($isNetgsmActive)
                        <div class="pt-2 border-t border-neutral-200/50 dark:border-neutral-800/50 text-center">
                            <button type="button" wire:click="requestWhatsappOtp"
                                class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 hover:underline flex items-center justify-center w-full">
                                <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 24 24">
                                    <path
                                        d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                </svg>
                                WhatsApp ile Gönder
                            </button>
                        </div>
                    @endif
                </form>

                <button wire:click="backToCredentials"
                    class="w-full text-center text-[11px] text-neutral-400 hover:text-neutral-600 transition-colors">
                    ← Giriş ekranına geri dön
                </button>
            </div>
        @endif

    </div>
</div>
