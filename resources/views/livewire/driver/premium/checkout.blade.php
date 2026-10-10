<?php

use App\Models\DriverProfile;
use App\Models\PaymentOrder;
use App\Services\KycService;
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

    /** Seçilen süre (ay): Premium sayfasından ?sure=1|3|6|12 ile gelir; geçersizse 1. */
    #[Locked]
    public int $months = 1;

    /** Mesafeli satış sözleşmesi ve "cayma hakkı yok" onayı (MSS 3.1: satın alma ekranında açıkça gösterilir ve onay alınır). */
    public bool $accepted = false;

    /** Otomatik yenileme tercihi: yalnız kuruluşta kart saklama açıkken gösterilir; varsayılan açık (Osman: "bir kere kayıt olsun, devam etsin"). */
    public bool $autoRenew = true;

    #[Locked]
    public bool $autoRenewAvailable = false;

    /**
     * Ödeme kuruluşu fatura için alıcı kimliği ister (bireysel/şahıs: TC, şirket: VKN). Profilde yoksa ödeme kuruluşu
     * "kimlik bilgisi eksik" diye reddediyordu; özet adımında bir kez istenir ve profile yazılır.
     */
    #[Locked]
    public bool $needsIdentity = false;

    #[Locked]
    public bool $identityIsTaxNo = false;

    public string $identityNumber = '';

    public function mount(PaymentService $payments, SubscriptionService $subscriptions, ?int $sure = null): void
    {
        $requested = (int) ($sure ?? request()->query('sure', 1));
        $this->months = in_array($requested, SubscriptionService::PLAN_MONTHS, true) ? $requested : 1;
        $this->configured = $payments->isConfigured();
        $this->sandbox = $payments->isSandbox();
        $this->autoRenewAvailable = $subscriptions->autoRenewAvailable();
        $this->autoRenew = $this->autoRenewAvailable;
        $profile = Auth::user()->driverProfile;
        $this->needsIdentity = $profile !== null && ! $profile->hasPayoutIdentity();
        $this->identityIsTaxNo = $profile?->legal_type === DriverProfile::LEGAL_COMPANY;
    }

    /** Eksik kimliği doğrular ve profile yazar; hata varsa false döner (hata `identityNumber` alanında). */
    private function saveIdentityIfNeeded(): bool
    {
        if (! $this->needsIdentity) {
            return true;
        }
        $profile = Auth::user()->driverProfile;
        $value = preg_replace('/\D+/', '', $this->identityNumber) ?? '';
        $this->resetErrorBag('identityNumber');
        if ($this->identityIsTaxNo) {
            if (! preg_match('/^\d{10}$/', $value)) {
                $this->addError('identityNumber', 'Vergi kimlik numarası 10 haneli olmalıdır.');

                return false;
            }
            $profile->update(['tax_number' => $value]);
        } else {
            if (! KycService::isValidTcNo($value)) {
                $this->addError('identityNumber', 'Geçerli bir T.C. kimlik numarası girin (11 hane).');

                return false;
            }
            $profile->update(['identity_number' => $value]);
        }
        $this->needsIdentity = false;

        return true;
    }

    /**
     * Onay kutusu işaretlenince sipariş açılır ve ödeme kuruluşunun ekranına geçilir. Özet ve uyarılar (süre uzatma,
     * cayma hakkı) bu adımdan önce görünür; doğrudan iyzico'ya atlanmaz.
     */
    public function pay(PaymentService $payments, SubscriptionService $subscriptions): void
    {
        if (! $this->configured) {
            return;
        }
        $this->resetErrorBag('accepted');
        if (! $this->accepted) {
            $this->addError('accepted', 'Devam etmek için mesafeli satış sözleşmesini ve cayma hakkı bilgisini onaylayın.');

            return;
        }
        if (! $this->saveIdentityIfNeeded()) {
            return;
        }
        $this->error = null;

        try {
            $order = $subscriptions->startCheckout(Auth::user(), $this->months, $this->autoRenewAvailable && $this->autoRenew);
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

    /** Hata kutusundaki "Tekrar dene": özet ekranına döner. */
    public function retry(): void
    {
        $this->error = null;
        $this->checkoutType = null;
        $this->checkoutUrl = null;
    }

    /** wire:poll: sunucu bildirimi geldiyse sonuç sayfasına geç. */
    public function checkStatus(): void
    {
        $order = $this->orderId ? PaymentOrder::query()->whereKey($this->orderId)->where('user_id', Auth::id())->first() : null;
        if ($order && $order->status === 'paid') {
            $this->redirect(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']), navigate: true);
        }
    }

    public function with(): array
    {
        $subscriptions = app(SubscriptionService::class);

        $premiumUntil = Auth::user()->driverProfile?->premium_until;
        $active = $premiumUntil && $premiumUntil->isFuture();

        return [
            'price' => $subscriptions->priceFor($this->months),
            'listPrice' => round($subscriptions->monthlyPrice() * $this->months, 2),
            'discount' => $subscriptions->discountFor($this->months),
            'vatRate' => \App\Support\Settings::float('payment_vat_rate'),
            'premiumUntil' => $premiumUntil,
            'premiumActive' => $active,
            'trialEndsAt' => $subscriptions->activeTrialEndsAt(Auth::user()),
            'storedCard' => $this->autoRenewAvailable ? $subscriptions->storedCardFor(Auth::user()) : null,
            // Ödeme sonrası yeni bitiş: aktif süre varsa onun üstüne, yoksa bugünden itibaren
            'newUntil' => ($active ? $premiumUntil->copy() : now())->addMonthsNoOverflow($this->months),
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
            <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Premium şoför üyeliği</span><span class="text-neutral-900 dark:text-white">{{ $months }} ay <a href="{{ route('driver.premium.index') }}" wire:navigate class="ml-2 text-brand-400 font-semibold hover:underline">Değiştir</a></span></div>
            @if($discount > 0)
                <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Liste fiyatı ({{ $months }} × aylık)</span><span class="text-neutral-500 line-through tabular-nums">{{ number_format($listPrice, 2, ',', '.') }} ₺</span></div>
                <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Süre indirimi</span><span class="text-emerald-600 dark:text-emerald-400 font-bold tabular-nums">−%{{ rtrim(rtrim(number_format($discount, 1, ',', '.'), '0'), ',') }} · {{ number_format($listPrice - $price, 2, ',', '.') }} ₺</span></div>
            @endif
            <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Başlangıç</span><span class="text-neutral-900 dark:text-white">{{ $premiumActive ? 'Mevcut sürenin bitiminde ('.$premiumUntil->format('d.m.Y').')' : 'Ödeme onaylandığında' }}</span></div>
            <div class="flex items-center justify-between py-3"><span class="text-neutral-500">Yeni bitiş</span><span class="text-neutral-900 dark:text-white font-semibold">{{ $newUntil->format('d.m.Y') }}</span></div>
            <div class="flex items-center justify-between py-3"><span class="text-neutral-800 dark:text-neutral-200 font-semibold">Ödenecek toplam (KDV %{{ number_format($vatRate, 0) }} dahil)</span><span class="tabular-nums font-bold text-brand-400 text-base">{{ number_format($price, 2, ',', '.') }} ₺</span></div>
        </div>
        <p class="text-2xs text-neutral-500 leading-relaxed">{{ $autoRenewAvailable ? 'Otomatik yenilemeyi aşağıda seçebilirsiniz; seçmezseniz üyelik dönem sonunda biter.' : 'Üyelik otomatik yenilenmez; dönem sonunda hesabınız standart plana döner.' }} Dijital hizmet kullanıma açıldığından dönem içinde iade yapılmaz; ayrıntılar <a href="{{ route('contracts', 'mesafeli-satis') }}" target="_blank" class="text-brand-400 hover:underline">mesafeli satış sözleşmesinde</a>.</p>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800">
        <div class="flex items-center gap-3">
            <img src="/images/payment/iyzico-ile-ode.svg" alt="iyzico ile Öde" class="h-7 w-auto dark:hidden">
            <img src="/images/payment/iyzico-ile-ode-white.svg" alt="iyzico ile Öde" class="h-7 w-auto hidden dark:block">
            <span class="text-2xs text-neutral-500 dark:text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme lisanslı ödeme kuruluşu iyzico'nun güvenli sayfasında 3D Secure ile alınır.</span>
        </div>
        <img src="/images/payment/iyzico-band-colored.svg" alt="Mastercard, Visa, American Express, Troy" class="h-6 w-auto shrink-0 dark:hidden">
        <img src="/images/payment/iyzico-band-white.svg" alt="Mastercard, Visa, American Express, Troy" class="h-6 w-auto shrink-0 hidden dark:block">
    </div>

    @if($premiumActive && ! $checkoutUrl)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 space-y-1.5 text-xs">
            <h3 class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ $trialEndsAt ? 'Ücretsiz deneme süreniz devam ediyor' : 'Premium üyeliğiniz zaten aktif' }}</h3>
            <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed">
                Üyeliğiniz <strong>{{ $premiumUntil->format('d.m.Y H:i') }}</strong> tarihine kadar geçerli. Bu ödeme süreyi kısaltmaz, o tarihin üzerine ekler:
                yeni bitiş <strong>{{ $newUntil->format('d.m.Y') }}</strong>. Şimdi ödemek zorunda değilsiniz; bitişe 3 gün kala hatırlatma gönderilir ve o zaman da uzatabilirsiniz.
            </p>
        </div>
    @endif

    @if(! $configured)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6 space-y-2 text-xs">
            <h3 class="text-sm font-bold text-amber-800 dark:text-amber-200">Ödeme altyapısı aktivasyon aşamasında</h3>
            <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed">Kart ile tahsilat henüz açık değil. Altyapı devreye alındığında bu sayfadan {{ number_format($price, 2, ',', '.') }} ₺ ödeyerek premium'u başlatabileceksiniz.</p>
        </div>
    @elseif($error)
        <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 space-y-3 text-xs">
            <h3 class="text-sm font-bold text-rose-700 dark:text-rose-300">Ödeme başlatılamadı</h3>
            <p class="text-neutral-700 dark:text-neutral-300">{{ $error }}</p>
            <button type="button" wire:click="retry" class="inline-flex px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-white font-semibold">Tekrar dene</button>
        </div>
    @elseif(! $checkoutUrl)
        {{-- Onay adımı: MSS 3.1 gereği cayma hakkının olmadığı gösterilir ve onay alınır; ancak ondan sonra ödeme kuruluşuna geçilir. --}}
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="accepted" class="mt-0.5 rounded">
                <span class="text-neutral-700 dark:text-neutral-300 leading-relaxed">
                    <a href="{{ route('contracts', 'mesafeli-satis') }}" target="_blank" class="text-brand-400 font-semibold hover:underline">Mesafeli satış sözleşmesini</a> okudum. Premium'un dijital bir hizmet olduğunu, ödeme onaylanınca {{ $premiumActive ? 'mevcut sürenin bitiminde uzayacağını' : 'hemen başlayacağını' }} ve bu nedenle cayma hakkımın bulunmadığını, {{ $autoRenewAvailable && $autoRenew ? 'otomatik yenilemeyi seçtiğimi ve her dönem bedelinin çekimden önce bildirileceğini' : 'üyeliğin otomatik yenilenmediğini' }} kabul ediyorum.
                </span>
            </label>
            @error('accepted') <p class="text-rose-500 font-semibold">{{ $message }}</p> @enderror
            @if($needsIdentity)
                <div class="space-y-1.5">
                    <label class="form-label" for="checkout-identity">{{ $identityIsTaxNo ? 'Vergi kimlik numarası' : 'T.C. kimlik numarası' }}</label>
                    <input id="checkout-identity" type="text" inputmode="numeric" autocomplete="off" maxlength="{{ $identityIsTaxNo ? 10 : 11 }}" wire:model="identityNumber" class="form-input tabular-nums max-w-xs" placeholder="{{ $identityIsTaxNo ? '10 hane' : '11 hane' }}">
                    @error('identityNumber') <p class="text-rose-500 font-semibold">{{ $message }}</p> @enderror
                    <p class="text-2xs text-neutral-500 leading-relaxed">Ödeme kuruluşu fatura için ister; şoföre ya da yük sahibine gösterilmez.</p>
                </div>
            @endif
            @if($autoRenewAvailable)
                {{-- Otomatik yenileme: kart ödeme kuruluşunda saklanır (NavlunIQ'da değil); dönem bitiminden 3 gün önce aynı süre güncel fiyattan çekilir. --}}
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border {{ $autoRenew ? 'border-brand-500/40 bg-brand-500/5' : 'border-neutral-200 dark:border-neutral-700' }} p-3">
                    <input type="checkbox" wire:model.live="autoRenew" @checked($autoRenew) class="mt-0.5 rounded">
                    <span class="text-neutral-700 dark:text-neutral-300 leading-relaxed">
                        <span class="font-bold text-neutral-900 dark:text-white">Otomatik yenile</span> · dönem bitiminden 3 gün önce {{ $months }} aylık ücret kayıtlı kartınızdan çekilir, üyelik kesintisiz sürer; bedel çekimden önce bildirilir. İstediğiniz zaman Premium sayfasından tek dokunuşla kapatırsınız.
                        {{ $storedCard ? 'Kayıtlı kartınız: '.$storedCard->label().'. ' : 'Ödeme sayfasında "kartımı sakla" kutusunu işaretleyin; kart NavlunIQ\'da değil iyzico\'da saklanır. ' }}
                    </span>
                </label>
            @endif
            <button type="button" wire:click="pay" wire:loading.attr="disabled" class="btn-primary w-full sm:w-auto text-sm px-6 py-3">
                <span wire:loading.remove wire:target="pay">{{ $premiumActive ? 'Süreyi uzat' : 'Ödemeye geç' }} · {{ number_format($price, 2, ',', '.') }} ₺</span>
                <span wire:loading wire:target="pay">iyzico ödeme sayfası açılıyor…</span>
            </button>
        </div>
    @elseif($checkoutType === 'iframe' && $checkoutUrl)
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl overflow-hidden">
            <div class="p-4 border-b border-neutral-200 dark:border-neutral-800 text-xs text-neutral-500 dark:text-neutral-400">Kart bilgileriniz NavlunIQ sunucularına ulaşmaz; ödeme, lisanslı ödeme kuruluşunun güvenli sayfasında tamamlanır.</div>
            <div class="bg-white" wire:ignore>
                <iframe src="{{ $checkoutUrl }}" id="checkout-frame" frameborder="0" scrolling="no" style="width:100%;min-height:520px"></iframe>
            </div>
        </div>
        <div wire:poll.5s="checkStatus" class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 text-2xs text-neutral-500 dark:text-neutral-400 flex items-center gap-2">
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
