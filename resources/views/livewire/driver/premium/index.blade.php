<?php

use App\Models\Invoice;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.driver')]
#[Title('Premium Abonelik')]
class extends Component {
    /** Yeni ilan e-postasını aç/kapat (kontrol şoförde; uygulama içi bildirim her zaman devam eder). */
    public function toggleLoadMail(): void
    {
        $profile = Auth::user()->driverProfile;
        if (! $profile) {
            return;
        }
        $prefs = (array) ($profile->preferences ?? []);
        $prefs['notify_new_loads'] = ! \App\Services\LoadReleaseService::wantsLoadMail($profile);
        $profile->update(['preferences' => $prefs]);
        session()->flash('success_message', $prefs['notify_new_loads'] ? 'Yeni ilan e-postaları açıldı.' : 'Yeni ilan e-postaları kapatıldı; uygulama içi bildirimler devam eder.');
    }

    /** Otomatik yenilemeyi tek dokunuşla aç/kapat (kapatma dönem sonuna kadar hakları etkilemez). */
    public function setAutoRenew(SubscriptionService $subscriptions, bool $on): void
    {
        $user = Auth::user();
        if ($user->driverProfile?->is_staff_view) {
            session()->flash('error_message', 'Yönetici görünümünde işlem yapılamaz.');

            return;
        }
        if ($subscriptions->setAutoRenew($user, $on)) {
            session()->flash('success_message', $on ? 'Otomatik yenileme açıldı; dönem bitiminden 3 gün önce kayıtlı kartınızdan çekilir.' : 'Otomatik yenileme kapatıldı; üyeliğiniz dönem sonuna kadar sürer.');
        } else {
            session()->flash('error_message', $on ? 'Otomatik yenileme açılamadı: kayıtlı kartınız yok. Yeni bir ödeme yapıp kartınızı kaydedin.' : 'Sürmekte olan ücretli üyelik bulunamadı.');
        }
    }

    /** Kayıtlı kartı sil (kuruluştan ve NavlunIQ'dan); otomatik yenileme kapanır. */
    public function deleteCard(SubscriptionService $subscriptions, int $cardId): void
    {
        $user = Auth::user();
        if ($user->driverProfile?->is_staff_view) {
            session()->flash('error_message', 'Yönetici görünümünde işlem yapılamaz.');

            return;
        }
        $card = \App\Models\StoredCard::query()->whereKey($cardId)->where('user_id', $user->id)->first();
        if (! $card) {
            return;
        }
        try {
            $subscriptions->deleteStoredCard($user, $card);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }
        session()->flash('success_message', 'Kayıtlı kart silindi; otomatik yenileme kapatıldı.');
    }

    /** Ücretsiz denemeyi başlat (bir kez; belgeleri onaylı şoför). */
    public function startTrial(SubscriptionService $subscriptions): void
    {
        $user = Auth::user();
        if ($user->driverProfile?->is_staff_view) {
            session()->flash('error_message', 'Yönetici görünümünde işlem yapılamaz.');

            return;
        }
        $subscription = $subscriptions->startTrial($user);
        if ($subscription) {
            session()->flash('success_message', $subscriptions->trialDays().' günlük ücretsiz premium deneme süreniz başladı.');
            $this->redirect(route('driver.loads.index', ['tab' => 'external']), navigate: true);

            return;
        }
        session()->flash('error_message', 'Deneme süresi başlatılamadı: belgeleriniz onaylı değil ya da deneme hakkınızı daha önce kullandınız.');
    }

