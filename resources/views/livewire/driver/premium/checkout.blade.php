<?php

use App\Models\PaymentOrder;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/** Premium üyelik ödeme ekranı: sipariş oluşturur, ödeme kuruluşunun ekranını gömer, durumu izler. */
new
#[Layout('components.layouts.driver')]
#[Title('Premium Ödeme')]
class extends Component {
    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?string $checkoutType = null;

    #[Locked]
    public ?string $checkoutUrl = null;

    #[Locked]
    public ?string $resizerScript = null;

    #[Locked]
    public ?string $error = null;

    #[Locked]
    public bool $configured = false;

    #[Locked]
    public bool $sandbox = true;

    public function mount(PaymentService $payments, SubscriptionService $subscriptions): void
    {
        $this->configured = $payments->isConfigured();
        $this->sandbox = $payments->isSandbox();
        if (! $this->configured) {
            return;
        }

        try {
            $order = $subscriptions->startCheckout(Auth::user());
            $this->orderId = $order->id;
            $cacheKey = 'checkout.'.$order->id;
            $cached = session($cacheKey);
            if (is_array($cached) && ($cached['expires'] ?? 0) > time() && ! empty($cached['url'])) {
                $this->checkoutType = $cached['type'];
                $this->checkoutUrl = $cached['url'];
                $this->resizerScript = $cached['resizer'] ?? null;
            } else {
                $checkout = $payments->checkout($order, request());
                $this->checkoutType = $checkout->type;
                $this->checkoutUrl = $checkout->url;
                $this->resizerScript = $checkout->resizerScript;
                session()->put($cacheKey, ['type' => $checkout->type, 'url' => $checkout->url, 'resizer' => $checkout->resizerScript, 'expires' => time() + $checkout->expiresInSeconds]);
            }
            if ($this->checkoutType === 'redirect') {
                $this->redirect($this->checkoutUrl);
            }
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** wire:poll: sunucu bildirimi geldiyse sonuç sayfasına geç. */
    public function checkStatus(): void
    {
        $order = $this->orderId ? PaymentOrder::query()->find($this->orderId) : null;
        if ($order && $order->status === 'paid') {
            $this->redirect(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']), navigate: true);
        }
    }

    public function with(): array
    {
        return [
            'price' => app(SubscriptionService::class)->monthlyPrice(),
            'vatRate' => \App\Support\Settings::float('payment_vat_rate'),
            'premiumUntil' => Auth::user()->driverProfile?->premium_until,
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <a href="{{ route('driver.premium.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold">&larr; Premium sayfasına dön</a>
            <h2 class="mt-2 text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Premium üyelik ödemesi</h2>
        </div>
        @if($configured && $sandbox)
            <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300">Test (sandbox) modu</span>
        @endif
    </div>

    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
        <h3 class="section-title">Sipariş özeti</h3>
        <div class="divide-y divide-neutral-200 dark:divide-neutral-800 border-t border-neutral-200 dark:border-neutral-800">
            <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Premium şoför üyeliği</span><span class="text-neutral-900 dark:text-white">1 ay</span></div>
            <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Başlangıç</span><span class="text-neutral-900 dark:text-white">{{ $premiumUntil && $premiumUntil->isFuture() ? 'Mevcut sürenin bitiminde ('.$premiumUntil->format('d.m.Y').')' : 'Ödeme onaylandığında' }}</span></div>
            <div class="flex items-center justify-between py-3"><span class="text-neutral-800 dark:text-neutral-200 font-semibold">Ödenecek toplam (KDV %{{ number_format($vatRate, 0) }} dahil)</span><span class="tabular-nums font-bold text-brand-400 text-base">{{ number_format($price, 2, ',', '.') }} ₺</span></div>
        </div>
        <p class="text-[11px] text-neutral-500 leading-relaxed">Üyelik otomatik yenilenmez; dönem sonunda hesabınız standart plana döner. Dijital hizmet satın alındığı anda kullanıma açıldığından dönem içinde iade yapılmaz. Ödeme için <a href="{{ route('contracts', 'mesafeli-satis-sozlesmesi') }}" target="_blank" class="text-brand-400 hover:underline">mesafeli satış sözleşmesini</a> kabul etmiş sayılırsınız.</p>
    </div>

    @if(! $configured)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6 space-y-2 text-xs">
            <h3 class="text-sm font-bold text-amber-800 dark:text-amber-200">Ödeme altyapısı aktivasyon aşamasında</h3>
            <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed">Kart ile tahsilat henüz açık değil. Altyapı devreye alındığında bu sayfadan {{ number_format($price, 2, ',', '.') }} ₺ ödeyerek premium'u başlatabileceksiniz.</p>
        </div>
    @elseif($error)
        <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 space-y-3 text-xs">
            <h3 class="text-sm font-bold text-rose-700 dark:text-rose-300">Ödeme başlatılamadı</h3>
            <p class="text-neutral-700 dark:text-neutral-300">{{ $error }}</p>
            <a href="{{ route('driver.premium.checkout') }}" class="inline-flex px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-white font-semibold">Tekrar dene</a>
        </div>
    @elseif($checkoutType === 'iframe' && $checkoutUrl)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden">
            <div class="p-4 border-b border-neutral-200 dark:border-neutral-800 text-xs text-neutral-500 dark:text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme, lisanslı ödeme kuruluşunun güvenli sayfasında tamamlanır.</div>
            <div class="bg-white" wire:ignore>
                <iframe src="{{ $checkoutUrl }}" id="checkout-frame" frameborder="0" scrolling="no" style="width:100%;min-height:520px"></iframe>
            </div>
        </div>
        <div wire:poll.5s="checkStatus" class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-[11px] text-neutral-500 dark:text-neutral-400 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
            <span>Ödeme durumu izleniyor. Ödeme tamamlandığında sonuç sayfasına yönlendirileceksiniz.</span>
        </div>
        @if($resizerScript)
            @assets
            <script src="{{ $resizerScript }}"></script>
            @endassets
            @script
            <script>
                const startResizer = () => {
                    if (window.iFrameResize && document.getElementById('checkout-frame')) { iFrameResize({}, '#checkout-frame'); } else { setTimeout(startResizer, 250); }
                };
                startResizer();
            </script>
            @endscript
        @endif
    @endif
</div>
