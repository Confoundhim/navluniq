<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

new
#[Layout('components.layouts.driver')]
#[Title('Premium Sürücü Aboneliği')]
class extends Component {
    public bool $isPremiumActive = false;
    public int $daysRemaining = 0;
    public ?string $premiumUntil = null;

    public function mount(): void
    {
        $profile = Auth::user()?->driverProfile;
        if (!$profile?->premium_until) {
            return;
        }

        $until = Carbon::parse($profile->premium_until);
        $this->premiumUntil = $until->format('d.m.Y');
        $this->daysRemaining = max(0, (int) Carbon::now()->diffInDays($until, false));
        $this->isPremiumActive = $until->isFuture();
    }

    public function with(): array
    {
        $userId = Auth::id();

        return [
            'invoices' => $userId
                ? Invoice::query()->where('user_id', $userId)->where('invoice_type', 'subscription')->latest()->get()
                : collect(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="relative overflow-hidden rounded-2xl border border-amber-500/30 bg-gradient-to-r from-neutral-900 to-amber-950/40 p-6 md:p-8">
        <div class="relative z-10">
            <span class="inline-flex rounded-full border border-amber-500/20 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300">
                NavlunIQ Premium
            </span>
            <h2 class="mt-4 text-2xl font-extrabold text-white">
                @if($isPremiumActive)
                    Mevcut üyeliğiniz aktif — {{ $daysRemaining }} gün kaldı
                @else
                    Güvenli abonelik entegrasyonu hazırlanıyor
                @endif
            </h2>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-neutral-300">
                Tahsilat yapılmadan Premium hakkı tanıyan eski simülasyon kapatıldı. Satın alma ve otomatik yenileme,
                PayTR kart saklama/tekrarlayan ödeme yetkileri ve imzalı ödeme sonuçları doğrulandıktan sonra açılacaktır.
            </p>
        </div>
    </div>

    <div class="rounded-2xl border border-neutral-800 bg-neutral-900 p-6">
        <h3 class="text-sm font-bold text-white">Güvenli abonelik kuralları</h3>
        <ul class="mt-4 space-y-2 text-xs leading-5 text-neutral-400">
            <li>• Kart bilgileri NavlunIQ veritabanında tutulmayacak.</li>
            <li>• Premium hakkı yalnız doğrulanmış başarılı tahsilatla başlayacak.</li>
            <li>• Aynı dönem için mükerrer tahsilat ve mükerrer süre uzatma engellenecek.</li>
            <li>• İptal, gelecek yenilemeyi durduracak; ödenmiş dönem hakkı ayrı korunacak.</li>
            <li>• ERP/e-belge sonucu doğrulanmadan fatura “kesildi” sayılmayacak.</li>
        </ul>
    </div>

    @if($invoices->isNotEmpty())
        <div class="rounded-2xl border border-neutral-800 bg-neutral-900 p-6">
            <h3 class="text-sm font-bold text-white">Geçmiş abonelik kayıtları</h3>
            <p class="mt-2 text-xs text-amber-300">
                Eski kayıtların gerçek ödeme ve e-belge durumları ayrıca mutabakatla doğrulanmalıdır.
            </p>
            <div class="mt-4 space-y-2">
                @foreach($invoices as $invoice)
                    <div class="flex items-center justify-between rounded-xl border border-neutral-800 bg-neutral-950 p-3 text-xs text-neutral-300">
                        <span>{{ $invoice->invoice_no }}</span>
                        <span>{{ number_format((float) $invoice->total_amount, 2, ',', '.') }} ₺ — {{ $invoice->status }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
