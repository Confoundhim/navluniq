{{-- Hesap bilgileri + şifre formları; şoför ve yük sahibi profilinde birebir aynı (ManagesAccountSecurity trait'i). --}}
<form wire:submit.prevent="updateProfile" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
    <h3 class="section-title">Hesap bilgileri</h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
        <div>
            <label class="form-label">Ad</label>
            <input type="text" wire:model="first_name" class="form-input">
            @error('first_name') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">Soyad</label>
            <input type="text" wire:model="last_name" class="form-input">
            @error('last_name') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">E-posta adresi</label>
            <input type="email" wire:model="email" class="form-input" @disabled($emailChangePending)>
            @error('email') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">Cep telefonu</label>
            <input type="text" wire:model="phone" inputmode="tel" class="form-input tabular-nums">
            @error('phone') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="form-label">Mevcut şifre <span class="font-normal text-neutral-400">(yalnız e-posta ya da telefon değişirse)</span></label>
            <input type="password" wire:model="contact_password" autocomplete="current-password" class="form-input">
            @error('contact_password') <span class="form-error">{{ $message }}</span> @enderror
        </div>
    </div>

    @if($emailChangePending)
        <div class="rounded-xl border border-brand-200 dark:border-brand-900/60 bg-brand-50 dark:bg-brand-900/10 p-4 space-y-3 text-xs">
            <p class="text-neutral-700 dark:text-neutral-200">Yeni e-posta adresinize 6 haneli doğrulama kodu gönderildi. Kod girilince yeni adres geçerli olur; o zamana kadar mevcut adres kullanılır.</p>
            <div class="flex flex-wrap gap-2 items-start">
                <div class="flex-1 min-w-[140px]">
                    <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="email_change_otp" maxlength="6" placeholder="000000" class="form-input tracking-[0.4em] text-center font-bold">
                    @error('email_change_otp') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <button type="button" wire:click="confirmEmailChange" class="btn-primary py-2 text-xs">Kodu doğrula</button>
                <button type="button" wire:click="cancelEmailChange" class="btn-secondary py-2 text-xs">Vazgeç</button>
            </div>
        </div>
    @endif

    <div class="pt-2 flex justify-end">
        <button type="submit" class="btn-primary py-2 text-xs">
            <span wire:loading.remove wire:target="updateProfile">Değişiklikleri kaydet</span>
            <span wire:loading wire:target="updateProfile">Kaydediliyor...</span>
        </button>
    </div>
</form>

<form wire:submit.prevent="updatePassword" class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
    <h3 class="section-title">Şifre değiştir</h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
        <div>
            <label class="form-label">Mevcut şifre</label>
            <input type="password" wire:model="current_password" autocomplete="current-password" class="form-input">
            @error('current_password') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">Yeni şifre (en az 12 karakter)</label>
            <input type="password" wire:model="new_password" autocomplete="new-password" class="form-input">
            @error('new_password') <span class="form-error">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="form-label">Yeni şifre (tekrar)</label>
            <input type="password" wire:model="new_password_confirmation" autocomplete="new-password" class="form-input">
        </div>
    </div>
    <p class="text-[11px] text-neutral-500">Şifre değişince diğer cihazlardaki oturumlar kapanır.</p>
    <div class="pt-2 flex flex-wrap items-center justify-between gap-3">
        <button type="button" wire:click="exportMyData" class="text-[11px] text-neutral-500 hover:text-brand-500 underline">Hesabımdaki verileri indir (JSON)</button>
        <button type="submit" class="btn-secondary py-2 text-xs">Şifreyi güncelle</button>
    </div>
</form>
