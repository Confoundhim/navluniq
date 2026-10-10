<?php

use App\Models\BankAccount;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\Payout;
use App\Payments\GatewayManager;
use App\Payments\Gateways\IyzicoGateway;
use App\Services\BankAccountService;
use App\Services\GibService;
use App\Services\KycService;
use App\Services\NotificationService;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Ödemelerim: hakediş kayıtları, IBAN ve ödeme kimliği (TC / VKN). Pazaryeri modelinde ödeme kuruluşu şoförü alt üye işyeri
 * olarak kaydeder; bunun için IBAN + kimlik birlikte gerekir. IBAN değişikliği şifre ister ve hesaba bildirilir (P7).
 */
new
#[Layout('components.layouts.driver')]
#[Title('Ödemelerim')]
class extends Component {
    use WithPagination;

    public string $iban = '';

    public string $account_holder = '';

    public string $legal_type = DriverProfile::LEGAL_INDIVIDUAL;

    public string $identity_number = '';

    public string $tax_number = '';

    public string $tax_office = '';

    /** iyzico pazaryeri satıcı sözleşmesi onayı (bir kez; onaylanınca kutu bir daha çıkmaz). */
    public bool $iyzico_terms = false;

    public string $bank_password = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->account_holder = $user->full_name;
        $profile = $user->driverProfile;
        $this->legal_type = $profile?->legal_type ?: DriverProfile::LEGAL_INDIVIDUAL;
        $this->tax_office = (string) ($profile?->tax_office ?? '');
    }

    public function saveBankAccount(BankAccountService $bankAccounts): void
    {
        $this->validate([
            'iban' => 'required|string|min:26|max:40',
            'account_holder' => 'required|string|min:3|max:120',
            'legal_type' => 'required|in:individual,sole_proprietor,company',
            'identity_number' => 'nullable|digits:11',
            'tax_number' => 'nullable|digits:10',
            'tax_office' => 'nullable|string|max:120',
            'bank_password' => 'required|current_password',
        ], [
            'iban.required' => 'IBAN zorunludur.',
            'iban.min' => 'IBAN TR ile başlayan 26 karakter olmalıdır.',
            'account_holder.required' => 'Hesap sahibi adı zorunludur.',
            'identity_number.digits' => 'T.C. kimlik numarası 11 rakamdan oluşur.',
            'tax_number.digits' => 'Vergi numarası 10 rakamdan oluşur.',
            'bank_password.required' => 'IBAN kaydetmek için mevcut şifrenizi girin.',
            'bank_password.current_password' => 'Mevcut şifreniz hatalı.',
        ]);

        $user = Auth::user();
        // Taze sorgu: önbellekli ilişki, bu istek içinde başka yerden yazılan payout_provider_ref'i görmez ve "değişmedi" sanıp yazmaz.
        $profile = $user->driverProfile()->first();
        if (! $profile) {
            $this->addError('iban', 'Şoför profili bulunamadı.');

            return;
        }

        if ($this->needsSellerAgreement($profile) && ! $this->iyzico_terms) {
            $this->addError('iyzico_terms', 'Ödeme alabilmek için iyzico satıcı sözleşmesini onaylamanız gerekir.');

            return;
        }

        // Kimlik: bireysel ve şahıs şirketinde TC (sağlama denetimi), şirkette VKN (GİB algoritması). Yoksa mevcut kayıt korunur; uydurma numara kabul edilmez.
        $identity = $profile->identity_number;
        $tax = $profile->tax_number;
        if ($this->legal_type === DriverProfile::LEGAL_COMPANY) {
            if ($this->tax_number !== '') {
                if (! (new GibService)->verifyTax($this->tax_number)['is_match']) {
                    $this->addError('tax_number', 'Vergi numarası geçersiz; lütfen vergi levhanızdaki 10 haneli numarayı girin.');

                    return;
                }
                $tax = $this->tax_number;
            }
            if (! $tax) {
                $this->addError('tax_number', 'Şirket hesabı için vergi numarası zorunludur.');

                return;
            }
        } else {
            if ($this->identity_number !== '') {
                if (! KycService::isValidTcNo($this->identity_number)) {
                    $this->addError('identity_number', 'T.C. kimlik numarası geçersiz; lütfen kimlik kartınızdaki 11 haneli numarayı girin.');

                    return;
                }
                $identity = $this->identity_number;
            }
            if (! $identity) {
                $this->addError('identity_number', 'Ödeme kuruluşu kaydı için T.C. kimlik numaranız zorunludur.');

                return;
            }
        }

        $previous = $user->defaultBankAccount;
        try {
            $account = $bankAccounts->save($user, $this->iban, $this->account_holder, true);
        } catch (\InvalidArgumentException $e) {
            $this->addError('iban', $e->getMessage());

            return;
        }

        $ibanChanged = ! $previous || $previous->id !== $account->id;
        $requiresTaxOffice = in_array($this->legal_type, [DriverProfile::LEGAL_SOLE, DriverProfile::LEGAL_COMPANY], true);
        $taxOffice = $requiresTaxOffice ? trim($this->tax_office) : null;
        if ($requiresTaxOffice && $taxOffice === '') {
            $this->addError('tax_office', 'Şahıs şirketi ve şirket hesabı için vergi dairesi zorunludur (ödeme kuruluşu kaydında istenir).');

            return;
        }
        $identityChanged = $identity !== $profile->identity_number || $tax !== $profile->tax_number || $this->legal_type !== $profile->legal_type || $taxOffice !== $profile->tax_office;
        $profile->update([
            'legal_type' => $this->legal_type,
            'identity_number' => $identity,
            'tax_number' => $tax,
            'tax_office' => $taxOffice,
            'iyzico_seller_agreed_at' => $profile->iyzico_seller_agreed_at ?: ($this->iyzico_terms ? now() : null),
            // IBAN ya da kimlik değişince kuruluş kaydı yeni bilgiyle yenilenir; otomatik aktarım ayarlı süre bekler.
            'payout_provider_ref' => ($ibanChanged || $identityChanged) ? null : $profile->payout_provider_ref,
            'bank_account_changed_at' => $ibanChanged ? now() : $profile->bank_account_changed_at,
        ]);

        if ($ibanChanged) {
            app(NotificationService::class)->notify($user, 'IBAN bilginiz değiştirildi',
                ['Ödeme alacağınız hesap '.now()->format('d.m.Y H:i').' tarihinde '.$account->maskedIban().' olarak güncellendi.',
                    'Bu işlemi siz yapmadıysanız hemen şifrenizi değiştirin ve destek ekibimize yazın; otomatik ödemeler güvenlik için kısa süre bekletilir.'],
                route('driver.wallet.index'), 'Ödemelerim', 'security');
        }
        try {
            app(PayoutService::class)->ensureSubMerchant($profile->fresh());
        } catch (\Throwable) {
            // kuruluş kaydı sonra (teklif kabulünde) yeniden denenir
        }

        $this->reset(['iban', 'bank_password', 'identity_number', 'tax_number', 'iyzico_terms']);
        session()->flash('success_message', 'Ödeme bilgileriniz kaydedildi. Navlun ödemeleriniz bu hesaba yapılacaktır.');
    }

    /** Başarısız hakediş: şoför IBAN'ı düzeltti, yeniden gönderim ister. */
    public function retryPayout(int $payoutId): void
    {
        $payout = Payout::query()->where('user_id', Auth::id())->whereKey($payoutId)->first();
        if (! $payout) {
            session()->flash('error_message', 'Ödeme kaydı bulunamadı.');

            return;
        }
        try {
            app(PayoutService::class)->retryAfterFix($payout, Auth::user());
            session()->flash('success_message', 'Ödeme yeniden sıraya alındı; finans ekibi bilgilendirildi.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    /** Etkin ödeme kuruluşu iyzico ise ve şoför satıcı sözleşmesini henüz onaylamadıysa kutu gösterilir ve zorunludur. */
    private function needsSellerAgreement(?DriverProfile $profile): bool
    {
        return app(GatewayManager::class)->active()->id() === 'iyzico' && ! $profile?->iyzico_seller_agreed_at;
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'profile' => $user->driverProfile,
            // Doğrudan kip: navlun taraflar arasında ödenir; hakediş, IBAN, kimlik ve satıcı sözleşmesi şu an gerekmez.
            'directPayment' => \App\Support\FreightPayment::direct(),
            'needsSellerAgreement' => $this->needsSellerAgreement($user->driverProfile),
            'sellerAgreementUrl' => IyzicoGateway::SELLER_AGREEMENT_URL,
            'summary' => app(PayoutService::class)->walletSummary($user),
            'payouts' => Payout::query()->with('cargoLoad')->where('user_id', $user->id)->latest('id')->paginate(15),
            'bankAccount' => BankAccount::query()->where('user_id', $user->id)->where('is_default', true)->first(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->latest('id')->take(20)->get(),
        ];
    }
}; ?>

<div @unless($directPayment) wire:poll.30s @endunless class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    @php
        $legacyTotal = (float) ($summary['pending'] ?? 0) + (float) ($summary['paid'] ?? 0) + (float) ($summary['in_escrow'] ?? 0) + (float) ($summary['failed'] ?? 0);
        $hasLegacy = $legacyTotal > 0 || $payouts->total() > 0;
    @endphp

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Ödemelerim</h2>
        <p class="page-subtitle">@if($directPayment)Navlun bedeli yük sahibiyle aranızda doğrudan ödenir; NavlunIQ tahsilat yapmaz, komisyon almaz.@else Navlun ödemeleriniz, teslimat onayından sonra lisanslı ödeme kuruluşu aracılığıyla kayıtlı IBAN adresinize yapılır.@endif</p>
    </div>

    @if($directPayment)
        <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 text-xs text-neutral-700 dark:text-neutral-300 leading-relaxed space-y-1">
            <div class="font-bold text-neutral-900 dark:text-white">Navlun şoförle yük sahibi arasında ödenir</div>
            <p>Teklifiniz kabul edilince yük sahibinin iletişim bilgilerini ve yükleme adresini hemen görürsünüz; ödemeyi yük sahibiyle aranızda anlaştığınız şekilde alırsınız. NavlunIQ para tutmaz, hakediş oluşturmaz, komisyon almaz. IBAN ve kimlik bilgisi şu an gerekmez; platform üzerinden ödeme açılırsa burada istenir.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 {{ $directPayment && ! $hasLegacy ? 'hidden' : '' }}">
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödeme sırasında</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($summary['pending'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-2xs text-neutral-500">Onaylanmış, hesaba geçmeyi bekleyen net ödeme</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödendi</div>
            <div class="mt-2 text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">{{ number_format((float) ($summary['paid'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-2xs text-neutral-500">Banka hesabınıza aktarılan toplam</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Teslimat onayı bekleyen</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($summary['in_escrow'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-2xs text-neutral-500">Devam eden sevkiyatların navlun bedeli</div>
        </div>
        @if(($summary['failed'] ?? 0) > 0)
            <div class="bg-white dark:bg-neutral-900 border border-rose-300 dark:border-rose-800 rounded-2xl p-6">
                <div class="text-xs text-rose-600 dark:text-rose-400">Düzeltme bekleyen</div>
                <div class="mt-2 text-2xl font-black text-rose-600 dark:text-rose-400 tabular-nums">{{ number_format((float) $summary['failed'], 2, ',', '.') }} ₺</div>
                <div class="mt-1 text-2xs text-neutral-500">Banka reddetti; IBAN'ı düzeltip yeniden gönderin</div>
            </div>
        @else
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
                <div class="text-xs text-neutral-500 dark:text-neutral-400">Kesilen komisyon</div>
                <div class="mt-2 text-2xl font-black text-neutral-700 dark:text-neutral-300 tabular-nums">{{ number_format((float) ($summary['commission'] ?? 0), 2, ',', '.') }} ₺</div>
                <div class="mt-1 text-2xs text-neutral-500">Tüm ödemelerden düşülen toplam</div>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="section-title">Ödeme kayıtları</h3>

                @if($directPayment && ! $payouts->count())
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Platform üzerinden ödeme kaydınız yok; navlun yük sahibiyle aranızda ödenir.</div>
                @elseif($payouts->count())
                    <div class="responsive-scroll overflow-x-auto">
                        <table class="table-cards w-full text-xs text-left">
                            <thead class="text-2xs uppercase text-neutral-500 border-b border-neutral-200 dark:border-neutral-800">
                                <tr>
                                    <th class="py-2 pr-3">Sevkiyat</th>
                                    <th class="py-2 pr-3">Navlun</th>
                                    <th class="py-2 pr-3">Komisyon</th>
                                    <th class="py-2 pr-3">Net</th>
                                    <th class="py-2 pr-3">Durum</th>
                                    <th class="py-2 pr-3">Referans</th>
                                    <th class="py-2">Ödeme tarihi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @foreach($payouts as $payout)
                                    <tr wire:key="payout-{{ $payout->id }}">
                                        <td class="py-3 pr-3 text-neutral-900 dark:text-white">
                                            @if($payout->cargoLoad)
                                                <a href="{{ route('driver.jobs.show', $payout->cargoLoad->id) }}" wire:navigate class="hover:text-brand-400">{{ $payout->cargoLoad->pickup_location }} &rarr; {{ $payout->cargoLoad->delivery_location }}</a>
                                            @else
                                                İlan kaldırılmış
                                            @endif
                                        </td>
                                        <td class="py-3 pr-3 tabular-nums text-neutral-700 dark:text-neutral-300" data-label="Navlun">{{ number_format((float) ($payout->total_amount ?? 0), 2, ',', '.') }} ₺</td>
                                        <td class="py-3 pr-3 tabular-nums text-neutral-500 dark:text-neutral-400" data-label="Komisyon">{{ number_format((float) ($payout->commission_amount ?? 0), 2, ',', '.') }} ₺</td>
                                        <td class="py-3 pr-3 tabular-nums font-bold text-neutral-900 dark:text-white" data-label="Net">{{ number_format((float) ($payout->net_amount ?? 0), 2, ',', '.') }} ₺</td>
                                        <td class="py-3 pr-3 tc-block" data-label="Durum">
                                            <span class="px-2 py-0.5 rounded-full text-2xs font-bold border
                                                {{ $payout->status === 'paid' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($payout->status === 'failed' ? 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400' : 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400') }}">
                                                {{ \App\Models\Payout::STATUS_LABELS[$payout->status] ?? $payout->status }}
                                            </span>
                                            @if($payout->status === 'failed')
                                                @if($payout->failure_reason)<div class="text-2xs text-rose-600 dark:text-rose-400 mt-1">{{ $payout->failure_reason }}</div>@endif
                                                @if($bankAccount)
                                                    <button type="button" wire:click="retryPayout({{ $payout->id }})" wire:confirm="IBAN bilginiz güncel mi? Ödeme yeniden sıraya alınacak." class="mt-1 text-2xs font-bold text-brand-500 hover:underline">IBAN'ı güncelledim, yeniden gönder</button>
                                                @else
                                                    <div class="text-2xs text-neutral-500 mt-1">Önce IBAN ekleyin.</div>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="py-3 pr-3 font-mono text-neutral-500 dark:text-neutral-400" data-label="Referans">{{ $payout->reference_no ?: '—' }}</td>
                                        <td class="py-3 text-neutral-500 dark:text-neutral-400" data-label="Ödeme tarihi">{{ $payout->paid_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($payouts->hasPages())
                        <div class="pt-2">{{ $payouts->links() }}</div>
                    @endif
                @else
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz ödeme kaydınız yok. Tamamlanan ve onaylanan sevkiyatlar burada listelenir.</div>
                @endif
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
                <h3 class="section-title">Faturalar</h3>
                @forelse($invoices as $invoice)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs" wire:key="inv-{{ $invoice->id }}">
                        <div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $invoice->invoice_no ?: 'Numara bekleniyor' }}</div>
                            <div class="text-2xs text-neutral-500">{{ $invoice->typeLabel() }} · {{ $invoice->issued_at?->format('d.m.Y H:i') ?? $invoice->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format((float) ($invoice->total_amount ?? 0), 2, ',', '.') }} ₺ · {{ $invoice->statusLabel() }}</div>
                    </div>
                @empty
                    <div class="text-xs text-neutral-500">Henüz adınıza kesilmiş fatura yok.</div>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
                <h3 class="section-title">Ödeme alacağınız hesap</h3>

                @if($directPayment)
                    <div class="p-3 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 text-neutral-600 dark:text-neutral-400 leading-relaxed">Şu an gerekmez: navlun yük sahibiyle aranızda ödenir. Platform üzerinden ödeme açılırsa IBAN ve kimlik bilgisi burada istenir.</div>
                    @if($bankAccount)
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                            <div class="text-neutral-900 dark:text-white font-mono font-bold">{{ $bankAccount->maskedIban() }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">{{ $bankAccount->account_holder }}</div>
                            <div class="text-2xs text-neutral-500">Daha önce kaydedilen hesap; şu an kullanılmaz.</div>
                        </div>
                    @endif
                @else
                @if($bankAccount)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="text-neutral-900 dark:text-white font-mono font-bold">{{ $bankAccount->maskedIban() }}</div>
                        <div class="text-neutral-500 dark:text-neutral-400">{{ $bankAccount->account_holder }}</div>
                        @if($profile?->hasPayoutIdentity())
                            <div class="text-neutral-500 dark:text-neutral-400">{{ $profile->legalTypeLabel() }} · {{ $profile->legal_type === 'company' ? 'Vergi no' : 'T.C. kimlik no' }}: <span class="font-mono">{{ $profile->maskedPayoutIdentity() }}</span></div>
                        @endif
                        <div class="text-2xs text-neutral-500">{{ $profile?->payout_provider_ref ? 'Ödeme kuruluşuna kayıtlı' : ($bankAccount->is_verified ? 'Doğrulandı' : 'İlk ödemede ödeme kuruluşuna kaydedilir') }}</div>
                    </div>
                @else
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300">Kayıtlı IBAN adresiniz yok. Teklifinizin kabul edilebilmesi ve ödeme alabilmeniz için IBAN ve kimlik numaranızı ekleyin.</div>
                @endif
                @if($bankAccount && ! $profile?->hasPayoutIdentity())
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300">Kimlik (T.C. / vergi) numaranız kayıtlı değil; ödeme kuruluşu kaydı için gerekir. Aşağıdaki formu doldurun.</div>
                @endif

                <form wire:submit.prevent="saveBankAccount" class="space-y-3 border-t border-neutral-200 dark:border-neutral-800 pt-4">
                    <div>
                        <label class="form-label">Hesap türü</label>
                        <select wire:model.live="legal_type" class="form-input">
                            <option value="individual">Bireysel (T.C. kimlik no ile)</option>
                            <option value="sole_proprietor">Şahıs şirketi (T.C. kimlik no + vergi dairesi)</option>
                            <option value="company">Limited / Anonim şirket (vergi no ile)</option>
                        </select>
                    </div>
                    @if($legal_type === 'company')
                        <div>
                            <label class="form-label">Vergi numarası (10 hane){{ $profile?->tax_number ? ' · kayıtlı: '.$profile->maskedPayoutIdentity() : '' }}</label>
                            <input type="text" inputmode="numeric" wire:model="tax_number" maxlength="10" autocomplete="off" placeholder="{{ $profile?->tax_number ? 'Değiştirmek için yeni numara' : '' }}" class="form-input font-mono">
                            @error('tax_number') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    @else
                        <div>
                            <label class="form-label">T.C. kimlik numarası{{ $profile?->identity_number ? ' · kayıtlı: '.$profile->maskedPayoutIdentity() : '' }}</label>
                            <input type="text" inputmode="numeric" wire:model="identity_number" maxlength="11" autocomplete="off" placeholder="{{ $profile?->identity_number ? 'Değiştirmek için yeni numara' : '' }}" class="form-input font-mono">
                            @error('identity_number') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    @endif
                    @if($legal_type !== 'individual')
                        <div>
                            <label class="form-label">Vergi dairesi</label>
                            <input type="text" wire:model="tax_office" maxlength="120" autocomplete="organization" placeholder="Örn. Başkent" class="form-input">
                            @error('tax_office') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    @endif
                    <div>
                        <label class="form-label">{{ $bankAccount ? 'Yeni IBAN' : 'IBAN' }}</label>
                        <input type="text" wire:model="iban" placeholder="TR00 0000 0000 0000 0000 0000 00" class="form-input font-mono">
                        @error('iban') <span class="form-error">{{ $message }}</span> @enderror
                        <p class="mt-1 text-2xs text-neutral-500">IBAN {{ $legal_type === 'individual' ? 'kendi adınıza' : 'vergi levhanızdaki unvana' }} kayıtlı olmalı; ödeme kuruluşu başkasının hesabına aktarım yapmaz.</p>
                    </div>
                    <div>
                        <label class="form-label">{{ $legal_type === 'individual' ? 'Hesap sahibi' : 'Hesap sahibi (vergi levhasındaki unvan)' }}</label>
                        <input type="text" wire:model="account_holder" class="form-input">
                        @error('account_holder') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    @if($needsSellerAgreement)
                        <label class="flex items-start gap-3 p-3 rounded-xl border {{ $errors->has('iyzico_terms') ? 'border-rose-400 bg-rose-500/5' : 'border-neutral-200 dark:border-neutral-700' }} cursor-pointer">
                            <input type="checkbox" wire:model="iyzico_terms" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-brand-500 focus:ring-brand-500">
                            <span class="text-2xs text-neutral-600 dark:text-neutral-300 leading-relaxed">
                                Navlun ödemelerinin lisanslı ödeme kuruluşu iyzico üzerinden hesabıma aktarılması için
                                <a href="{{ $sellerAgreementUrl }}" target="_blank" rel="noopener" class="text-brand-500 font-semibold hover:underline">iyzico Pazaryeri Satıcı Sözleşmesi</a>'ni okudum, kabul ediyorum.
                            </span>
                        </label>
                        @error('iyzico_terms') <span class="form-error">{{ $message }}</span> @enderror
                    @endif
                    <div>
                        <label class="form-label">Mevcut şifreniz</label>
                        <input type="password" wire:model="bank_password" autocomplete="current-password" class="form-input">
                        @error('bank_password') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveBankAccount">Kaydet</span>
                        <span wire:loading wire:target="saveBankAccount">Kaydediliyor...</span>
                    </button>
                </form>
                @endif
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                <h3 class="section-title">Ödeme süreci</h3>
                @if($directPayment)
                    <p>Teklifiniz kabul edilince yük sahibiyle doğrudan görüşürsünüz; navlun bedelini aranızda anlaştığınız şekilde alırsınız.</p>
                    <p>Teslimat kanıtını yükledikten ve yük sahibi onayladıktan sonra iş tamamlanır. NavlunIQ bu ödemeye taraf olmaz; sorun olursa uyuşmazlık kaydı açabilirsiniz.</p>
                @else
                    <p>Yük sahibi teslimatı onayladığında ödemeniz platform hizmet bedeli düşülerek hesabınıza geçer.</p>
                    <p>Ödeme, lisanslı ödeme kuruluşu tarafından kayıtlı IBAN adresinize aktarılır; tamamlandığında referans numarası bu sayfada görünür. Kimlik numarası, ödeme kuruluşunun yasal zorunluluğudur ve yalnız bu amaçla kullanılır.</p>
                @endif
            </div>
        </div>
    </div>
</div>
