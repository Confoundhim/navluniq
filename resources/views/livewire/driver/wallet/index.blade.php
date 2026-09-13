<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.driver')]
#[Title('Cüzdan & Hak Ediş Merkezi')]
class extends Component {
    public float $pendingEscrow = 0.0;
    public float $availableBalance = 0.0;
    public float $totalWithdrawn = 0.0;

    // IBAN Bilgileri
    public string $bank_name = '';
    public string $iban = '';
    public string $account_holder = '';

    // Para Çekme Modalı
    public bool $withdrawModalOpen = false;
    public string $withdrawAmount = '';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user) {
            $this->account_holder = $user->full_name;

            if ($user->driverProfile) {
                $driverId = (int) $user->driverProfile->id;

                // Havuzda bekleyen brüt tutar
                $rawSum = (float) Load::where('driver_profile_id', $driverId)
                    ->where('escrow_status', 'paid_in_escrow')
                    ->sum('price');
                $this->pendingEscrow = round($rawSum * 0.95, 2);

                // Teslimatı bitmiş, çekilebilir net bakiye (%95)
                $releasedSum = (float) Load::where('driver_profile_id', $driverId)
                    ->where('escrow_status', 'released_to_driver')
                    ->sum('price');
                $this->availableBalance = round($releasedSum * 0.95, 2);

                $this->totalWithdrawn = 0.0;
            }
        }
    }

    public function updateIban(): void
    {
        $this->validate([
            'iban' => 'required|min:24',
            'bank_name' => 'required',
        ], [
            'iban.required' => 'Lütfen TR ile başlayan 26 haneli IBAN numaranızı giriniz.',
        ]);

        session()->flash('success_message', 'Banka ve IBAN bilgileriniz başarıyla güncellendi. Hak edişleriniz bu hesaba aktarılacaktır.');
    }

    public function requestPayout(): void
    {
        $this->validate([
            'withdrawAmount' => 'required|numeric|min:100|max:' . max(100, $this->availableBalance),
        ], [
            'withdrawAmount.max' => 'Çekmek istediğiniz tutar mevcut çekilebilir bakiyenizden fazla olamaz.',
        ]);

        $this->addError('withdrawAmount', 'Ödeme sağlayıcısı ve doğrulanmış banka hesabı bağlantısı tamamlanmadan çekim talebi oluşturulamaz.');
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

    <!-- Başarı Bildirimi -->
    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success_message') }}</span>
            </div>
        </div>
    @endif

    <!-- Üst Başlık & Bakiye Çekme Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Cüzdan & Hak Ediş Merkezi</h2>
            <p class="text-xs text-neutral-400 mt-1">Alın terinizin karşılığı PayTR havuzunda güvendedir. Başarılı teslimatlardan sonra hak edişinizi IBAN'ınıza çekin.</p>
        </div>

        <button type="button" wire:click="$set('withdrawModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Banka Hesabıma Para Çek</span>
        </button>
    </div>

    <!-- 3'lü Finansal Metrik Kartları -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 space-y-2">
            <span class="text-xs font-semibold text-neutral-400 block">PayTR Havuzunda Bekleyen</span>
            <div class="text-3xl font-black text-brand-400 font-mono">
                {{ number_format($pendingEscrow, 2, ',', '.') }} <span class="text-white text-base">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 flex items-center gap-1.5 pt-1">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                <span>Yük teslim edilince çözülür (Net %95)</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 space-y-2">
            <span class="text-xs font-semibold text-neutral-400 block">Çekilebilir Hazır Bakiye</span>
            <div class="text-3xl font-black text-emerald-400 font-mono">
                {{ number_format($availableBalance, 2, ',', '.') }} <span class="text-white text-base">₺</span>
            </div>
            <div class="text-[11px] text-emerald-400 flex items-center gap-1.5 pt-1">
                <span>✓ Anında IBAN'a EFT / FAST yapılabilir</span>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-5 space-y-2">
            <span class="text-xs font-semibold text-neutral-400 block">Toplam Çekilen Kazanç</span>
            <div class="text-3xl font-black text-white font-mono">
                {{ number_format($totalWithdrawn, 2, ',', '.') }} <span class="text-neutral-400 text-base">₺</span>
            </div>
            <div class="text-[11px] text-neutral-500 pt-1">
                NavlunIQ güvencesiyle tamamlanan transferler
            </div>
        </div>

    </div>

    <!-- IBAN BİLGİLERİ VE KOMİSYON KESİNTİ BİLGİSİ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Sol 2 Kolon: IBAN Tanımlama Formu -->
        <div class="lg:col-span-2 bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
            <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider border-b border-neutral-800 pb-3">Kayıtlı Banka / IBAN Bilgilerim</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-medium text-neutral-300 mb-1">Hesap Sahibi (Ad Soyad)</label>
                    <input type="text" value="{{ $account_holder }}" disabled class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-400 font-semibold cursor-not-allowed">
                    <span class="text-[10px] text-neutral-500 mt-1 block">Güvenlik gereği sadece kendi adınıza kayıtlı IBAN'a çekim yapabilirsiniz.</span>
                </div>

                <div>
                    <label class="block font-medium text-neutral-300 mb-1">Banka Adı</label>
                    <input type="text" wire:model="bank_name" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                </div>

                <div class="sm:col-span-2">
                    <label class="block font-medium text-neutral-300 mb-1">IBAN Numarası <span class="text-brand-500">*</span></label>
                    <input type="text" wire:model="iban" maxlength="32" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono font-bold focus:border-brand-500 focus:outline-none">
                    @error('iban') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" wire:click="updateIban" class="px-5 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-white font-bold text-xs transition-colors">
                    IBAN Bilgilerini Güncelle
                </button>
            </div>
        </div>

        <!-- Sağ 1 Kolon: Şeffaf Komisyon Bilgilendirmesi -->
        <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4 flex flex-col justify-between">
            <div class="space-y-3">
                <h3 class="text-xs font-bold text-neutral-400 uppercase tracking-wider border-b border-neutral-800 pb-3">Şeffaf Komisyon Yapısı</h3>
                <div class="p-4 bg-neutral-950 rounded-xl border border-neutral-800 space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Şoför Hak Ediş Oranı:</span>
                        <span class="text-emerald-400 font-bold font-mono text-sm">%95</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-neutral-400">Platform Hizmet Kesintisi:</span>
                        <span class="text-neutral-300 font-mono">%5</span>
                    </div>
                </div>
                <p class="text-[11px] text-neutral-400 leading-relaxed">
                    Kesilen %5'lik komisyon bedeli için adınıza KDV dahil <b>e-Arşiv Fatura</b> otomatik olarak düzenlenir ve e-postanıza iletilir.
                </p>
            </div>
        </div>

    </div>

    <!-- KESİNTİLER VE E-ARŞİV FATURALAR TABLOSU -->
    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl overflow-hidden">
        <div class="p-5 border-b border-neutral-800 flex items-center justify-between">
            <h3 class="text-sm font-bold text-white tracking-tight">Komisyon ve Hizmet Faturalarım (e-Arşiv)</h3>
            <span class="text-xs text-neutral-400 font-mono">GİB Entegre</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-neutral-950 text-neutral-400 uppercase tracking-wider text-[10px] border-b border-neutral-800">
                    <tr>
                        <th class="py-3.5 px-5">Fatura No</th>
                        <th class="py-3.5 px-5">Hizmet Türü</th>
                        <th class="py-3.5 px-5">Tarih</th>
                        <th class="py-3.5 px-5">Matrah</th>
                        <th class="py-3.5 px-5">KDV (%20)</th>
                        <th class="py-3.5 px-5 text-right">Tutar</th>
                        <th class="py-3.5 px-5 text-center">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800 text-neutral-300">
                    @forelse($invoices as $inv)
                        <tr class="hover:bg-neutral-800/40 transition-colors">
                            <td class="py-4 px-5 font-mono font-bold text-white">{{ $inv->invoice_no }}</td>
                            <td class="py-4 px-5">
                                <span class="px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-400 text-[10px] font-bold">
                                    {{ $inv->invoice_type === 'commission' ? 'Taşıma Hizmet Kesintisi' : 'Abonelik' }}
                                </span>
                            </td>
                            <td class="py-4 px-5 text-neutral-400">{{ $inv->issued_at?->format('d.m.Y H:i') }}</td>
                            <td class="py-4 px-5 font-mono">{{ number_format((float)$inv->base_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono text-neutral-400">{{ number_format((float)$inv->tax_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 font-mono font-bold text-white text-right">{{ number_format((float)$inv->total_amount, 2, ',', '.') }} ₺</td>
                            <td class="py-4 px-5 text-center">
                                <button type="button" onclick="window.print()" class="px-3 py-1.5 rounded-lg bg-neutral-800 hover:bg-neutral-700 text-white font-semibold text-[11px] transition-colors">
                                    PDF İndir
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-neutral-500">
                                Henüz kesilmiş bir komisyon faturanız bulunmamaktadır.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- PARA ÇEKME MODALI -->
    @if($withdrawModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('withdrawModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400">₺</span>
                        <span>Banka Hesabına Para Çek</span>
                    </h3>
                    <button wire:click="$set('withdrawModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-3.5 bg-neutral-950 rounded-xl border border-neutral-800 text-xs space-y-1">
                    <div class="flex items-center justify-between text-neutral-400">
                        <span>Çekilebilir Bakiyeniz:</span>
                        <span class="text-emerald-400 font-bold font-mono text-sm">{{ number_format($availableBalance, 2, ',', '.') }} ₺</span>
                    </div>
                    <div class="flex items-center justify-between text-neutral-400">
                        <span>Hedef IBAN:</span>
                        <span class="text-white font-mono text-[11px]">{{ substr($iban, 0, 10) }}...{{ substr($iban, -4) }}</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-medium text-neutral-300">Çekmek İstediğiniz Tutar (₺)</label>
                    <input type="number" wire:model="withdrawAmount" placeholder="Örn: 5000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-lg font-mono font-bold text-white focus:border-emerald-500 focus:outline-none">
                    @error('withdrawAmount') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('withdrawModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="requestPayout" class="flex-1 px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold shadow-lg shadow-emerald-500/20 transition-all">
                        Transferi Başlat
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
