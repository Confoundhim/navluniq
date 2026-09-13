<?php

use App\Models\Invoice;
use App\Services\PaymentService;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.driver')]
#[Title('Premium Abonelik')]
class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $profile = $user->driverProfile;

        return [
            'profile' => $profile,
            'isPremium' => $profile?->isPremium() ?? false,
            'premiumUntil' => $profile?->premium_until,
            'monthlyPrice' => Settings::float('premium_monthly_price'),
            'standardRate' => Settings::float('commission_standard_driver'),
            'premiumRate' => Settings::float('commission_discounted_premium'),
            'paymentReady' => app(PaymentService::class)->isConfigured(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->where('invoice_type', 'subscription')->latest('id')->take(20)->get(),
        ];
    }
}; ?>

<div class="space-y-6">

    <div class="border-b border-neutral-800 pb-4">
        <h2 class="text-xl font-bold text-white tracking-tight">Premium Abonelik</h2>
        <p class="text-xs text-neutral-400 mt-1">Premium üyelik, dış kaynak ilanlara erken erişim ve düşürülmüş komisyon oranı sağlar.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-neutral-900 border {{ $isPremium ? 'border-amber-500/30' : 'border-neutral-800' }} rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Üyelik durumu</h3>
                        <div class="mt-1 text-base font-bold {{ $isPremium ? 'text-amber-300' : 'text-white' }}">
                            {{ $isPremium ? 'Premium aktif' : 'Standart üyelik' }}
                        </div>
                        <div class="text-xs text-neutral-400 mt-0.5">
                            @if($isPremium)
                                {{ $premiumUntil->format('d.m.Y H:i') }} tarihine kadar geçerli.
                            @elseif($premiumUntil)
                                Premium üyeliğiniz {{ $premiumUntil->format('d.m.Y H:i') }} tarihinde sona erdi.
                            @else
                                Daha önce premium üyelik kullanmadınız.
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-black text-white font-mono">{{ number_format($monthlyPrice, 2, ',', '.') }} ₺</div>
                        <div class="text-[11px] text-neutral-500">aylık</div>
                    </div>
                </div>

                @if(! $paymentReady)
                    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs leading-relaxed">
                        Ödeme altyapısı aktivasyon aşamasında; premium satın alma yakında. Altyapı devreye alındığında bu sayfadan abonelik başlatabileceksiniz.
                    </div>
                @else
                    <div class="p-4 rounded-xl bg-neutral-950 border border-neutral-800 text-neutral-300 text-xs leading-relaxed">
                        Premium abonelik satın alma akışı bu sayfaya henüz bağlanmadı. Abonelik için destek ekibiyle iletişime geçebilirsiniz.
                        <a href="{{ route('driver.disputes.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Destek talebi oluştur</a>
                    </div>
                @endif
            </div>

            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Premium avantajları</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="p-4 bg-neutral-950 rounded-xl border border-neutral-800 space-y-1">
                        <div class="text-white font-bold">Düşük komisyon</div>
                        <div class="text-neutral-400">Hakedişlerinizden standart %{{ number_format($standardRate, 1, ',', '.') }} yerine %{{ number_format($premiumRate, 1, ',', '.') }} komisyon kesilir.</div>
                    </div>
                    <div class="p-4 bg-neutral-950 rounded-xl border border-neutral-800 space-y-1">
                        <div class="text-white font-bold">Dış kaynak ilanlara erken erişim</div>
                        <div class="text-neutral-400">İzinli kaynaklardan derlenen ilanlar, standart üyelere açılmadan önce premium üyelere gösterilir; ilan sahibinin telefon numarasının tamamı görünür.</div>
                    </div>
                </div>
                <p class="text-[11px] text-neutral-500">Mevcut komisyon oranınız: %{{ number_format($profile?->commissionRate() ?? $standardRate, 1, ',', '.') }}</p>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Abonelik faturaları</h3>
                @forelse($invoices as $invoice)
                    <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <div class="text-white font-semibold">{{ $invoice->invoice_no ?: 'Numara bekleniyor' }}</div>
                            <div class="text-[10px] text-neutral-500">{{ $invoice->issued_at?->format('d.m.Y H:i') ?? $invoice->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="font-mono text-neutral-300">{{ number_format((float) ($invoice->total_amount ?? 0), 2, ',', '.') }} ₺ · {{ $invoice->status }}</div>
                    </div>
                @empty
                    <div class="text-neutral-500">Henüz abonelik faturanız yok.</div>
                @endforelse
            </div>

            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-2 text-xs text-neutral-400 leading-relaxed">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Nasıl çalışır</h3>
                <p>Premium hakkı yalnız doğrulanmış bir ödeme sonrasında tanımlanır; kart bilgileri NavlunIQ'da saklanmaz.</p>
                <p>Üyelik süresi dolduğunda komisyon oranınız otomatik olarak standart orana döner.</p>
            </div>
        </div>
    </div>
</div>
