<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Güvenli Ödeme')]
class extends Component {
    public int $loadId = 0;
    public ?Load $load = null;

    public function mount(int $loadId): void
    {
        $user = Auth::user();
        abort_unless($user?->cargoOwnerProfile, 403);

        $this->loadId = $loadId;
        $this->load = Load::query()
            ->whereKey($loadId)
            ->where('cargo_owner_profile_id', $user->cargoOwnerProfile->id)
            ->firstOrFail();
    }
}; ?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <a href="{{ route('cargo-owner.loads.offers', $loadId) }}"
                class="text-xs text-neutral-400 hover:text-brand-400 font-semibold">
                &larr; Tekliflere geri dön
            </a>
            <h2 class="mt-2 text-xl font-bold text-white">Güvenli ödeme</h2>
        </div>
        <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-300">
            Entegrasyon güvenli moda alındı
        </span>
    </div>

    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6">
        <h3 class="text-base font-bold text-amber-200">Gerçek PayTR doğrulaması tamamlanmadan tahsilat alınmıyor</h3>
        <p class="mt-3 text-sm leading-6 text-neutral-300">
            Önceki simülasyon, ödeme alınmadan ilanı ödenmiş gösterdiği ve kart bilgilerini uygulamaya taşıdığı için kapatıldı.
            Bu sayfa, PayTR mağaza yetkileriyle kurulan güvenli ödeme oturumu ve imzalı sunucu bildirimi tamamlandıktan sonra açılacaktır.
        </p>
        <ul class="mt-4 space-y-2 text-xs text-neutral-400">
            <li>• Kart numarası ve CVV NavlunIQ sunucusunda tutulmayacaktır.</li>
            <li>• Ödeme yalnız doğrulanmış PayTR bildirimi sonrasında başarılı sayılacaktır.</li>
            <li>• Aynı bildirim ikinci bir finansal işlem oluşturmayacaktır.</li>
            <li>• Fatura, gerçek ERP/e-belge sonucu gelmeden “kesildi” olarak gösterilmeyecektir.</li>
        </ul>
    </div>

    <div class="rounded-2xl border border-neutral-800 bg-neutral-900 p-5 text-sm text-neutral-300">
        <div class="flex items-center justify-between gap-4">
            <span>İlan</span>
            <strong>#{{ $load?->id }}</strong>
        </div>
        <div class="mt-3 flex items-center justify-between gap-4 border-t border-neutral-800 pt-3">
            <span>Navlun bedeli</span>
            <strong>{{ number_format((float) ($load?->price ?? 0), 2, ',', '.') }} ₺</strong>
        </div>
    </div>
</div>
