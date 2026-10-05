<?php

use App\Models\PaymentOrder;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Ödeme sonuç sayfası. Ödeme kuruluşu kullanıcıyı buraya döndürür; nihai durum yalnız sunucu
 * bildirimiyle belirlenir, bu sayfa sipariş durumunu birkaç saniyede bir sorgular.
 */
new
#[Layout('components.layouts.frontend')]
#[Title('Ödeme Sonucu')]
class extends Component {
    #[Locked]
    public string $orderPublicId = '';

    #[Locked]
    public string $outcome = 'basarili';

    #[Locked]
    public int $polls = 0;

    public function mount(string $order, string $outcome): void
    {
        $this->orderPublicId = $order;
        $this->outcome = $outcome;
        abort_unless($this->order() !== null, 404);
    }

    private function order(): ?PaymentOrder
    {
        return PaymentOrder::query()->where('public_id', $this->orderPublicId)->where('user_id', (int) Auth::id())->first();
    }

    public function refresh(): void
    {
        $this->polls++;
    }

    public function with(): array
    {
        $order = $this->order();
        $isSubscription = $order?->purpose === PaymentService::PURPOSE_SUBSCRIPTION;
        // 'indeterminate': para çekilmiş olabilir (iade bekleyen/edilmiş, süresi dolmuş ya da tutar uyuşmazlığı); "çekim yapılmadı" denmez.
        $state = match (true) {
            $order?->status === 'paid' => 'paid',
            in_array($order?->status, ['refund_pending', 'refunded', 'expired'], true) || ($order?->status === 'failed' && $order?->refunded_at) => 'indeterminate',
            $order?->status === 'failed' => 'failed',
            $this->outcome === 'basarisiz' => 'failed_hint',
            default => 'pending',
        };

        return [
            'order' => $order,
            'state' => $state,
            'isSubscription' => $isSubscription,
            'nextUrl' => $isSubscription
                ? route('driver.premium.index')
                : ($order?->load_id ? route('cargo-owner.shipments.show', $order->load_id) : route('cargo-owner.dashboard')),
            'retryUrl' => $isSubscription
                ? route('driver.premium.checkout')
                : ($order?->load_id ? route('cargo-owner.finance.payment', $order->load_id) : route('cargo-owner.dashboard')),
            'waitedTooLong' => $this->polls > 40,
        ];
    }
}; ?>

