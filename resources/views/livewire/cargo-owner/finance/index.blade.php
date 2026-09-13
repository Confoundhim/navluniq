<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Finans, Güvenli Havuz & Faturalar')]
class extends Component {
    public float $escrowBalance = 0.0;
    public float $totalPaid = 0.0;

    public function mount(): void
    {
        $user = Auth::user();
        if ($user && $user->cargoOwnerProfile) {
            $profileId = (int) $user->cargoOwnerProfile->id;

            $this->escrowBalance = (float) Load::where('cargo_owner_profile_id', $profileId)
                ->where('escrow_status', 'paid_in_escrow')
                ->sum('price');

            $this->totalPaid = (float) Load::where('cargo_owner_profile_id', $profileId)
                ->where('escrow_status', 'released_to_driver')
                ->sum('price');
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $invoices = collect();

        if ($user) {
            $invoices = Invoice::where('user_id', $user->id)
                ->latest()
                ->get();
        }

        return [
            'invoices' => $invoices,
        ];
    }
}; ?>

<div class="space-y-6">

    <!-- Finansal Başlık ve Sayaçlar -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5">
            <span class="text-xs font-semibold text-neutral-400 block mb-2">PayTR Havuzunda (Bloke) Tutar</span>
            <div class="text-3xl font-extrabold text-brand-400 font-mono">
                {{ number_format($escrowBalance, 2, ',', '.') }} <span class="text-lg text-white">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 mt-2 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                <span>Teslimat onayında şoföre aktarılacak</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5">
            <span class="text-xs font-semibold text-neutral-400 block mb-2">Tamamlanan Toplam Ödemeler</span>
            <div class="text-3xl font-extrabold text-white font-mono">
                {{ number_format($totalPaid, 2, ',', '.') }} <span class="text-lg text-emerald-400">₺</span>
            </div>
            <div class="text-[11px] text-emerald-400 mt-2 flex items-center gap-1.5">
                <span>✓ Başarılı teslimatlar toplamı</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 flex flex-col justify-between">
            <div>
                <span class="text-xs font-semibold text-neutral-400 block mb-1">e-Arşiv Fatura Durumu</span>
                <div class="text-xs text-neutral-300">Tüm işlemleriniz KDV dahil resmi e-Arşiv faturalandırılır.</div>
            </div>
            <div class="text-[11px] text-neutral-500 pt-2 border-t border-neutral-800 font-mono">
                GİB & e-Fatura Entegre
            </div>
        </div>

    </div>

    <!-- e-Arşiv Faturalar Tablosu -->
    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden">
        <div class="p-5 border-b border-neutral-800 flex items-center justify-between">
            <h3 class="text-sm font-bold text-white tracking-tight">e-Arşiv Faturalarım ve Ödeme Dekontları</h3>
            <span class="text-xs text-neutral-400">Son İşlemler</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-neutral-950 text-neutral-400 uppercase tracking-wider text-[10px] border-b border-neutral-800">
                    <tr>
                        <th class="py-3.5 px-5">Fatura No</th>
                        <th class="py-3.5 px-5">Tür</th>
                        <th class="py-3.5 px-5">Tarih</th>
                        <th class="py-3.5 px-5">Matrah</th>
                        <th class="py-3.5 px-5">KDV (%20)</th>
                        <th class="py-3.5 px-5 text-right">Toplam Tutar</th>
                        <th class="py-3.5 px-5 text-center">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800 text-neutral-300">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-neutral-800/40 transition-colors">
                            <td class="py-4 px-5 font-mono font-bold text-white">{{ $invoice->invoice_no }}</td>
                            <td class="py-4 px-5">
                                <span class="px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-400 text-[10px] font-bold">
                                    {{ $invoice->invoice_type === 'commission' ? 'Navlun & Hizmet' : 'Abonelik' }}
                                </span>
                            </td>
                            <td class="py-4 px-5 text-neutral-400">{{ $invoice->issued_at?->format('d.m.Y H:i') }}</td>
                            <td class="py-4 px-5 font-mono">{{ number_format((float)$invoice->base_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono text-neutral-400">{{ number_format((float)$invoice->tax_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono font-bold text-white text-right">{{ number_format((float)$invoice->total_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 text-center">
                                <button type="button" onclick="window.print()" class="px-3 py-1.5 rounded-lg bg-neutral-800 hover:bg-neutral-700 text-white font-medium text-[11px] transition-colors inline-flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span>PDF İndir</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-neutral-500">
                                Henüz kesilmiş bir e-arşiv faturanız bulunmuyor.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
