<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use App\Mail\AdminOtpMail;

new class extends Component {
    public bool $switchModalOpen = false;
    public string $otp_input = '';

    #[Locked]
    public string $targetRole = 'driver';

    public function openRoleSwitchModal(string $role): void
    {
        if (!in_array($role, ['driver', 'cargo_owner'], true)) {
            abort(422);
        }

        $user = Auth::user();
        if (!$user || !$this->hasTargetProfile($role)) {
            $this->addError('otp_input', 'Bu role ait onaylı bir profiliniz bulunmuyor.');
            return;
        }

        $key = 'role-switch-send:' . $user->id . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('otp_input', 'Çok fazla kod istendi. Lütfen bir dakika sonra tekrar deneyin.');
            return;
        }
        RateLimiter::hit($key, 60);

        $this->targetRole = $role;
        $this->otp_input = '';
        $otpCode = (string) random_int(100000, 999999);

        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => now()->addMinutes(5),
        ]);
        session()->put('role_switch_target', $role);

        try {
            Mail::to($user->email)->send(new AdminOtpMail($otpCode));
        } catch (\Throwable $e) {
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            session()->forget('role_switch_target');
            Log::error('Rol geçişi OTP e-postası gönderilemedi.', ['user_id' => $user->id]);
            $this->addError('otp_input', 'Doğrulama kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.');
            return;
        }

        $this->switchModalOpen = true;
    }

    public function executeRoleSwitch(): void
    {
        $this->validate(['otp_input' => 'required|numeric|digits:6']);

        $user = Auth::user();
        $sessionTarget = session('role_switch_target');
        if (!$user || $sessionTarget !== $this->targetRole || !$this->hasTargetProfile($this->targetRole)) {
            abort(403);
        }

        $key = 'role-switch-verify:' . $user->id . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->clearChallenge($user);
            $this->addError('otp_input', 'Çok fazla hatalı deneme yapıldı. Yeni kod isteyin.');
            return;
        }

        if (!$user->otp_expires_at || now()->greaterThan($user->otp_expires_at) ||
            !$user->otp_code || !Hash::check($this->otp_input, $user->otp_code)) {
            RateLimiter::hit($key, 300);
            $this->addError('otp_input', 'Kod hatalı veya süresi dolmuş.');
            return;
        }

        RateLimiter::clear($key);
        $role = $this->targetRole;
        $this->clearChallenge($user);
        $user->switchRole($role);
        request()->session()->regenerate();
        $this->switchModalOpen = false;

        $this->redirect(
            $role === 'driver' ? route('driver.dashboard') : route('cargo-owner.dashboard'),
            navigate: true
        );
    }

    private function hasTargetProfile(string $role): bool
    {
        $user = Auth::user();
        return $role === 'driver' ? (bool) $user?->driverProfile : (bool) $user?->cargoOwnerProfile;
    }

    private function clearChallenge($user): void
    {
        $user->update(['otp_code' => null, 'otp_expires_at' => null]);
        session()->forget('role_switch_target');
    }
}; ?>

<div>
    @if(auth()->user()?->current_role === 'cargo_owner' && auth()->user()?->driverProfile)
        <button type="button" wire:click="openRoleSwitchModal('driver')"
            class="w-full rounded-xl border border-neutral-700 bg-neutral-800 px-3 py-2 text-xs font-medium text-neutral-200">
            Şoför moduna geç
        </button>
    @elseif(auth()->user()?->current_role === 'driver' && auth()->user()?->cargoOwnerProfile)
        <button type="button" wire:click="openRoleSwitchModal('cargo_owner')"
            class="w-full rounded-xl border border-neutral-700 bg-neutral-800 px-3 py-2 text-xs font-medium text-neutral-200">
            Yük sahibi moduna geç
        </button>
    @endif

    @if($switchModalOpen)
        <div class="fixed inset-0 z-[99999] flex items-start sm:items-center justify-center overflow-y-auto p-4 bg-neutral-950/85 p-4 backdrop-blur-md">
            <div class="w-full max-w-md space-y-5 rounded-2xl border border-neutral-800 bg-neutral-900 p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-white">E-posta koduyla rol değiştir</h3>
                    <button type="button" wire:click="$set('switchModalOpen', false)" class="text-neutral-400">✕</button>
                </div>
                <p class="text-xs leading-5 text-neutral-400">Kod {{ auth()->user()?->email }} adresine gönderildi ve beş dakika geçerlidir.</p>
                <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                    wire:model.defer="otp_input" placeholder="000000"
                    class="w-full rounded-xl border border-neutral-800 bg-neutral-950 p-3 text-center font-mono text-lg tracking-[0.4em] text-white">
                @error('otp_input') <span class="block text-xs text-rose-400">{{ $message }}</span> @enderror
                <button type="button" wire:click="executeRoleSwitch"
                    class="w-full rounded-xl bg-brand-500 px-4 py-3 text-xs font-bold text-white">
                    <span wire:loading.remove wire:target="executeRoleSwitch">Doğrula ve geç</span>
                    <span wire:loading wire:target="executeRoleSwitch">Doğrulanıyor…</span>
                </button>
            </div>
        </div>
    @endif
</div>
