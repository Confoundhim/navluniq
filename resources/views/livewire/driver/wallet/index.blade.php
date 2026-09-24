<?php

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Payout;
use App\Services\BankAccountService;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('Ödemelerim')]
class extends Component {
    use WithPagination;

    public string $iban = '';

    public string $account_holder = '';

    public function mount(): void
    {
        $this->account_holder = Auth::user()->full_name;
    }

    public function saveBankAccount(BankAccountService $bankAccounts): void
    {
        $this->validate([
            'iban' => 'required|string|min:26|max:40',
            'account_holder' => 'required|string|min:3|max:120',
        ], [
            'iban.required' => 'IBAN zorunludur.',
            'iban.min' => 'IBAN TR ile başlayan 26 karakter olmalıdır.',
            'account_holder.required' => 'Hesap sahibi adı zorunludur.',
        ]);

        try {
            $bankAccounts->save(Auth::user(), $this->iban, $this->account_holder, true);
        } catch (\InvalidArgumentException $e) {
            $this->addError('iban', $e->getMessage());

            return;
        }

        $this->reset(['iban']);
        session()->flash('success_message', 'Banka hesabınız kaydedildi. Ödemeleriniz bu hesaba yapılacaktır.');
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'summary' => app(PayoutService::class)->walletSummary($user),
            'payouts' => Payout::query()->with('cargoLoad')->where('user_id', $user->id)->latest('id')->paginate(15),
            'bankAccount' => BankAccount::query()->where('user_id', $user->id)->where('is_default', true)->first(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->latest('id')->take(20)->get(),
        ];
    }
}; ?>

<div wire:poll.10s class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Ödemelerim</h2>
        <p class="page-subtitle">Navlun ödemeleriniz, teslimat onayından sonra lisanslı ödeme kuruluşu aracılığıyla kayıtlı IBAN adresinize yapılır.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödeme sırasında</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($summary['pending'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-[11px] text-neutral-500">Onaylanmış, hesaba geçmeyi bekleyen net ödeme</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Ödendi</div>
            <div class="mt-2 text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">{{ number_format((float) ($summary['paid'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-[11px] text-neutral-500">Banka hesabınıza aktarılan toplam</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Teslimat onayı bekleyen</div>
            <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white tabular-nums">{{ number_format((float) ($summary['in_escrow'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-[11px] text-neutral-500">Devam eden sevkiyatların navlun bedeli</div>
        </div>
        <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
            <div class="text-xs text-neutral-500 dark:text-neutral-400">Kesilen komisyon</div>
            <div class="mt-2 text-2xl font-black text-neutral-700 dark:text-neutral-300 tabular-nums">{{ number_format((float) ($summary['commission'] ?? 0), 2, ',', '.') }} ₺</div>
            <div class="mt-1 text-[11px] text-neutral-500">Tüm ödemelerden düşülen toplam</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">
                <h3 class="section-title">Ödeme kayıtları</h3>

                @if($payouts->count())
                    <div class="responsive-scroll overflow-x-auto">
                        <table class="table-cards w-full text-xs text-left">
                            <thead class="text-[11px] uppercase text-neutral-500 border-b border-neutral-200 dark:border-neutral-800">
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
                                    <tr>
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
                                        <td class="py-3 pr-3" data-label="Durum">
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold border
                                                {{ $payout->status === 'paid' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($payout->status === 'failed' ? 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400' : 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400') }}">
                                                {{ \App\Models\Payout::STATUS_LABELS[$payout->status] ?? $payout->status }}
                                            </span>
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
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                        <div>
                            <div class="text-neutral-900 dark:text-white font-semibold">{{ $invoice->invoice_no ?: 'Numara bekleniyor' }}</div>
                            <div class="text-[11px] text-neutral-500">{{ $invoice->invoice_type }} · {{ $invoice->issued_at?->format('d.m.Y H:i') ?? $invoice->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format((float) ($invoice->total_amount ?? 0), 2, ',', '.') }} ₺ · {{ $invoice->status }}</div>
                    </div>
                @empty
                    <div class="text-xs text-neutral-500">Henüz adınıza kesilmiş fatura yok.</div>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
                <h3 class="section-title">Ödeme alacağınız hesap</h3>

                @if($bankAccount)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="text-neutral-900 dark:text-white font-mono font-bold">{{ $bankAccount->maskedIban() }}</div>
                        <div class="text-neutral-500 dark:text-neutral-400">{{ $bankAccount->account_holder }}</div>
                        <div class="text-[11px] text-neutral-500">{{ $bankAccount->is_verified ? 'Doğrulandı' : 'Finans ekibi ilk transferde doğrular' }}</div>
                    </div>
                @else
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300">Kayıtlı IBAN adresiniz yok. Ödemelerinizin yapılabilmesi için IBAN ekleyin.</div>
                @endif

                <form wire:submit.prevent="saveBankAccount" class="space-y-3 border-t border-neutral-200 dark:border-neutral-800 pt-4">
                    <div>
                        <label class="form-label">{{ $bankAccount ? 'Yeni IBAN' : 'IBAN' }}</label>
                        <input type="text" wire:model="iban" placeholder="TR00 0000 0000 0000 0000 0000 00" class="form-input font-mono">
                        @error('iban') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="form-label">Hesap sahibi</label>
                        <input type="text" wire:model="account_holder" class="form-input">
                        @error('account_holder') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveBankAccount">IBAN kaydet</span>
                        <span wire:loading wire:target="saveBankAccount">Kaydediliyor...</span>
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-2 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                <h3 class="section-title">Ödeme süreci</h3>
                <p>Yük sahibi teslimatı onayladığında ödemeniz platform hizmet bedeli düşülerek hesabınıza geçer.</p>
                <p>Transfer, finans ekibi tarafından kayıtlı IBAN adresinize yapılır; ödeme tamamlandığında referans numarası bu sayfada görünür.</p>
            </div>
        </div>
    </div>
</div>
