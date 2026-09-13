<?php

use App\Models\Invoice;
use App\Models\Load;
use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Finans, Güvenli Havuz & Faturalar')]
class extends Component {
    use WithPagination;

    public function with(): array
    {
        $user = Auth::user();
        $profileId = (int) $user->cargoOwnerProfile?->id;

        $inEscrow = (float) Load::query()
            ->where('cargo_owner_profile_id', $profileId)
            ->whereIn('escrow_status', [Load::ESCROW_PAID, Load::ESCROW_ON_HOLD, Load::ESCROW_RELEASE_APPROVED])
            ->sum('price');

        $paidOrders = PaymentOrder::query()->where('user_id', $user->id)->where('status', 'paid');
        $totalPaid = (float) (clone $paidOrders)->sum('amount');

        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthly[now()->subMonths($i)->format('Y-m')] = 0.0;
        }
        (clone $paidOrders)
            ->where('paid_at', '>=', now()->subMonths(5)->startOfMonth())
            ->get(['amount', 'paid_at'])
            ->each(function (PaymentOrder $order) use (&$monthly): void {
                $key = $order->paid_at?->format('Y-m');
                if ($key !== null && array_key_exists($key, $monthly)) {
                    $monthly[$key] += (float) $order->amount;
                }
            });
        $monthlyMax = max(1.0, max($monthly ?: [0.0]));

        return [
            'inEscrow' => $inEscrow,
            'totalPaid' => $totalPaid,
            'monthly' => $monthly,
            'monthlyMax' => $monthlyMax,
            'orders' => PaymentOrder::query()->with('cargoLoad')->where('user_id', $user->id)->latest()->paginate(15),
            'invoices' => Invoice::query()->where('user_id', $user->id)->latest()->take(50)->get(),
            'orderStatusLabels' => [
                'created' => 'Oluşturuldu',
                'pending' => 'Ödeme bekleniyor',
                'paid' => 'Ödendi',
                'failed' => 'Başarısız',
                'refund_pending' => 'İade bekleniyor',
                'refunded' => 'İade edildi',
            ],
            'invoiceStatusLabels' => [
                'pending' => 'Hazırlanıyor',
                'issued' => 'Kesildi',
                'failed' => 'Kesilemedi',
                'cancelled' => 'İptal edildi',
            ],
            'monthNames' => [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'],
        ];
    }
}; ?>

