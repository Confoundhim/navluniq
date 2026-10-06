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

        return [
            'profile' => $profile,
            'isPremium' => $profile?->isPremium() ?? false,
            'premiumUntil' => $profile?->premium_until,
            'trialDays' => $subscriptions->trialDays(),
            'trialEligible' => $subscriptions->trialEligible($profile),
            'trialEndsAt' => $subscriptions->activeTrialEndsAt($user),
            'loadMail' => $profile ? \App\Services\LoadReleaseService::wantsLoadMail($profile) : true,
            'monthlyPrice' => Settings::float('premium_monthly_price'),
            'standardRate' => Settings::float('commission_standard_driver'),
            'paymentReady' => app(PaymentService::class)->isConfigured(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->where('invoice_type', 'subscription')->latest('id')->take(20)->get(),
        ];
    }
}; ?>

<div class="space-y-6">

    @php
        $lead = app(\App\Services\LoadReleaseService::class)->delayMinutes();
        $leadText = $lead > 0 ? $lead.' dakika' : 'aynı anda';
    @endphp
    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Premium Abonelik</h2>
        <p class="page-subtitle">Premium üyeler gruplardan derlenen ilanları ilan sahibinin numarasıyla görür, yeni NavlunIQ ilanlarını herkesten {{ $leadText }} önce görür ve anında bildirim alır; standart üyeye bildirim gitmez, grup ilanları görünmez.</p>
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
                                Deneme süreniz {{ $trialEndsAt->format('d.m.Y H:i') }} tarihine kadar; ücret alınmaz, sonra isterseniz aylık devam edersiniz.
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
                        <p class="text-xs text-neutral-600 dark:text-neutral-300 leading-relaxed">Premium'un tamamı {{ $trialDays }} gün boyunca ücretsiz: gruplardan derlenen ilanlar numarasıyla, yeni ilanlar herkesten önce ve bildirimle, dönüş yükü radarı. Kart bilgisi istenmez; süre bitince ücret alınmaz, hesabınız kendiliğinden standart üyeliğe döner.</p>
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
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <a href="{{ route('driver.premium.checkout') }}" wire:navigate class="btn-primary text-sm px-6 py-3 text-center">{{ $trialEndsAt ? 'Denemeden sonra devam et' : ($isPremium ? '1 ay daha uzat' : 'Premium\'u başlat') }} · {{ number_format($monthlyPrice, 2, ',', '.') }} ₺</a>
                        <span class="text-2xs text-neutral-500">Kredi kartı, banka kartı; KDV dahil fatura panelinizde. Otomatik yenilenmez.</span>
                    </div>
                @endif
            </div>

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
                        <div class="text-neutral-500 dark:text-neutral-400">Numaralar yalnız o ilan için ilan sahibiyle görüşmeniz içindir; üçüncü kişilerle paylaşılamaz (Kullanıcı Sözleşmesi md. 3.4). İzinli gruplardan derlenip onaylanan dış kaynak ilanları ilan sahibinin telefon numarasıyla yalnız premium üyelere gösterilir; standart üyeler bu ilanları görmez.</div>
                    </div>
                </div>
                <p class="text-2xs text-neutral-500">Platform hizmet bedeli (%{{ number_format($standardRate, 1, ',', '.') }}) üyelik türünden bağımsızdır; premium ile değişmez.</p>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3 text-xs">
                <h3 class="section-title">Abonelik faturaları</h3>
                @forelse($invoices as $invoice)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $invoice->invoice_no ?: 'Numara bekleniyor' }}</div>
                            <div class="text-2xs text-neutral-500">{{ $invoice->issued_at?->format('d.m.Y H:i') ?? $invoice->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format((float) ($invoice->total_amount ?? 0), 2, ',', '.') }} ₺ · {{ $invoice->status }}</div>
                    </div>
                @empty
                    <div class="text-neutral-500">Henüz abonelik faturanız yok.</div>
                @endforelse
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                <h3 class="section-title">Nasıl çalışır</h3>
                <p>Premium hakkı yalnız doğrulanmış bir ödeme sonrasında tanımlanır; kart bilgileri NavlunIQ'da saklanmaz.</p>
                <p>Üyelik süresi dolduğunda hesabınız kendiliğinden standart üyeliğe döner; ilanlarınız ve geçmişiniz aynen kalır.</p>
                @if($telegramUrl = \App\Services\TelegramPublisher::channelUrl())
                    <p>Uygulamayı sürekli açmak istemiyorsanız sistem ilanları herkese açıldığı anda <a href="{{ $telegramUrl }}" target="_blank" rel="noopener" class="text-brand-500 font-bold hover:underline">Telegram kanalımızda</a> da yayınlanır.</p>
                @endif
            </div>
        </div>
    </div>
</div>
