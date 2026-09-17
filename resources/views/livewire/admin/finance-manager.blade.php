<?php

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Services\BankAccountService;
use App\Services\LedgerService;
use App\Services\PayoutService;
use App\Support\Settings;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $activeTab = 'payouts';

    public string $payoutStatus = 'pending';

    public string $orderStatus = 'all';

    /** @var array<int, string> Hakediş kimliğine göre banka referansı */
    public array $reference = [];

    /** @var array<int, string> Hakediş kimliğine göre başarısızlık nedeni */
    public array $failReason = [];

    /** @var array<int, string> Banka hesabı kimliğine göre çözülmüş IBAN */
    #[\Livewire\Attributes\Locked]
    public ?int $revealedAccountId = null;

    public string $exportMonth = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view financials'), 403);
        $this->exportMonth = now()->format('Y-m');
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->revealedAccountId = null;
    }

    public function updatedPayoutStatus(): void
    {
        $this->resetPage();
    }

    public function updatedOrderStatus(): void
    {
        $this->resetPage();
    }

    private function requireManage(): bool
    {
        if (auth()->user()->can('manage payouts')) {
            return true;
        }
        session()->flash('error_message', 'Bu işlem için "manage payouts" izni gerekir.');

        return false;
    }

    public function revealIban(int $bankAccountId): void
    {
        if (! $this->requireManage()) {
            return;
        }

        $account = BankAccount::query()->find($bankAccountId);
        if (! $account) {
            session()->flash('error_message', 'Banka hesabı bulunamadı.');

            return;
        }

        try {
            app(BankAccountService::class)->decrypt($account);
            $this->revealedAccountId = $account->id;
            ActivityLog::record('bank_account.revealed', "IBAN görüntülendi (hesap #{$account->id}, kullanıcı #{$account->user_id})", auth()->id(), $account);
        } catch (\Throwable) {
            session()->flash('error_message', 'IBAN çözülemedi; şifreleme anahtarı değişmiş olabilir.');
        }
    }

    public function hideIban(int $bankAccountId): void
    {
        if ($this->revealedAccountId === $bankAccountId) {
            $this->revealedAccountId = null;
        }
    }

    public function markPaid(int $payoutId): void
    {
        if (! $this->requireManage()) {
            return;
        }

        $reference = trim((string) ($this->reference[$payoutId] ?? ''));
        if (mb_strlen($reference) < 3) {
            $this->addError('reference.'.$payoutId, 'Banka transfer referansı zorunludur.');

            return;
        }

        $payout = Payout::query()->find($payoutId);
        if (! $payout) {
            session()->flash('error_message', 'Hakediş bulunamadı.');

            return;
        }

        try {
            app(PayoutService::class)->markPaid($payout, auth()->user(), $reference);
            unset($this->reference[$payoutId]);
            session()->flash('success_message', "Hakediş #{$payoutId} ödendi olarak işaretlendi.");
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    public function markFailed(int $payoutId): void
    {
        if (! $this->requireManage()) {
            return;
        }

        $reason = trim((string) ($this->failReason[$payoutId] ?? ''));
        if (mb_strlen($reason) < 5) {
            $this->addError('failReason.'.$payoutId, 'Başarısızlık nedeni en az 5 karakter olmalıdır.');

            return;
        }

        $payout = Payout::query()->find($payoutId);
        if (! $payout || ! in_array($payout->status, ['pending', 'processing'], true)) {
            session()->flash('error_message', 'Yalnız bekleyen hakedişler başarısız olarak işaretlenebilir.');

            return;
        }

        app(PayoutService::class)->markFailed($payout, auth()->user(), $reason);
        unset($this->failReason[$payoutId]);
        session()->flash('success_message', "Hakediş #{$payoutId} başarısız olarak işaretlendi.");
    }

    public function exportCsv()
    {
        if (! $this->requireManage()) {
            return null;
        }

        $this->validate(['exportMonth' => 'required|date_format:Y-m']);

        $start = \Carbon\Carbon::createFromFormat('Y-m', $this->exportMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = Payout::query()->with(['user', 'bankAccount', 'cargoLoad'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();

        ActivityLog::record('payout.exported', "Hakediş CSV dışa aktarıldı ({$this->exportMonth}, {$rows->count()} satır)", auth()->id());

        $filename = 'hakedisler-'.$this->exportMonth.'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Hakediş No', 'İlan No', 'Şoför', 'E-posta', 'IBAN (maskeli)', 'Brüt', 'Komisyon', 'Net', 'Durum', 'Referans', 'Oluşturma', 'Ödeme'], ';');
            foreach ($rows as $payout) {
                fputcsv($out, [
                    $payout->id,
                    $payout->load_id,
                    $payout->user?->full_name,
                    $payout->user?->email,
                    $payout->bankAccount?->maskedIban() ?? '',
                    number_format((float) $payout->total_amount, 2, ',', ''),
                    number_format((float) $payout->commission_amount, 2, ',', ''),
                    number_format((float) $payout->net_amount, 2, ',', ''),
                    Payout::STATUS_LABELS[$payout->status] ?? $payout->status,
                    $payout->reference_no,
                    $payout->created_at?->format('d.m.Y H:i'),
                    $payout->paid_at?->format('d.m.Y H:i'),
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function with(): array
    {
        $data = [
            'canManage' => auth()->user()->can('manage payouts'),
            'rates' => [
                'Standart şoför komisyonu' => Settings::float('commission_standard_driver'),
                'Yük sahibi hizmet bedeli' => Settings::float('commission_cargo_owner'),
            ],
            'payouts' => null,
            'orders' => null,
            'invoices' => null,
            'ledger' => [],
        ];

        if ($this->activeTab === 'payouts') {
            $query = Payout::query()->with(['user', 'bankAccount', 'cargoLoad']);
            if ($this->payoutStatus !== 'all') {
                $query->where('status', $this->payoutStatus);
            }
            $data['payouts'] = $query->orderBy('id')->paginate(15);
        } elseif ($this->activeTab === 'orders') {
            $query = PaymentOrder::query()->with(['user', 'cargoLoad']);
            if ($this->orderStatus !== 'all') {
                $query->where('status', $this->orderStatus);
            }
            $data['orders'] = $query->latest('id')->paginate(15);
        } elseif ($this->activeTab === 'ledger') {
            $ledger = app(LedgerService::class);
            foreach (LedgerService::ACCOUNTS as $code => [$name, $type]) {
                $balance = $ledger->balance($code);
                $data['ledger'][] = [
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'balance' => in_array($type, ['liability', 'revenue'], true) ? -$balance : $balance,
                ];
            }
        } elseif ($this->activeTab === 'invoices') {
            $data['invoices'] = Invoice::query()->with('user')->latest('id')->paginate(15);
        }

        return $data;
    }
}; ?>

<div wire:poll.10s class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['payouts' => 'Hakedişler', 'orders' => 'Ödeme emirleri', 'ledger' => 'Muhasebe bakiyeleri', 'invoices' => 'Faturalar'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Finans ve Muhasebe</h1>
            <p class="page-subtitle">Hakediş ödemeleri banka transferi sonrasında elle işaretlenir; havuz bakiyesi yalnız ödeme bildirimi ve uyuşmazlık kararıyla değişir.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-[11px]">
            @foreach($rates as $label => $rate)
                <span class="px-3 py-1.5 rounded-full bg-neutral-500/10 text-neutral-600 dark:text-neutral-300">{{ $label }}: %{{ number_format($rate, 2, ',', '.') }}</span>
            @endforeach
        </div>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl overflow-x-auto">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')" class="flex-1 whitespace-nowrap px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === $key ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($activeTab === 'payouts')
        <div class="flex flex-col sm:flex-row gap-3 apple-glass p-3 rounded-2xl">
            <select wire:model.live="payoutStatus" class="{{ $input }} sm:w-56">
                <option value="pending">Ödeme sırasında</option>
                <option value="processing">Transfer yapılıyor</option>
                <option value="failed">Başarısız</option>
                <option value="paid">Ödendi</option>
                <option value="all">Tümü</option>
            </select>
            <form wire:submit="exportCsv" class="flex gap-2 sm:ml-auto">
                <input type="month" wire:model="exportMonth" class="{{ $input }} sm:w-44">
                <button type="submit" class="btn-apple-secondary py-2 px-4 text-[11px] whitespace-nowrap">CSV indir</button>
            </form>
        </div>
        @error('exportMonth') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror

        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Hakediş</th>
                            <th class="p-4">Şoför</th>
                            <th class="p-4">IBAN</th>
                            <th class="p-4">Tutar</th>
                            <th class="p-4">Durum</th>
                            <th class="p-4">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($payouts as $payout)
                            <tr class="align-top">
                                <td class="p-4 font-bold">#{{ $payout->id }}<div class="text-[11px] font-normal text-neutral-400">İlan #{{ $payout->load_id }} · {{ $payout->created_at?->format('d.m.Y H:i') }}</div></td>
                                <td class="p-4">{{ $payout->user?->full_name ?? '—' }}<div class="text-[11px] text-neutral-400">{{ $payout->user?->email }}</div></td>
                                <td class="p-4 whitespace-nowrap">
                                    @if($payout->bankAccount)
                                        <span class="font-mono">{{ $revealedAccountId === $payout->bank_account_id && $canManage ? app(\App\Services\BankAccountService::class)->decrypt($payout->bankAccount) : $payout->bankAccount->maskedIban() }}</span>
                                        <div class="text-[11px] text-neutral-400">{{ $payout->bankAccount->account_holder }}</div>
                                        @if($canManage)
                                            @if($revealedAccountId === $payout->bank_account_id)
                                                <button type="button" wire:click="hideIban({{ $payout->bank_account_id }})" class="text-[11px] text-brand-500 font-semibold">Gizle</button>
                                            @else
                                                <button type="button" wire:click="revealIban({{ $payout->bank_account_id }})" class="text-[11px] text-brand-500 font-semibold">IBAN'ı göster</button>
                                            @endif
                                        @endif
                                    @else
                                        <span class="text-amber-600">Banka hesabı tanımlı değil</span>
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <div class="font-semibold">{{ number_format((float) $payout->net_amount, 2, ',', '.') }} ₺</div>
                                    <div class="text-[11px] text-neutral-400">Brüt {{ number_format((float) $payout->total_amount, 2, ',', '.') }} ₺ · Kom. {{ number_format((float) $payout->commission_amount, 2, ',', '.') }} ₺</div>
                                </td>
                                <td class="p-4">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $payout->status === 'paid' ? 'bg-emerald-500/10 text-emerald-600' : ($payout->status === 'failed' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ \App\Models\Payout::STATUS_LABELS[$payout->status] ?? $payout->status }}</span>
                                    @if($payout->reference_no)<div class="text-[11px] text-neutral-400 mt-1">{{ $payout->reference_no }}</div>@endif
                                    @if($payout->paid_at)<div class="text-[11px] text-neutral-400">{{ $payout->paid_at->format('d.m.Y H:i') }}</div>@endif
                                </td>
                                <td class="p-4 min-w-[14rem]">
                                    @if($canManage && in_array($payout->status, ['pending', 'processing', 'failed'], true))
                                        <div class="space-y-2">
                                            <input type="text" wire:model="reference.{{ $payout->id }}" placeholder="Banka referans no" class="{{ $input }}">
                                            @error('reference.'.$payout->id) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                                            <button type="button" wire:click="markPaid({{ $payout->id }})" wire:confirm="Hakediş ödendi olarak işaretlenecek. Banka transferi tamamlandı mı?" wire:loading.attr="disabled" class="w-full py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold">Ödendi olarak işaretle</button>
                                            @if($payout->status !== 'failed')
                                                <input type="text" wire:model="failReason.{{ $payout->id }}" placeholder="Başarısızlık nedeni" class="{{ $input }}">
                                                @error('failReason.'.$payout->id) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                                                <button type="button" wire:click="markFailed({{ $payout->id }})" wire:loading.attr="disabled" class="w-full py-2 rounded-xl border border-red-300 text-red-600 text-[11px] font-semibold">Başarısız</button>
                                            @endif
                                        </div>
                                    @elseif(! $canManage)
                                        <span class="text-[11px] text-neutral-400">Yalnız görüntüleme</span>
                                    @else
                                        <span class="text-[11px] text-neutral-400">Sonuçlandı</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Bu durumda hakediş yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $payouts->links() }}</div>
        </div>
    @endif

    @if($activeTab === 'orders')
        <div class="apple-glass p-3 rounded-2xl">
            <select wire:model.live="orderStatus" class="{{ $input }} sm:w-56">
                <option value="all">Tüm durumlar</option>
                @foreach(['created' => 'Oluşturuldu', 'pending' => 'Ödeme bekleniyor', 'paid' => 'Ödendi', 'failed' => 'Başarısız', 'refund_pending' => 'İade bekliyor', 'refunded' => 'İade edildi'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Sipariş</th>
                            <th class="p-4">İlan</th>
                            <th class="p-4">Ödeyen</th>
                            <th class="p-4">Tutar</th>
                            <th class="p-4">Durum</th>
                            <th class="p-4">Tarih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($orders as $order)
                            <tr>
                                <td class="p-4 font-mono">{{ $order->merchant_oid }}<div class="text-[11px] text-neutral-400 font-sans">{{ $order->provider }} · {{ $order->purpose }}</div></td>
                                <td class="p-4">#{{ $order->load_id }}<div class="text-[11px] text-neutral-400">{{ $order->cargoLoad?->pickup_location }} → {{ $order->cargoLoad?->delivery_location }}</div></td>
                                <td class="p-4">{{ $order->user?->full_name ?? '—' }}</td>
                                <td class="p-4 whitespace-nowrap font-semibold">{{ number_format((float) $order->amount, 2, ',', '.') }} ₺<div class="text-[11px] font-normal text-neutral-400">Hizmet bedeli {{ number_format((float) $order->service_fee_amount, 2, ',', '.') }} ₺</div></td>
                                <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $order->status === 'paid' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500' }}">{{ $order->status }}</span></td>
                                <td class="p-4 whitespace-nowrap text-neutral-500">{{ ($order->paid_at ?? $order->created_at)?->format('d.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Ödeme emri yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $orders->links() }}</div>
        </div>
    @endif

    @if($activeTab === 'ledger')
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($ledger as $account)
                <div class="apple-glass rounded-2xl p-5">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">{{ $account['name'] }}</span>
                    <p class="mt-2 text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($account['balance'], 2, ',', '.') }} ₺</p>
                    <p class="text-[11px] text-neutral-400 mt-1 font-mono">{{ $account['code'] }} · {{ $account['type'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-neutral-400">Varlık ve gider hesapları borç bakiyesi, borç ve gelir hesapları alacak bakiyesi olarak gösterilir. Kayıt bulunmayan hesaplar 0,00 ₺ görünür.</p>
    @endif

    @if($activeTab === 'invoices')
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Fatura no</th>
                            <th class="p-4">Tür</th>
                            <th class="p-4">Kullanıcı</th>
                            <th class="p-4">Tutar</th>
                            <th class="p-4">Durum</th>
                            <th class="p-4">Tarih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($invoices as $invoice)
                            <tr>
                                <td class="p-4 font-mono">{{ $invoice->invoice_no ?: '—' }}</td>
                                <td class="p-4">{{ $invoice->invoice_type }}</td>
                                <td class="p-4">{{ $invoice->user?->full_name ?? '—' }}</td>
                                <td class="p-4 whitespace-nowrap font-semibold">{{ number_format((float) $invoice->total_amount, 2, ',', '.') }} ₺<div class="text-[11px] font-normal text-neutral-400">KDV {{ number_format((float) $invoice->tax_amount, 2, ',', '.') }} ₺</div></td>
                                <td class="p-4">{{ $invoice->status }}</td>
                                <td class="p-4 whitespace-nowrap text-neutral-500">{{ ($invoice->issued_at ?? $invoice->created_at)?->format('d.m.Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Henüz fatura kaydı yok; e-fatura entegrasyonu bu sürümde etkin değil.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $invoices->links() }}</div>
        </div>
    @endif
</div>