<div class="max-w-2xl mx-auto px-6 py-16 md:py-24" @if($state === 'pending') wire:poll.3s="refresh" @endif>
    <div class="apple-glass rounded-3xl p-8 md:p-10 text-center space-y-6 shadow-apple-sm">
        @if($state === 'paid')
            <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div class="space-y-2">
                <h1 class="text-2xl font-black text-neutral-950 dark:text-white">Ödemeniz alındı</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    @if($isSubscription)
                        Premium üyeliğiniz etkinleştirildi. Onaylı dış kaynak ilanları artık panelinizde; yeni sistem ilanlarını da herkesten önce görüyorsunuz.
                    @else
                        Navlun ödemesi lisanslı ödeme kuruluşu tarafından doğrulandı. Şoför artık yola çıkabilir; ödeme, teslimatı onayladığınızda tamamlanır.
                    @endif
                </p>
            </div>
        @elseif($state === 'pending')
            <div class="w-16 h-16 mx-auto rounded-full bg-brand-500/10 text-brand-500 flex items-center justify-center">
                <span class="w-8 h-8 rounded-full border-4 border-brand-500/30 border-t-brand-500 animate-spin"></span>
            </div>
            <div class="space-y-2">
                <h1 class="text-2xl font-black text-neutral-950 dark:text-white">Ödemeniz doğrulanıyor</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Ödeme kuruluşunun onayı bekleniyor; bu genellikle birkaç saniye sürer. Sayfayı kapatmanıza gerek yok.</p>
                @if($waitedTooLong)
                    <p class="text-xs text-amber-600 dark:text-amber-400">Onay beklenenden uzun sürdü. Kartınızdan çekim yapıldıysa ödeme birkaç dakika içinde yansır; yansımazsa destek ekibine sipariş numaranızla ulaşın.</p>
                @endif
            </div>
        @elseif($state === 'indeterminate')
            <div class="w-16 h-16 mx-auto rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
            </div>
            <div class="space-y-2">
                <h1 class="text-2xl font-black text-neutral-950 dark:text-white">Ödeme doğrulanamadı</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Ödeme doğrulanamadı; kartınızdan çekim olduysa tutar iade edilir (bankanıza göre 1-10 iş günü). Durumu bildirimlerinizden takip edebilir, ödemeyi yeniden deneyebilirsiniz.</p>
            </div>
        @else
            <div class="w-16 h-16 mx-auto rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <div class="space-y-2">
                <h1 class="text-2xl font-black text-neutral-950 dark:text-white">Ödeme tamamlanamadı</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Ödeme kuruluşu işlemi onaylamadı. Farklı bir kartla tekrar deneyebilir ya da bankanızla görüşebilirsiniz; bir çekim görürseniz kendiliğinden iade edilir.</p>
            </div>
        @endif

        @if($order)
            <div class="mx-auto max-w-sm text-left text-xs divide-y divide-neutral-200 dark:divide-neutral-800 border-y border-neutral-200 dark:border-neutral-800">
                <div class="flex justify-between py-2.5"><span class="text-neutral-500">Sipariş no</span><span class="font-mono font-semibold text-neutral-900 dark:text-white">{{ $order->merchant_oid }}</span></div>
                <div class="flex justify-between py-2.5"><span class="text-neutral-500">Tutar</span><span class="font-bold tabular-nums text-neutral-900 dark:text-white">{{ number_format((float) $order->amount, 2, ',', '.') }} ₺</span></div>
                <div class="flex justify-between py-2.5"><span class="text-neutral-500">Konu</span><span class="text-neutral-900 dark:text-white">{{ $isSubscription ? 'Premium şoför üyeliği (1 ay)' : 'Navlun bedeli #'.$order->load_id }}</span></div>
                <div class="flex justify-between py-2.5"><span class="text-neutral-500">Durum</span><span class="text-neutral-900 dark:text-white">{{ ['created' => 'Ödeme bekleniyor', 'pending' => 'Ödeme bekleniyor', 'paid' => 'Ödendi', 'failed' => $order->refunded_at ? 'Kabul edilmedi, iade edildi' : 'Başarısız', 'refund_pending' => 'İade bekliyor', 'refunded' => 'İade edildi', 'expired' => 'Süresi doldu', 'cancelled' => 'İptal edildi'][$order->status] ?? $order->status }}</span></div>
                @if($order->paid_at)<div class="flex justify-between py-2.5"><span class="text-neutral-500">Ödeme zamanı</span><span class="text-neutral-900 dark:text-white">{{ $order->paid_at->format('d.m.Y H:i') }}</span></div>@endif
            </div>
        @endif

        <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
            @if($state === 'paid')
                <a href="{{ $nextUrl }}" class="btn-primary text-sm px-6 py-3">{{ $isSubscription ? 'Premium sayfasına git' : 'Sevkiyatı takip et' }}</a>
            @elseif($state === 'pending')
                <a href="{{ $nextUrl }}" class="btn-secondary text-sm px-6 py-3">Panele dön</a>
            @else
                <a href="{{ $retryUrl }}" class="btn-primary text-sm px-6 py-3">Tekrar dene</a>
                <a href="{{ $nextUrl }}" class="btn-secondary text-sm px-6 py-3">Panele dön</a>
            @endif
        </div>
        <p class="text-[11px] text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme lisanslı ödeme kuruluşunun güvenli sayfasında alınır.</p>
    </div>
</div>
