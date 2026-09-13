<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\Load;
use App\Models\Payout;
use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    // Aktif Sekme Yönetimi
    public string $activeTab = 'escrow'; // 'escrow' (bloke havuz), 'payouts' (hak edişler), 'invoices' (faturalar)

    public function mount()
    {
        if (!auth()->user()->can('view financials')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * Mali Müşavirler İçin Excel/Google Sheets Uyumlu Aylık Finans Raporu İndirir (CSV)
     */
    public function exportMaliRapor()
    {
        $headers = [
            "Content-type" => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=NavlunIQ_Aylik_Mali_Rapor_" . now()->format('Y-m') . ".csv",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $invoices = Invoice::with('user')->get();

        $callback = function() use ($invoices) {
            $file = fopen('php://output', 'w');

            // Excel UTF-8 karakter desteği (BOM ekleme)
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Sütun Başlıkları
            fputcsv($file, ['Fatura No', 'Fatura Tipi', 'Kullanıcı', 'Tarih', 'Matrah (TL)', 'KDV Tutar (TL)', 'KDV Dahil Toplam (TL)', 'Fatura Durumu']);

            foreach ($invoices as $inv) {
                fputcsv($file, [
                    $inv->invoice_no,
                    $inv->invoice_type === 'commission' ? 'Platform Komisyonu' : 'Aylık Premium Üyelik',
                    $inv->user->full_name ?? 'Bilinmeyen Kullanıcı',
                    $inv->issued_at->format('Y-m-d H:i'),
                    number_format($inv->base_amount, 2, ',', ''),
                    number_format($inv->tax_amount, 2, ',', ''),
                    number_format($inv->total_amount, 2, ',', ''),
                    $inv->status === 'issued' ? 'Kesildi / Aktif' : 'İptal'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Üst mali metrik kart hesaplamaları
     */
    private function getMaliKartlar()
    {
        return [
            'escrow_pending' => Load::where('escrow_status', 'paid_in_escrow')->sum('price'),
            'total_commission' => Invoice::where('invoice_type', 'commission')->sum('total_amount'),
            'total_subscription' => Invoice::where('invoice_type', 'subscription')->sum('total_amount'),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in" wire:poll.3s>
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Aksiyon Butonları -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Finans ve Muhasebe Yöneticisi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Sistem üzerindeki bloke havuz bakiyelerini, hak ediş transferlerini ve faturaları yönetin.</p>
        </div>

        <div class="flex items-center space-x-3 w-full md:w-auto">
            <!-- Mali Müşavir Rapor Butonu -->
            @if(\App\Models\Invoice::count() > 0)
                <button wire:click="exportMaliRapor" class="btn-apple-secondary py-2.5 px-4 text-xs font-semibold flex items-center space-x-2 w-full md:w-auto justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Mali Müşavir Raporu İndir (Excel/CSV)</span>
                </button>
            @endif

            <!-- Test Verisi Üretici -->
            @if(\App\Models\Invoice::count() === 0)
@endif
        </div>
    </div>

    <!-- Üst Mali Metrik Kartları (Apple Tarzı) -->
    @php $kartlar = $this->getMaliKartlar(); @endphp
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Kart 1: Havuzda Bekleyen Bloke Tutar (Escrow) -->
        <div class="apple-glass rounded-3xl p-6 space-y-2 relative overflow-hidden">
            <span class="text-xs text-neutral-400 font-semibold uppercase tracking-wider block">HAVUZDA BEKLEYEN BAKİYE (ESCROW)</span>
            <div class="text-2xl font-bold text-neutral-900 dark:text-white">
                <!-- KESİN ÇÖZÜM: TL Simgesi yerine W3C HTML Entity kodunu enjekte ediyoruz -->
                &#8378;{{ number_format($kartlar['escrow_pending'], 2) }}
            </div>
            <p class="text-[11px] text-neutral-400">Sevkiyatı süren ve güvence altında tutulan bloke navlun bedelleri.</p>
        </div>

        <!-- Kart 2: Kazanılan Toplam Platform Komisyonu (%5 Platform Fee) -->
        <div class="apple-glass rounded-3xl p-6 space-y-2 relative overflow-hidden">
            <span class="text-xs text-neutral-400 font-semibold uppercase tracking-wider block">KAZANILAN NET KOMİSYON (%5)</span>
            <div class="text-2xl font-bold text-brand-500">
                <!-- KESİN ÇÖZÜM: TL Simgesi yerine W3C HTML Entity kodunu enjekte ediyoruz -->
                &#8378;{{ number_format($kartlar['total_commission'], 2) }}
            </div>
            <p class="text-[11px] text-neutral-400">Başarıyla biten sevkiyatların kesilen komisyon faturaları toplamı.</p>
        </div>

        <!-- Kart 3: Aylık Toplam Premium Üyelik Gelirleri (900 TL) -->
        <div class="apple-glass rounded-3xl p-6 space-y-2 relative overflow-hidden">
            <span class="text-xs text-neutral-400 font-semibold uppercase tracking-wider block">PREMİUM ABONELİK GELİRLERİ</span>
            <div class="text-2xl font-bold text-emerald-500">
                <!-- KESİN ÇÖZÜM: TL Simgesi yerine W3C HTML Entity kodunu enjekte ediyoruz -->
                &#8378;{{ number_format($kartlar['total_subscription'], 2) }}
            </div>
            <p class="text-[11px] text-neutral-400">Şoförler tarafından satın alınan aylık Premium üyelik faturaları toplamı.</p>
        </div>
    </div>

    <!-- Filtre ve Seçim Segmentleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm">
        <button wire:click="$set('activeTab', 'escrow')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'escrow' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Bloke Havuz Ödemeleri (Escrow)
        </button>
        <button wire:click="$set('activeTab', 'payouts')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'payouts' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Şoför Hak Edişleri (Payouts)
        </button>
        <button wire:click="$set('activeTab', 'invoices')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'invoices' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Düzenlenen E-Arşiv Faturalar
        </button>
    </div>

    <!-- Listeleme Tabloları -->
    <div class="apple-glass rounded-3xl overflow-hidden">

        @if($activeTab === 'escrow')
            <!-- TABLO 1: BLOKE HAVUZ ÖDEMELERİ (ESCROW) -->
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                        <th class="p-5">Yük Sahibi (Gönderici)</th>
                        <th class="p-5">Güzergah</th>
                        <th class="p-5">Fatura Tutarı (KDV Dahil)</th>
                        <th class="p-5">Sevkiyat Durumu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse(\App\Models\Load::with('cargoOwnerProfile.user')->whereIn('escrow_status', ['paid_in_escrow', 'pending_payment'])->latest()->get() as $load)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="p-5">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $load->cargoOwnerProfile->user->full_name ?? 'Bilinmeyen Kullanıcı' }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">VKN/TC: {{ $load->cargoOwnerProfile->tax_no ?? $load->cargoOwnerProfile->tc_no }}</div>
                            </td>
                            <td class="p-5">
                                <div class="font-semibold">{{ $load->pickup_location }} -> {{ $load->delivery_location }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">{{ $load->goods_type }}</div>
                            </td>
                            <td class="p-5">
                                <div class="font-bold">&#8378;{{ number_format($load->price, 2) }}</div>
                                <div class="text-[10px] font-semibold text-emerald-500 mt-0.5">PayTR Güvencesinde Bloke</div>
                            </td>
                            <td class="p-5">
                                <span class="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-blue-500/10 text-blue-600">
                                    {{ $load->status === 'on_the_way' ? 'Yolda' : 'Sürücü Atandı' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-12 text-center text-neutral-400">Havuzda aktif bloke bakiye içeren bir yükleme bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        @elseif($activeTab === 'payouts')
            <!-- TABLO 2: ŞOFÖR HAK EDİŞLERİ (PAYOUTS) -->
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                        <th class="p-5">Hak Sahibi Şoför</th>
                        <th class="p-5">IBAN / Banka Bilgisi</th>
                        <th class="p-5">Mali Dağılım (%5 Komisyon Kesintili)</th>
                        <th class="p-5 text-right">İşlem / Dekont</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse(\App\Models\Payout::with('user')->latest()->get() as $pay)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="p-5">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $pay->user->full_name ?? 'Şoför' }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">Tel: {{ $pay->user->phone }}</div>
                            </td>
                            <td class="p-5">
                                <div class="font-semibold font-mono tracking-wider">{{ $pay->iban }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">{{ $pay->bank_name }}</div>
                            </td>
                            <td class="p-5">
                                <div class="grid grid-cols-1 gap-0.5">
                                    <div>Navlun: <strong>&#8378;{{ number_format($pay->total_amount, 2) }}</strong></div>
                                    <div class="text-red-500">Komisyon (%5): -&#8378;{{ number_format($pay->commission_amount, 2) }}</div>
                                    <div class="text-emerald-500 font-bold">Net EFT: &#8378;{{ number_format($pay->net_amount, 2) }}</div>
                                </div>
                            </td>
                            <td class="p-5 text-right">
                                @if($pay->status === 'pending')
@else
                                    <div class="inline-flex flex-col items-end">
                                        <span class="text-[10px] bg-emerald-500/10 text-emerald-600 font-bold px-2.5 py-1 rounded-full">EFT ÖDENDİ</span>
                                        <span class="text-[9px] text-neutral-400 font-mono mt-1 select-all">Ref: {{ $pay->reference_no }}</span>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-12 text-center text-neutral-400">Şoförler için bekleyen veya ödenmiş hak ediş kaydı bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        @elseif($activeTab === 'invoices')
            <!-- TABLO 3: DÜZENLENEN E-ARŞİV FATURALAR -->
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] font-bold text-neutral-400 dark:text-neutral-500 tracking-wider">
                        <th class="p-5">Fatura No / Tarih</th>
                        <th class="p-5">Fatura Tipi</th>
                        <th class="p-5">Müşteri Detayı</th>
                        <th class="p-5">Maliye Dağılımı (%20 KDV)</th>
                        <th class="p-5">Fatura Durumu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse(\App\Models\Invoice::with('user')->latest()->get() as $inv)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="p-5">
                                <div class="font-bold font-mono tracking-wider text-neutral-900 dark:text-white">{{ $inv->invoice_no }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">{{ $inv->issued_at->format('Y-m-d H:i') }}</div>
                            </td>
                            <td class="p-5">
                                <span class="px-2.5 py-1 rounded-full font-bold text-[10px] {{ $inv->invoice_type === 'commission' ? 'bg-brand-500/10 text-brand-600' : 'bg-emerald-500/10 text-emerald-600' }}">
                                    {{ $inv->invoice_type === 'commission' ? 'Platform Komisyonu' : 'Aylık Premium Üyelik' }}
                                </span>
                            </td>
                            <td class="p-5">
                                <div class="font-bold">{{ $inv->user->full_name ?? 'Müşteri' }}</div>
                                <div class="text-[11px] text-neutral-400 mt-0.5">E-posta: {{ $inv->user->email }}</div>
                            </td>
                            <td class="p-5">
                                <div class="grid grid-cols-1 gap-0.5">
                                    <div>Matrah: <strong>&#8378;{{ number_format($inv->base_amount, 2) }}</strong></div>
                                    <div>KDV (%20): <strong>&#8378;{{ number_format($inv->tax_amount, 2) }}</strong></div>
                                    <div class="font-bold text-neutral-950 dark:text-white">Toplam: &#8378;{{ number_format($inv->total_amount, 2) }}</div>
                                </div>
                            </td>
                            <td class="p-5">
                                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-600">Resmileştirildi (E-Arşiv)</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-12 text-center text-neutral-400">Sistem üzerinde henüz kesilmiş bir e-arşiv fatura kaydı bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif

    </div>
</div>
