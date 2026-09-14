<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $done = false;

    public function mount(string $token, string $email = ''): void
    {
        $this->token = $token;
        $this->email = $email;
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:12|max:255|confirmed',
        ], [
            'password.confirmed' => 'Girdiğiniz şifreler birbiriyle eşleşmiyor.',
            'password.min' => 'Şifre en az 12 karakter olmalıdır.',
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'otp_code' => null,
                    'otp_expires_at' => null,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', 'Bağlantı geçersiz veya süresi dolmuş. Lütfen yeni bir sıfırlama bağlantısı isteyin.');

            return;
        }

        $this->reset(['password', 'password_confirmation']);
        $this->done = true;
    }
}; ?>

<div class="max-w-md mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">
        <div class="flex flex-col items-center mb-2">
            <div class="flex items-center space-x-2 text-2xl font-black text-neutral-900 dark:text-white">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
            </div>
            <p class="text-xs text-neutral-400 mt-1 font-medium">Yeni şifre belirle</p>
        </div>

        @if($done)
            <div class="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 p-4 rounded-xl text-xs text-center border border-emerald-500/20 font-medium">
                Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.
            </div>
            <a href="{{ route('login') }}" wire:navigate class="btn-primary w-full py-3">Giriş yap</a>
        @else
            <form wire:submit.prevent="resetPassword" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="form-label">E-posta adresi</label>
                    <input type="email" wire:model="email" autocomplete="email"
                        class="form-input">
                    @error('email') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-1.5">
                    <label class="form-label">Yeni şifre (en az 12 karakter)</label>
                    <input type="password" wire:model="password" autocomplete="new-password"
                        class="form-input">
                    @error('password') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-1.5">
                    <label class="form-label">Yeni şifre (tekrar)</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                        class="form-input">
                </div>
                <button type="submit" class="btn-primary w-full py-3">
                    <span wire:loading.remove wire:target="resetPassword">Şifreyi güncelle</span>
                    <span wire:loading wire:target="resetPassword">Kaydediliyor...</span>
                </button>
            </form>
        @endif
    </div>
</div>
