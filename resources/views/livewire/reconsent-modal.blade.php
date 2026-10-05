<?php

use App\Models\UserConsent;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Sözleşme / KVKK metni sürümü değiştiğinde panelde çıkan onay penceresi: kullanıcı yeni sürümü onaylamadan
 * panelde işlem yapmaz. Onay, kayıt anındaki gibi UserConsent satırı olarak (sürüm, IP, tarayıcı) saklanır.
 */
new class extends Component {
    public bool $accept = false;

    public bool $done = false;

    public function approve(): void
    {
        $this->validate(['accept' => 'accepted'], ['accept.accepted' => 'Devam etmek için metinleri onaylamanız gerekir.']);
        UserConsent::recordRegistration(Auth::user());
        $this->done = true;
    }

    public function with(): array
    {
        return ['version' => UserConsent::currentVersion(), 'effective' => \App\Support\Settings::string('legal_effective_date')];
    }
}; ?>

<div>
    @unless($done)
        <div class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-sm">
            <form wire:submit.prevent="approve" class="w-full max-w-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-xs">
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Sözleşmeler güncellendi</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">Kullanıcı sözleşmesi ve KVKK aydınlatma metni yenilendi (sürüm {{ $version }}{{ $effective ? ', yürürlük '.$effective : '' }}). Devam etmek için güncel metinleri onaylayın.</p>
                </div>
                <div class="flex flex-wrap gap-3 text-[11px]">
                    <a href="{{ route('contracts', 'kullanici-sozlesmesi') }}" target="_blank" rel="noopener" class="text-brand-500 font-semibold hover:underline">Kullanıcı sözleşmesi</a>
                    <a href="{{ route('contracts', 'kvkk') }}" target="_blank" rel="noopener" class="text-brand-500 font-semibold hover:underline">KVKK aydınlatma metni</a>
                </div>
                <label class="flex items-start gap-2 select-none text-neutral-700 dark:text-neutral-200">
                    <input type="checkbox" wire:model="accept" class="mt-0.5 rounded border-neutral-300 dark:border-neutral-700 text-brand-500 focus:ring-brand-500">
                    <span>Güncel kullanıcı sözleşmesini ve KVKK aydınlatma metnini okudum, onaylıyorum.</span>
                </label>
                @error('accept') <span class="form-error">{{ $message }}</span> @enderror
                <div class="flex justify-end gap-2 pt-1">
                    {{-- Çıkış formu onay formunun DIŞINDA (iç içe form tarayıcıda yok sayılır, düğme onayı gönderiyordu); düğme form="" ile bağlanır --}}
                    <button type="submit" form="reconsent-logout" class="btn-secondary py-2 text-xs">Çıkış yap</button>
                    <button type="submit" class="btn-primary py-2 text-xs">Onaylıyorum</button>
                </div>
            </form>
            <form id="reconsent-logout" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
        </div>
    @endunless
</div>
