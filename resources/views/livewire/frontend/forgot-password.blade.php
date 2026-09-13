<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

new class extends Component {
    public string $email = '';

    public bool $sent = false;

    public function sendResetLink(): void
    {
        $this->validate(['email' => 'required|email|max:255']);

        $email = mb_strtolower(trim($this->email));
        $key = 'password-reset:'.hash('sha256', $email.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Çok fazla istek gönderildi. Lütfen 10 dakika sonra tekrar deneyin.');

            return;
        }
        RateLimiter::hit($key, 600);

        // Hesabın var olup olmadığını dışarı sızdırmamak için sonuç ne olursa olsun aynı mesaj gösterilir.
        Password::sendResetLink(['email' => $email]);
        $this->sent = true;
    }
}; ?>

<div class="max-w-md mx-auto py-12 px-6 animate-fade-in">
    <div class="apple-glass rounded-3xl p-8 space-y-6 shadow-apple-lg border border-neutral-200/60 dark:border-neutral-800">
        <div class="flex flex-col items-center mb-2">
            <div class="flex items-center space-x-2 text-2xl font-black text-neutral-900 dark:text-white">
                <span>Navlun</span><span class="text-brand-500">IQ</span>
            </div>
            <p class="text-xs text-neutral-400 mt-1 font-medium">Şifre sıfırlama</p>
        </div>

        @if($sent)
            <div class="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 p-4 rounded-xl text-xs text-center border border-emerald-500/20 font-medium leading-relaxed">
                Bu e-posta adresi sistemde kayıtlıysa, şifre sıfırlama bağlantısı gönderildi. Gelen kutunuzu ve spam klasörünü kontrol edin. Bağlantı 60 dakika geçerlidir.
            </div>
            <a href="{{ route('login') }}" wire:navigate class="block text-center text-[11px] text-neutral-400 hover:text-neutral-600">← Giriş ekranına dön</a>
        @else
            <form wire:submit.prevent="sendResetLink" class="space-y-4 text-xs">
                <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">Kayıtlı e-posta adresinizi girin. Size yeni şifre belirlemeniz için bir bağlantı göndereceğiz.</p>
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">E-posta adresi</label>
                    <input type="email" wire:model="email" autocomplete="email" placeholder="ornek@sirket.com"
                        class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/50 dark:border-neutral-700/50 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20 text-neutral-900 dark:text-white">
                    @error('email') <span class="text-red-500 text-[10px]">{{ $message }}</span> @enderror
                </div>
                <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-bold shadow-apple-md flex justify-center items-center">
                    <span wire:loading.remove wire:target="sendResetLink">Sıfırlama bağlantısı gönder</span>
                    <span wire:loading wire:target="sendResetLink">Gönderiliyor...</span>
                </button>
                <a href="{{ route('login') }}" wire:navigate class="block text-center text-[11px] text-neutral-400 hover:text-neutral-600">← Giriş ekranına dön</a>
            </form>
        @endif
    </div>
</div>
