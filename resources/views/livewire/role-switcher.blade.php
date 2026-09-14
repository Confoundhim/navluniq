<?php

use App\Services\OtpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public bool $switchModalOpen = false;

    public string $otp_input = '';

    #[Locked]
    public string $targetRole = 'driver';

    public function openRoleSwitchModal(string $role): void
    {
        if (! in_array($role, ['driver', 'cargo_owner'], true)) {
            return;
        }

        $user = Auth::user();
        if (! $user || ! $this->hasTargetProfile($role)) {
            $this->addError('otp_input', 'Bu role ait bir profiliniz bulunmuyor.');

            return;
        }

        $this->targetRole = $role;
        $this->otp_input = '';

        $error = app(OtpService::class)->send($user, 'Panel rolünüzü değiştirmek', 'role-switch');
        if ($error) {
            $this->addError('otp_input', $error);
            $this->switchModalOpen = true;

            return;
        }

        session()->put('role_switch_target', $role);
        $this->switchModalOpen = true;
    }

    public function closeModal(): void
    {
        $this->switchModalOpen = false;
        $this->otp_input = '';
        session()->forget('role_switch_target');
        if ($user = Auth::user()) {
            app(OtpService::class)->clear($user);
        }
    }

    public function executeRoleSwitch(): void
    {
        $this->validate(['otp_input' => 'required|digits:6']);

        $user = Auth::user();
        if (! $user || session('role_switch_target') !== $this->targetRole || ! $this->hasTargetProfile($this->targetRole)) {
            $this->addError('otp_input', 'Doğrulama oturumu bulunamadı. Lütfen yeniden kod isteyin.');

            return;
        }

        if ($error = app(OtpService::class)->verify($user, $this->otp_input, 'role-switch')) {
            $this->addError('otp_input', $error);

            return;
        }

        $role = $this->targetRole;
        session()->forget('role_switch_target');
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
}; ?>


<div>
    @if(auth()->user()?->current_role === 'cargo_owner' && auth()->user()?->driverProfile)
        <button type="button" wire:click="openRoleSwitchModal('driver')"
            class="btn-secondary w-full py-2 text-xs">
            Şoför moduna geç
        </button>
    @elseif(auth()->user()?->current_role === 'driver' && auth()->user()?->cargoOwnerProfile)
        <button type="button" wire:click="openRoleSwitchModal('cargo_owner')"
            class="btn-secondary w-full py-2 text-xs">
            Yük sahibi moduna geç
        </button>
    @endif

    @if($switchModalOpen)
        <div class="fixed inset-0 z-[99999] flex items-start sm:items-center justify-center overflow-y-auto p-4 bg-neutral-950/70 p-4 backdrop-blur-md">
            <div class="w-full max-w-md space-y-5 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-neutral-900 dark:text-white">E-posta koduyla rol değiştir</h3>
                    <button type="button" wire:click="closeModal" class="text-neutral-500 dark:text-neutral-400" aria-label="Kapat">&times;</button>
                </div>
                <p class="text-xs leading-5 text-neutral-500 dark:text-neutral-400">Kod {{ auth()->user()?->email }} adresine gönderildi ve beş dakika geçerlidir.</p>
                <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                    wire:model="otp_input" placeholder="000000"
                    class="w-full rounded-xl border border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-950 p-3 text-center font-mono text-lg tracking-[0.4em] text-neutral-900 dark:text-white">
                @error('otp_input') <span class="block text-xs text-rose-600 dark:text-rose-400">{{ $message }}</span> @enderror
                <button type="button" wire:click="executeRoleSwitch"
                    class="w-full rounded-xl bg-brand-500 px-4 py-3 text-xs font-bold text-white">
                    <span wire:loading.remove wire:target="executeRoleSwitch">Doğrula ve geç</span>
                    <span wire:loading wire:target="executeRoleSwitch">Doğrulanıyor…</span>
                </button>
            </div>
        </div>
    @endif
</div>