    public function with(): array
    {
        $user = Auth::user();
        $profile = $user->driverProfile;
        $subscriptions = app(SubscriptionService::class);

        $paidSubscription = $subscriptions->activePaidSubscription($user);

        return [
            'profile' => $profile,
            'autoRenewAvailable' => $subscriptions->autoRenewAvailable(),
            'paidSubscription' => $paidSubscription,
            'willAutoRenew' => $paidSubscription ? $subscriptions->willAutoRenew($paidSubscription) : false,
            'storedCard' => $subscriptions->storedCardFor($user),
            'isPremium' => $profile?->isPremium() ?? false,
            'premiumUntil' => $profile?->premium_until,
            'trialDays' => $subscriptions->trialDays(),
            'trialEligible' => $subscriptions->trialEligible($profile),
            'trialEndsAt' => $subscriptions->activeTrialEndsAt($user),
            'loadMail' => $profile ? \App\Services\LoadReleaseService::wantsLoadMail($profile) : true,
            'monthlyPrice' => Settings::float('premium_monthly_price'),
            'plans' => $subscriptions->plans(),
            'standardRate' => Settings::float('commission_standard_driver'),
            'paymentReady' => app(PaymentService::class)->isConfigured(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->where('invoice_type', 'subscription')->latest('id')->take(20)->get(),
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    @php
        $lead = app(\App\Services\LoadReleaseService::class)->delayMinutes();
        $leadText = $lead > 0 ? $lead.' dakika' : 'aynı anda';
    @endphp
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Premium Abonelik</h2>
        <p class="page-subtitle">Premium üyeler gruplardan derlenen ilanları ilan bilgileriyle görür, yeni NavlunIQ ilanlarını herkesten {{ $leadText }} önce görür ve anında bildirim alır; standart üyeye bildirim gitmez, grup ilanları görünmez.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border {{ $isPremium ? 'border-amber-500/30' : 'border-neutral-200 dark:border-neutral-800' }} rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="section-title">Üyelik durumu</h3>
                        <div class="mt-1 text-base font-bold {{ $isPremium ? 'text-amber-700 dark:text-amber-300' : 'text-neutral-900 dark:text-white' }}">
                            {{ $isPremium ? ($trialEndsAt ? 'Premium aktif · ücretsiz deneme' : 'Premium aktif') : 'Standart üyelik' }}
                        </div>
                        <div class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                            @if($trialEndsAt)
                                Deneme süreniz {{ $trialEndsAt->format('d.m.Y H:i') }} tarihine kadar; ücret alınmaz, sonra isterseniz 1, 3, 6 ya da 12 aylık devam edersiniz.
                            @elseif($isPremium)
                                {{ $premiumUntil->format('d.m.Y H:i') }} tarihine kadar geçerli.
                            @elseif($premiumUntil)
                                Premium üyeliğiniz {{ $premiumUntil->format('d.m.Y H:i') }} tarihinde sona erdi.
                            @else
                                Daha önce premium üyelik kullanmadınız.
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format($monthlyPrice, 2, ',', '.') }} ₺</div>
                        <div class="text-2xs text-neutral-500">aylık</div>
                    </div>
                </div>

                @if($trialEligible)
                    <div class="p-4 rounded-xl bg-brand-500/10 border border-brand-500/20 space-y-3">
                        <div class="text-sm font-bold text-neutral-900 dark:text-white">{{ $trialDays }} gün ücretsiz deneyin</div>
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 leading-relaxed">Premium'un tamamı {{ $trialDays }} gün boyunca ücretsiz: gruplardan derlenen ilanlar ilan bilgileriyle, yeni ilanlar herkesten önce ve bildirimle, dönüş yükü radarı. Kart bilgisi istenmez; süre bitince ücret alınmaz, hesabınız kendiliğinden standart üyeliğe döner.</p>
                        <button type="button" wire:click="startTrial" wire:loading.attr="disabled" class="btn-primary text-sm px-6 py-3">
                            <span wire:loading.remove wire:target="startTrial">{{ $trialDays }} günlük denemeyi başlat</span>
                            <span wire:loading wire:target="startTrial">Başlatılıyor…</span>
                        </button>
                    </div>
                @elseif($trialDays > 0 && ! ($profile?->isKycApproved() ?? false) && ! $isPremium && ! $profile?->trial_started_at)
                    <div class="p-4 rounded-xl bg-brand-500/10 border border-brand-500/20 text-xs text-neutral-700 dark:text-neutral-300 leading-relaxed">
                        Belgeleriniz onaylandığında {{ $trialDays }} günlük ücretsiz premium deneme kendiliğinden başlar; kart gerekmez.
                    </div>
                @endif
                @if(! $paymentReady)
                    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-xs leading-relaxed">
                        Ödeme altyapısı aktivasyon aşamasında; premium satın alma yakında. Altyapı devreye alındığında bu sayfadan abonelik başlatabileceksiniz.
                    </div>
                @elseif(! ($profile?->isKycApproved() ?? false))
                    <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 text-neutral-700 dark:text-neutral-300 text-xs leading-relaxed">
                        Premium üyelik için önce belgelerinizin onaylanması gerekir. <a href="{{ route('driver.profile.index') }}" wire:navigate class="text-brand-400 font-bold hover:underline">Belgelerime git</a>
                    </div>
                @else
                    {{-- Süre seçimi: 1 ay tam fiyat, 3/6/12 ay panel ayarlı indirimle (SubscriptionService::plans). Ödeme sayfasına ?sure=N ile gider. --}}
                    <div class="space-y-3">
                        <div class="text-xs font-bold text-neutral-900 dark:text-white">{{ $trialEndsAt ? 'Denemeden sonra devam edin: süre seçin' : ($isPremium ? 'Üyeliği uzatın: süre seçin' : 'Süre seçin') }}</div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            @foreach($plans as $plan)
                                @php $best = $plan['months'] === 12 && $plan['discount'] > 0; @endphp
                                <a href="{{ route('driver.premium.checkout', ['sure' => $plan['months']]) }}" wire:navigate
                                   class="relative flex flex-col rounded-2xl border p-3 transition-colors hover:border-brand-500 {{ $best ? 'border-brand-500 bg-brand-500/5' : 'border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-950' }}">
                                    @if($plan['discount'] > 0)
                                        <span class="absolute -top-2 right-2 rounded-full bg-emerald-600 px-2 py-0.5 text-3xs font-black text-white">%{{ rtrim(rtrim(number_format($plan['discount'], 1, ',', '.'), '0'), ',') }} indirim</span>
                                    @endif
                                    <span class="text-sm font-black text-neutral-900 dark:text-white">{{ $plan['label'] }}</span>
                                    <span class="mt-1 text-base font-black tabular-nums {{ $best ? 'text-brand-500' : 'text-neutral-900 dark:text-white' }}">{{ number_format($plan['price'], 0, ',', '.') }} ₺</span>
                                    <span class="text-2xs text-neutral-500 dark:text-neutral-400">{{ $plan['months'] > 1 ? 'ayda ≈ '.number_format($plan['per_month'], 0, ',', '.').' ₺' : 'aylık' }}</span>
                                    @if($plan['saving'] > 0)
                                        <span class="text-2xs font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($plan['saving'], 0, ',', '.') }} ₺ kazanç</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                        <span class="text-2xs text-neutral-500 block">Kredi kartı, banka kartı; KDV dahil fatura panelinizde. {{ $autoRenewAvailable ? 'Ödeme sayfasında otomatik yenilemeyi seçebilirsiniz; seçmezseniz üyelik dönem sonunda biter.' : 'Otomatik yenilenmez; süre mevcut dönemin bitiminden itibaren eklenir.' }}</span>
                    </div>
                @endif
            </div>

            @if($autoRenewAvailable && ($paidSubscription || $storedCard))
                {{-- Otomatik yenileme kartı: durum + tek dokunuşla aç/kapat + kayıtlı kartı sil. Kart NavlunIQ'da değil iyzico'da saklanır. --}}
                <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h3 class="section-title">Otomatik yenileme</h3>
                            <div class="mt-1 font-bold {{ $willAutoRenew ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-900 dark:text-white' }}">{{ $willAutoRenew ? 'Açık' : 'Kapalı' }}</div>
                            <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed mt-0.5">
                                @if($willAutoRenew)
                                    {{ $paidSubscription->current_period_ends_at->format('d.m.Y') }} bitişinden 3 gün önce kayıtlı kartınızdan ({{ $paidSubscription->storedCard->label() }}) {{ in_array((int) $paidSubscription->renew_months, \App\Services\SubscriptionService::PLAN_MONTHS, true) ? $paidSubscription->renew_months : 1 }} aylık güncel ücret çekilir; bedel çekimden önce bildirilir. Kapatırsanız üyelik dönem sonuna kadar sürer.
                                @elseif($paidSubscription && $paidSubscription->last_renewal_error)
                                    Son yenileme denemesi başarısız: {{ $paidSubscription->last_renewal_error }}. Üyeliğiniz {{ $paidSubscription->current_period_ends_at->format('d.m.Y') }} tarihinde biter; yeni ödeme yapınca kart yeniden kaydedilir.
                                @elseif($paidSubscription && $storedCard)
                                    Üyeliğiniz {{ $paidSubscription->current_period_ends_at->format('d.m.Y') }} tarihinde biter. Açarsanız kayıtlı kartınızdan ({{ $storedCard->label() }}) kendiliğinden uzatılır.
                                @elseif($paidSubscription)
                                    Kayıtlı kartınız yok; yeni ödeme yaparken "kartımı sakla" seçeneğiyle otomatik yenilemeyi açabilirsiniz.
                                @else
                                    Sürmekte olan ücretli üyelik yok; kayıtlı kartınız yeni ödemede iyzico sayfasında listelenir.
                                @endif
                            </p>
                        </div>
                        @if($paidSubscription)
                            <div class="shrink-0">
                                @if($willAutoRenew)
                                    <button type="button" wire:click="setAutoRenew(false)" wire:loading.attr="disabled" class="inline-flex px-4 py-2 rounded-xl border border-neutral-200 dark:border-neutral-700 text-neutral-800 dark:text-neutral-100 font-semibold">Yenilemeyi kapat</button>
                                @elseif($storedCard)
                                    <button type="button" wire:click="setAutoRenew(true)" wire:loading.attr="disabled" class="btn-primary text-sm px-4 py-2">Yenilemeyi aç</button>
                                @endif
                            </div>
                        @endif
                    </div>
                    @if($storedCard)
                        <div class="flex items-center justify-between gap-3 pt-3 border-t border-neutral-100 dark:border-neutral-800">
                            <span class="text-neutral-600 dark:text-neutral-300">Kayıtlı kart: <strong>{{ $storedCard->label() }}</strong> · iyzico'da saklanır, NavlunIQ kart numarasını görmez.</span>
                            <button type="button" wire:click="deleteCard({{ $storedCard->id }})" wire:confirm="Kayıtlı kart silinsin mi? Otomatik yenileme kapanır." class="text-rose-500 font-semibold hover:underline shrink-0">Kartı sil</button>
                        </div>
                    @endif
                </div>
            @endif

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="section-title">Premium avantajları</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="text-neutral-900 dark:text-white font-bold">{{ $leadText }} önce görürsünüz</div>
                        <div class="text-neutral-500 dark:text-neutral-400">Yük sahiplerinin açtığı sistem ilanları önce premium üyelere açılır; diğer üyeler {{ $lead > 0 ? $lead.' dakika sonra' : 'aynı anda' }} görür.</div>
                    </div>
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="text-neutral-900 dark:text-white font-bold">Anında bildirim</div>
                        <div class="text-neutral-500 dark:text-neutral-400">Aracınıza uygun yeni ilan yayınlandığı anda uygulama içi bildirim ve e-posta alırsınız; ilk teklifi siz verirsiniz.</div>
                        <button type="button" wire:click="toggleLoadMail" class="mt-1 inline-flex items-center gap-1.5 text-2xs font-bold {{ $loadMail ? 'text-emerald-600' : 'text-neutral-500' }} hover:underline">
                            <span class="w-2 h-2 rounded-full {{ $loadMail ? 'bg-emerald-500' : 'bg-neutral-400' }}"></span>{{ $loadMail ? 'E-posta: açık · kapat' : 'E-posta: kapalı · aç' }}
                        </button>
                    </div>
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="text-neutral-900 dark:text-white font-bold">Grup ilanları yalnız size</div>
                        <div class="text-neutral-500 dark:text-neutral-400">Numaralar yalnız o ilan için ilan sahibiyle görüşmeniz içindir; üçüncü kişilerle paylaşılamaz (Kullanıcı Sözleşmesi md. 3.4). Gruplardan ve web mecralarından derlenen ilanlar ilan sahibinin telefon numarasıyla yalnız premium üyelere gösterilir; standart üyeler bu ilanları görmez.</div>
                    </div>
                </div>
                @unless(\App\Support\FreightPayment::direct())
                    <p class="text-2xs text-neutral-500">Platform hizmet bedeli (%{{ number_format($standardRate, 1, ',', '.') }}) üyelik türünden bağımsızdır; premium ile değişmez.</p>
                @endunless
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="section-title">Abonelik faturaları</h3>
                @forelse($invoices as $invoice)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2" wire:key="inv-{{ $invoice->id }}">
                        <div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $invoice->invoice_no ?: 'Numara bekleniyor' }}</div>
                            <div class="text-2xs text-neutral-500">{{ $invoice->issued_at?->format('d.m.Y H:i') ?? $invoice->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format((float) ($invoice->total_amount ?? 0), 2, ',', '.') }} ₺ · {{ $invoice->statusLabel() }}</div>
                    </div>
                @empty
                    <div class="text-neutral-500">Henüz abonelik faturanız yok.</div>
                @endforelse
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                <h3 class="section-title">Nasıl çalışır</h3>
                <p>Premium hakkı yalnız doğrulanmış bir ödeme sonrasında tanımlanır; kart bilgileri NavlunIQ'da saklanmaz.</p>
                <p>{{ $willAutoRenew ? 'Otomatik yenileme açıkken üyeliğiniz kesintisiz sürer; kapattığınızda dönem sonunda' : 'Üyelik süresi dolduğunda' }} hesabınız kendiliğinden standart üyeliğe döner; ilanlarınız ve geçmişiniz aynen kalır.</p>
                @if($telegramUrl = \App\Services\TelegramPublisher::channelUrl())
                    <p>Uygulamayı sürekli açmak istemiyorsanız sistem ilanları herkese açıldığı anda <a href="{{ $telegramUrl }}" target="_blank" rel="noopener" class="text-brand-500 font-bold hover:underline">Telegram kanalımızda</a> da yayınlanır.</p>
                @endif
            </div>
        </div>
    </div>
</div>
