<?php

use App\Models\Load;
use App\Payments\GatewayManager;
use App\Payments\Gateways\IyzicoGateway;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Güvenli Ödeme')]
class extends Component {
    #[Locked]
    public int $loadId = 0;

    #[Locked]
    public ?string $checkoutType = null;

    #[Locked]
    public ?string $checkoutUrl = null;

    #[Locked]
    public ?string $resizerScript = null;

    #[Locked]
    public ?string $tokenError = null;

    #[Locked]
    public bool $configured = false;

    #[Locked]
    public bool $sandbox = true;

    /** iyzico pazaryeri alıcı sözleşmesi onayı (bir kez; sonraki ödemelerde sorulmaz). */
    public bool $buyer_terms = false;

    public function mount(int $loadId, PaymentService $payments): void
    {
        $this->loadId = $loadId;
        $load = $this->ownerLoad();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı veya size ait değil.');
            $this->redirect(route('cargo-owner.loads.index'), navigate: true);

            return;
        }

        $this->configured = $payments->isConfigured();
        $this->sandbox = $payments->isSandbox();

        if ($this->configured && $this->isPayable($load) && ! $this->needsBuyerAgreement()) {
            $this->startCheckout($load, $payments);
        }
    }

    /** Alıcı sözleşmesi onaylanınca kaydedilir ve ödeme formu açılır. */
    public function acceptAndPay(PaymentService $payments): void
    {
        $this->resetErrorBag('buyer_terms');
        if (! $this->buyer_terms) {
            $this->addError('buyer_terms', 'Ödemeye geçmek için iyzico alıcı sözleşmesini onaylayın.');

            return;
        }
        $user = Auth::user();
        if (! $user->iyzico_buyer_agreed_at) {
            $user->forceFill(['iyzico_buyer_agreed_at' => now()])->save();
        }
        $load = $this->ownerLoad();
        if ($load && $this->configured && $this->isPayable($load)) {
            $this->startCheckout($load, $payments);
        }
    }

    /** Etkin ödeme kuruluşu iyzico ise alıcı, pazaryeri alıcı sözleşmesini bir kez dijital olarak onaylar (iyzico kuralı). */
    private function needsBuyerAgreement(): bool
    {
        return app(GatewayManager::class)->active()->id() === 'iyzico' && ! Auth::user()->iyzico_buyer_agreed_at;
    }

    private function startCheckout(Load $load, PaymentService $payments): void
    {
        try {
            $order = $payments->orderFor($load, Auth::user());
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
            $this->tokenError = $e->getMessage();
        }
    }

    private function ownerLoad(): ?Load
    {
        return Load::query()
            ->whereKey($this->loadId)
            ->where('cargo_owner_profile_id', (int) Auth::user()->cargoOwnerProfile?->id)
            ->first();
    }

    private function isPayable(Load $load): bool
    {
        return $load->status === Load::STATUS_ASSIGNED && $load->escrow_status === Load::ESCROW_PENDING;
    }

    /** wire:poll ile çağrılır; ödeme sunucu bildirimiyle işlendiğinde sevkiyat sayfasına yönlendirir. */
    public function checkStatus(): void
    {
        $load = $this->ownerLoad();

        if ($load && $load->escrow_status === Load::ESCROW_PAID) {
            session()->flash('success_message', 'Ödemeniz alındı. Navlun ödemesi teslimat onayınızla şoföre tamamlanacak.');
            $this->redirect(route('cargo-owner.shipments.show', $load->id), navigate: true);
        }
    }

    public function with(): array
    {
        $load = $this->ownerLoad();
        $payments = app(PaymentService::class);

        // Doğrudan kip: ilan doğrudan ödemeli ya da (henüz platform akışı başlamamışken) genel ayar doğrudan ise ödeme ekranı yerine açıklama.
        // publish() havuzu hep 'pending_payment' yazar; teklif kabul edilmemiş ilanda da platform akışı başlamış sayılmaz.
        $directLoad = $load && ($load->isDirectPayment() || (\App\Support\FreightPayment::direct() && ! $load->isPaid()
            && ! ($load->status === \App\Models\Load::STATUS_ASSIGNED && $load->escrow_status === \App\Models\Load::ESCROW_PENDING)));

        return [
            'load' => $load,
            'directLoad' => $directLoad,
            'payable' => $load ? $this->isPayable($load) : false,
            'amounts' => $load ? $payments->calculateAmounts($load) : ['price' => 0.0, 'service_fee' => 0.0, 'total' => 0.0],
            'iframeUrl' => $this->checkoutType === 'iframe' ? $this->checkoutUrl : null,
            'needsBuyerAgreement' => $load && $this->isPayable($load) && $this->configured && $this->needsBuyerAgreement() && ! $this->checkoutUrl,
            'buyerAgreementUrl' => IyzicoGateway::BUYER_AGREEMENT_URL,
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto space-y-6">

    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-semibold">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold">
                &larr; İlanlarıma dön
            </a>
            <h2 class="mt-2 text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
                <span>{{ ($load ?? null) && $directLoad ? 'Navlun ödemesi' : 'Güvenli ödeme' }}</span>
                <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 tabular-nums text-xs font-bold border border-brand-500/20">#{{ $loadId }}</span>
            </h2>
        </div>
        @if($configured && $sandbox)
            <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:text-amber-300">Test (sandbox) modu</span>
        @endif
    </div>

    @if($load && $directLoad)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
            <div class="text-sm font-bold text-neutral-900 dark:text-white break-words">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $load->statusLabel() }}</span>
                @if($load->price)
                    <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold tabular-nums">{{ number_format((float) $load->price, 2, ',', '.') }} ₺</span>
                @endif
            </div>
            <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-2 leading-relaxed">
                <p class="text-neutral-900 dark:text-white font-semibold">Bu ilanda navlun şoförle doğrudan ödenir; NavlunIQ tahsilat yapmaz.</p>
                <p class="text-neutral-600 dark:text-neutral-400">Ödeme adımı yoktur: teklif kabul edilince şoför yükleme bilgilerini görür ve yola çıkar. Navlun bedelini şoförle aranızda anlaştığınız şekilde ödersiniz; NavlunIQ para tutmaz, komisyon almaz ve ödemeye taraf olmaz.</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 pt-1">
                @if($load->status === 'active_seeking')
                    <a href="{{ route('cargo-owner.loads.offers', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-center">Teklifleri gör</a>
                @elseif($load->shipment || in_array($load->status, ['driver_assigned', 'on_the_way', 'delivered', 'disputed', 'completed'], true))
                    <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-center">Sevkiyatı görüntüle</a>
                @endif
            </div>
        </div>
    @elseif($load)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
            <h3 class="section-title">Ödeme özeti</h3>
            <div class="text-sm font-bold text-neutral-900 dark:text-white break-words">{{ $load->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $load->delivery_location }}</div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $load->statusLabel() }}</span>
                <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 text-[11px] font-bold">{{ $load->escrowLabel() }}</span>
            </div>
            <div class="divide-y divide-neutral-200 dark:divide-neutral-800 border-t border-neutral-200 dark:border-neutral-800">
                <div class="flex items-center justify-between py-3">
                    <span class="text-neutral-500 dark:text-neutral-400">Navlun bedeli</span>
                    <span class="tabular-nums text-neutral-900 dark:text-white">{{ number_format($amounts['price'], 2, ',', '.') }} ₺</span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-neutral-500 dark:text-neutral-400">Hizmet bedeli</span>
                    <span class="tabular-nums text-neutral-900 dark:text-white">{{ number_format($amounts['service_fee'], 2, ',', '.') }} ₺</span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-neutral-800 dark:text-neutral-200 font-semibold">Ödenecek toplam</span>
                    <span class="tabular-nums font-bold text-brand-400 text-base">{{ number_format($amounts['total'], 2, ',', '.') }} ₺</span>
                </div>
            </div>
            @if($payable && $load->payment_due_at)
                <p class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-800 dark:text-amber-200 leading-relaxed"><span class="font-bold whitespace-nowrap">Son ödeme: {{ $load->payment_due_at->format('d.m H:i') }}</span> — süre dolarsa ilan yeniden havuza döner.</p>
            @endif
            <p class="text-[11px] text-neutral-500 leading-relaxed">Navlun ödemesi lisanslı ödeme kuruluşu üzerinden yapılır ve teslimat onayınızla şoföre tamamlanır. Ödeme yalnız ödeme kuruluşunun sunucu bildirimi doğrulandığında alınmış sayılır.</p>
        </div>

        @if($payable && $configured)
            {{-- Y8: iyzico bandı yalnız ödeme alınabilir durumda; ödeme kapalıyken ya da ilan başka aşamadayken görünmez. --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800">
                <div class="flex items-center gap-3">
                    <img src="{{ asset_v('/images/payment/iyzico-ile-ode.svg') }}" alt="iyzico ile Öde" class="h-7 w-auto dark:hidden">
                    <img src="{{ asset_v('/images/payment/iyzico-ile-ode-white.svg') }}" alt="iyzico ile Öde" class="h-7 w-auto hidden dark:block">
                    <span class="text-[11px] text-neutral-500 dark:text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme lisanslı ödeme kuruluşu iyzico'nun güvenli sayfasında 3D Secure ile alınır.</span>
                </div>
                <img src="{{ asset_v('/images/payment/iyzico-band-colored.svg') }}" alt="Mastercard, Visa, American Express, Troy" class="h-6 w-auto shrink-0 dark:hidden">
                <img src="{{ asset_v('/images/payment/iyzico-band-white.svg') }}" alt="Mastercard, Visa, American Express, Troy" class="h-6 w-auto shrink-0 hidden dark:block">
            </div>
        @endif
        @if(! $payable)
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Bu ilan ödeme adımında değil</h3>
                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">
                    İlan durumu: <strong class="text-neutral-900 dark:text-white">{{ $load->statusLabel() }}</strong> · Ödeme durumu: <strong class="text-neutral-900 dark:text-white">{{ $load->escrowLabel() }}</strong>.
                    @if($load->status === 'active_seeking')
                        Ödeme, bir teklifi kabul ettikten sonra yapılır.
                    @elseif($load->isPaid())
                        Ödemeniz alınmış durumda.
                    @endif
                </p>
                <div class="flex flex-col sm:flex-row gap-2 pt-1">
                    @if($load->status === 'active_seeking')
                        <a href="{{ route('cargo-owner.loads.offers', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-center">Teklifleri gör</a>
                    @elseif(in_array($load->status, ['driver_assigned', 'on_the_way', 'delivered', 'disputed', 'completed'], true))
                        <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold text-center">Sevkiyatı görüntüle</a>
                    @endif
                </div>
            </div>
        @elseif(! $configured)
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6 space-y-3 text-xs">
                <h3 class="text-sm font-bold text-amber-800 dark:text-amber-200">Ödeme altyapısı aktivasyon aşamasında</h3>
                <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed">
                    Ödeme sağlayıcısı henüz bu ortam için etkinleştirilmedi; bu nedenle şu anda kart ile tahsilat yapılamıyor.
                    Ödeme altyapısı açıldığında bu sayfadan {{ number_format($amounts['total'], 2, ',', '.') }} ₺ tutarındaki navlun bedelini ödeyebileceksiniz.
                </p>
                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">Şoför, navlun ödemesi yapılmadan sevkiyatı başlatamaz. Sorularınız için <a href="{{ route('cargo-owner.support.index') }}" wire:navigate class="text-brand-400 hover:underline">destek bileti</a> açabilirsiniz.</p>
            </div>
        @elseif($tokenError)
            <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 space-y-3 text-xs">
                <h3 class="text-sm font-bold text-rose-700 dark:text-rose-300">Ödeme sayfası açılamadı</h3>
                <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed">{{ $tokenError }}</p>
                <a href="{{ route('cargo-owner.finance.payment', $load->id) }}" class="inline-flex px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-900 dark:text-white font-semibold">Tekrar dene</a>
            </div>
        @elseif($needsBuyerAgreement)
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Ödemeden önce tek seferlik onay</h3>
                <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">Navlun bedeli, teslimatı onaylayana kadar lisanslı ödeme kuruluşu iyzico'nun korumalı hesabında bekler. iyzico bunun için alıcının platform sözleşmesini bir kez onaylamasını ister; sonraki ödemelerde tekrar sorulmaz.</p>
                <label class="flex items-start gap-3 p-3 rounded-xl border {{ $errors->has('buyer_terms') ? 'border-rose-400 bg-rose-500/5' : 'border-neutral-200 dark:border-neutral-700' }} cursor-pointer">
                    <input type="checkbox" wire:model="buyer_terms" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-brand-500 focus:ring-brand-500">
                    <span class="text-neutral-700 dark:text-neutral-200 leading-relaxed">
                        <a href="{{ $buyerAgreementUrl }}" target="_blank" rel="noopener" class="text-brand-400 font-semibold hover:underline">iyzico Pazaryeri Alıcı Sözleşmesi</a>'ni okudum, kabul ediyorum.
                    </span>
                </label>
                @error('buyer_terms') <span class="form-error">{{ $message }}</span> @enderror
                <button type="button" wire:click="acceptAndPay" wire:loading.attr="disabled" class="w-full sm:w-auto px-5 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold">
                    <span wire:loading.remove wire:target="acceptAndPay">Ödemeye geç · {{ number_format($amounts['total'], 2, ',', '.') }} ₺</span>
                    <span wire:loading wire:target="acceptAndPay">Ödeme sayfası açılıyor...</span>
                </button>
            </div>
        @elseif($iframeUrl)
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-neutral-200 dark:border-neutral-800 text-xs text-neutral-500 dark:text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme, sağlayıcının güvenli sayfasında tamamlanır.</div>
                <div class="bg-white" wire:ignore>
                    <iframe src="{{ $iframeUrl }}" id="checkout-frame" frameborder="0" scrolling="no" style="width:100%;min-height:520px"></iframe>
                </div>
            </div>

            <div wire:poll.5s="checkStatus" class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-[11px] text-neutral-500 dark:text-neutral-400 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                <span>Ödeme durumu izleniyor: <span class="text-neutral-800 dark:text-neutral-200">{{ $load->escrowLabel() }}</span>. Ödeme tamamlandığında sevkiyat sayfasına yönlendirileceksiniz.</span>
            </div>

            @if($resizerScript)
            @assets
            <script src="{{ $resizerScript }}"></script>
            @endassets

            @script
            <script>
                const startResizer = () => {
                    if (window.iFrameResize && document.getElementById('checkout-frame')) {
                        iFrameResize({}, '#checkout-frame');
                    } else {
                        setTimeout(startResizer, 250);
                    }
                };
                startResizer();
            </script>
            @endscript
            @endif
        @endif
    @else
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center text-xs text-neutral-500 dark:text-neutral-400">İlan bulunamadı.</div>
    @endif

</div>