<div class="space-y-6">

    <div class="border-b border-neutral-800 pb-4">
        <h2 class="text-xl font-bold text-white tracking-tight">Finans, güvenli havuz ve faturalar</h2>
        <p class="text-xs text-neutral-400 mt-1">Havuzda bloke tutarlar, ödeme geçmişiniz ve adınıza kesilen faturalar.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5">
            <span class="text-xs font-semibold text-neutral-400 block mb-2">Güvenli havuzda bloke</span>
            <div class="text-3xl font-extrabold text-brand-400 font-mono">
                {{ number_format($inEscrow, 2, ',', '.') }} <span class="text-lg text-white">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 mt-2">Teslimat onayına kadar tutulan navlun bedelleri</div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5">
            <span class="text-xs font-semibold text-neutral-400 block mb-2">Toplam ödenen</span>
            <div class="text-3xl font-extrabold text-white font-mono">
                {{ number_format($totalPaid, 2, ',', '.') }} <span class="text-lg text-emerald-400">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 mt-2">Sağlayıcı bildirimiyle doğrulanmış ödemeler</div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 sm:col-span-2 lg:col-span-1">
            <span class="text-xs font-semibold text-neutral-400 block mb-3">Aylık harcama (son 6 ay)</span>
            <div class="flex items-end gap-2 h-20">
                @foreach($monthly as $month => $amount)
                    @php [$year, $mon] = explode('-', $month); @endphp
                    <div class="flex-1 flex flex-col items-center gap-1 min-w-0" title="{{ $monthNames[(int) $mon] }} {{ $year }}: {{ number_format($amount, 2, ',', '.') }} ₺">
                        <div class="w-full rounded-t-md {{ $amount > 0 ? 'bg-brand-500' : 'bg-neutral-800' }}" style="height: {{ max(4, (int) round($amount / $monthlyMax * 56)) }}px"></div>
                        <span class="text-[10px] text-neutral-500">{{ $monthNames[(int) $mon] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

    </div>

    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden">
        <div class="p-5 border-b border-neutral-800 flex items-center justify-between">
            <h3 class="text-sm font-bold text-white tracking-tight">Ödeme geçmişi</h3>
            <span class="text-xs text-neutral-400">{{ $orders->total() }} kayıt</span>
        </div>

        <div class="responsive-scroll">
            <table class="w-full text-left text-xs">
                <thead class="bg-neutral-950 text-neutral-400 uppercase tracking-wider text-[10px] border-b border-neutral-800">
                    <tr>
                        <th class="py-3.5 px-5">Sipariş</th>
                        <th class="py-3.5 px-5">İlan</th>
                        <th class="py-3.5 px-5">Tarih</th>
                        <th class="py-3.5 px-5">Durum</th>
                        <th class="py-3.5 px-5 text-right">Tutar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800 text-neutral-300">
                    @forelse($orders as $order)
                        <tr class="hover:bg-neutral-800/40 transition-colors">
                            <td class="py-4 px-5 font-mono text-white">{{ $order->merchant_oid }}</td>
                            <td class="py-4 px-5">
                                @if($order->cargoLoad)
                                    <a href="{{ route('cargo-owner.shipments.show', $order->cargoLoad->id) }}" wire:navigate class="text-neutral-200 hover:text-brand-400">#{{ $order->cargoLoad->id }} · {{ $order->cargoLoad->pickup_location }} &rarr; {{ $order->cargoLoad->delivery_location }}</a>
                                @else
                                    <span class="text-neutral-500">—</span>
                                @endif
                            </td>
                            <td class="py-4 px-5 text-neutral-400 whitespace-nowrap">{{ ($order->paid_at ?? $order->created_at)?->format('d.m.Y H:i') }}</td>
                            <td class="py-4 px-5">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border
                                    {{ $order->status === 'paid' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : (in_array($order->status, ['failed'], true) ? 'bg-rose-500/10 border-rose-500/20 text-rose-400' : 'bg-neutral-800 border-neutral-700 text-neutral-300') }}">
                                    {{ $orderStatusLabels[$order->status] ?? $order->status }}
                                </span>
                            </td>
                            <td class="py-4 px-5 font-mono font-bold text-white text-right whitespace-nowrap">{{ number_format((float) $order->amount, 2, ',', '.') }} ₺</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-neutral-500">Henüz ödeme kaydınız yok.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
            <div class="p-4 border-t border-neutral-800 text-xs">{{ $orders->links() }}</div>
        @endif
    </div>

    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden">
        <div class="p-5 border-b border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-white tracking-tight">Faturalar</h3>
            @if($invoices->isNotEmpty())
                <button type="button" onclick="window.print()" class="px-3 py-1.5 rounded-lg bg-neutral-800 hover:bg-neutral-700 text-white font-medium text-[11px] transition-colors inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    <span>Yazdır</span>
                </button>
            @endif
        </div>

        <div class="responsive-scroll">
            <table class="w-full text-left text-xs">
                <thead class="bg-neutral-950 text-neutral-400 uppercase tracking-wider text-[10px] border-b border-neutral-800">
                    <tr>
                        <th class="py-3.5 px-5">Fatura no</th>
                        <th class="py-3.5 px-5">Tür</th>
                        <th class="py-3.5 px-5">Tarih</th>
                        <th class="py-3.5 px-5">Durum</th>
                        <th class="py-3.5 px-5">Matrah</th>
                        <th class="py-3.5 px-5">KDV</th>
                        <th class="py-3.5 px-5 text-right">Toplam</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800 text-neutral-300">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-neutral-800/40 transition-colors">
                            <td class="py-4 px-5 font-mono font-bold text-white">{{ $invoice->invoice_no ?: '—' }}</td>
                            <td class="py-4 px-5">
                                <span class="px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-400 text-[10px] font-bold">
                                    {{ ['commission' => 'Hizmet bedeli', 'subscription' => 'Abonelik', 'escrow' => 'Navlun'][$invoice->invoice_type] ?? $invoice->invoice_type }}
                                </span>
                            </td>
                            <td class="py-4 px-5 text-neutral-400 whitespace-nowrap">{{ ($invoice->issued_at ?? $invoice->created_at)?->format('d.m.Y H:i') }}</td>
                            <td class="py-4 px-5">{{ $invoiceStatusLabels[$invoice->status] ?? $invoice->status }}</td>
                            <td class="py-4 px-5 font-mono whitespace-nowrap">{{ number_format((float) $invoice->base_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono text-neutral-400 whitespace-nowrap">{{ number_format((float) $invoice->tax_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono font-bold text-white text-right whitespace-nowrap">{{ number_format((float) $invoice->total_amount, 2, ',', '.') }} ₺</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-neutral-500">Henüz adınıza kesilmiş fatura yok.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
