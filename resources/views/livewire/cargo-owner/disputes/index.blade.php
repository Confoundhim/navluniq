<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\Dispute;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Kriz & Uyuşmazlık (Dispute) Merkezi')]
class extends Component {
    use WithFileUploads;

    // Yeni Uyuşmazlık Form Alanları
    public bool $createModalOpen = false;
    public ?int $selected_load_id = null;
    public string $claim_reason = 'Hasarlı Ürün / Yük Hasarı';
    public string $claim_description = '';
    public $damage_photo = null;

    // İtiraz Nedenleri
    public array $reasons = [
        'Hasarlı Ürün / Yük Hasarı',
        'Eksik Teslimat / Koli Kaybı',
        'Taahhüt Edilen Tarihten Çok Geç Teslimat',
        'Araç / Şoför Uyuşmazlığı',
        'Diğer / Kural İhlali'
    ];

    public function openNewDisputeModal(?int $loadId = null): void
    {
        $this->selected_load_id = $loadId;
        $this->createModalOpen = true;
    }

    public function submitDispute(): void
    {
        $this->validate([
            'selected_load_id' => 'required|exists:loads,id',
            'claim_reason' => 'required',
            'claim_description' => 'required|min:20',
            'damage_photo' => 'nullable|image|max:10240',
        ], [
            'selected_load_id.required' => 'Lütfen uyuşmazlık konusu sevkiyatı seçiniz.',
            'claim_description.required' => 'Lütfen uyuşmazlık detayını açıklayınız.',
            'claim_description.min' => 'Açıklama en az 20 karakter olmalıdır.',
        ]);

        $user = Auth::user();
        if (!$user || !$user->cargoOwnerProfile) {
            return;
        }

        $load = Load::where('id', $this->selected_load_id)
            ->where('cargo_owner_profile_id', (int) $user->cargoOwnerProfile->id)
            ->first();

        if ($load) {
            $photoPath = null;
            if ($this->damage_photo) {
                $photoPath = $this->damage_photo->store('private/dispute_evidence', 'local');
            }

            // Uyuşmazlık kaydını aç
            Dispute::create([
                'load_id' => $load->id,
                'cargo_owner_claim' => "[{$this->claim_reason}] " . $this->claim_description,
                'driver_proof_photo_path' => $photoPath,
                'status' => 'open',
            ]);

            // Sevkiyat durumunu 'disputed' yaparak para çıkışını havuzda dondur
            $load->update([
                'status' => 'disputed',
            ]);

            $this->createModalOpen = false;
            $this->reset(['selected_load_id', 'claim_description', 'damage_photo']);
            session()->flash('success_message', 'Uyuşmazlık dosyası açıldı. Uyuşmazlık kaydedildi ve ilgili ödeme işlemi incelemeye alındı.');
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $disputes = collect();
        $eligibleLoads = collect();

        if ($user && $user->cargoOwnerProfile) {
            $profileId = (int) $user->cargoOwnerProfile->id;

            // Kullanıcının açtığı uyuşmazlıklar
            $disputes = Dispute::whereHas('cargoLoad', function ($query) use ($profileId) {
                $query->where('cargo_owner_profile_id', $profileId);
            })->with(['cargoLoad.driverProfile.user'])->latest()->get();

            // Uyuşmazlık açılabilecek aktif/yoldaki yükler
            $eligibleLoads = Load::where('cargo_owner_profile_id', $profileId)
                ->whereIn('status', ['on_the_way', 'delivered'])
                ->get();
        }

        return [
            'disputes' => $disputes,
            'eligibleLoads' => $eligibleLoads,
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

    <!-- Başlık ve Yeni Talep Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-rose-500/10 text-rose-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </span>
                <span>Kriz & Uyuşmazlık (Hakem Heyeti) Merkezi</span>
            </h2>
            <p class="text-xs text-neutral-400 mt-1">Hasar, eksik teslimat veya gecikme durumunda PayTR havuz ödemesini kilitleyerek hakem kararı talep edin.</p>
        </div>

        <button type="button" wire:click="openNewDisputeModal" class="px-5 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs shadow-lg shadow-rose-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni Uyuşmazlık Dosyası Aç</span>
        </button>
    </div>

    <!-- Güvence ve İşleyiş Bilgilendirme Notu -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-4 rounded-xl bg-neutral-900 border border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400 flex items-center gap-1.5">
                <span>1. Ödeme Otomatik Kilitlenir</span>
            </div>
            <p class="text-[11px] text-neutral-400 leading-relaxed">Uyuşmazlık açıldığında şoföre para aktarımı derhal dondurulur.</p>
        </div>
        <div class="p-4 rounded-xl bg-neutral-900 border border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400 flex items-center gap-1.5">
                <span>2. Kanıtlar İncelenir</span>
            </div>
            <p class="text-[11px] text-neutral-400 leading-relaxed">Sürücünün yükleme/POD fotoğrafları ile hasar bildiriminiz kıyaslanır.</p>
        </div>
        <div class="p-4 rounded-xl bg-neutral-900 border border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400 flex items-center gap-1.5">
                <span>3. Yasal Hakem Kararı</span>
            </div>
            <p class="text-[11px] text-neutral-400 leading-relaxed">Hakem heyeti kararına göre PayTR iadesi veya ödemesi gerçekleştirilir.</p>
        </div>
    </div>

    <!-- Uyuşmazlık Dosyaları Listesi -->
    <div class="space-y-4">
        @forelse($disputes as $dispute)
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-neutral-800 pb-3">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-xs font-bold">
                            Dosya #DIS-{{ str_pad((string)$dispute->id, 5, '0', STR_PAD_LEFT) }}
                        </span>

                        @if($dispute->status === 'open')
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[10px] font-bold uppercase flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping"></span>
                                Hakem Heyeti İnceliyor
                            </span>
                        @elseif($dispute->status === 'resolved_owner_refunded')
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold uppercase">
                                ✓ Karar: Yük Sahibine İade Edildi
                            </span>
                        @elseif($dispute->status === 'resolved_driver_paid')
                            <span class="px-2.5 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-[10px] font-bold uppercase">
                                Karar: Şoföre Aktarıldı
                            </span>
                        @endif
                    </div>

                    <span class="text-xs text-neutral-500">
                        {{ $dispute->created_at?->format('d.m.Y H:i') }}
                    </span>
                </div>

                <!-- Sevkiyat ve İtiraz Detayı -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 text-xs">
                    <div class="space-y-2">
                        <div class="text-neutral-400">İlgili Sevkiyat:</div>
                        <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 space-y-1">
                            <div class="text-white font-bold">{{ $dispute->cargoLoad?->pickup_location }} &rarr; {{ $dispute->cargoLoad?->delivery_location }}</div>
                            <div class="text-neutral-500">Sürücü: <span class="text-neutral-300 font-medium">{{ $dispute->cargoLoad?->driverProfile?->user?->full_name ?? 'Atanmış Sürücü' }}</span></div>
                            <div class="text-neutral-500">Bloke Navlun Tutarı: <span class="text-brand-400 font-mono font-bold">{{ number_format((float)($dispute->cargoLoad?->price ?? 0), 2, ',', '.') }} ₺</span></div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="text-neutral-400">İtiraz ve Hasar Beyanınız:</div>
                        <div class="p-3 bg-neutral-950 rounded-xl border border-neutral-800 text-neutral-300 leading-relaxed italic">
                            "{{ $dispute->cargo_owner_claim }}"
                        </div>
                    </div>
                </div>

                @if($dispute->arbitration_notes)
                    <div class="p-4 rounded-xl bg-neutral-950/80 border border-brand-500/20 space-y-1 text-xs">
                        <span class="font-bold text-brand-400">Hakem Heyeti Nihai Karar Gerekçesi:</span>
                        <p class="text-neutral-300 leading-relaxed">{{ $dispute->arbitration_notes }}</p>
                    </div>
                @endif

            </div>
        @empty
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center mx-auto text-2xl font-bold">
                    ✓
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Aktif Uyuşmazlık Bulunmuyor</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">Tüm sevkiyatlarınız güvenli havuz ve sigorta koruması altında sorunsuz devam etmektedir.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Yeni Uyuşmazlık Başlatma Modalı -->
    @if($createModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('createModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-rose-500/10 text-rose-400">⚠</span>
                        <span>Uyuşmazlık Dosyası Başlat</span>
                    </h3>
                    <button wire:click="$set('createModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">İtiraz Edilecek Sevkiyat <span class="text-brand-500">*</span></label>
                        <select wire:model="selected_load_id" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-200 focus:border-brand-500 focus:outline-none">
                            <option value="">-- Sevkiyat Seçin --</option>
                            @foreach($eligibleLoads as $l)
                                <option value="{{ $l->id }}">#NVL-{{ $l->id }} | {{ $l->pickup_location }} &rarr; {{ $l->delivery_location }} ({{ number_format((float)$l->price, 2, ',', '.') }} ₺)</option>
                            @endforeach
                        </select>
                        @error('selected_load_id') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">Uyuşmazlık Konusu</label>
                        <select wire:model="claim_reason" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-200 focus:border-brand-500 focus:outline-none">
                            @foreach($reasons as $reason)
                                <option value="{{ $reason }}">{{ $reason }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">Olayın Detaylı Açıklaması <span class="text-brand-500">*</span></label>
                        <textarea wire:model="claim_description" rows="4" placeholder="Yükleme/boşaltma noktasında ne yaşandığını, hasar veya eksik miktarı ayrıntılı olarak yazınız..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('claim_description') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">Hasar / İtiraz Fotoğrafı (Opsiyonel)</label>
                        <input type="file" wire:model="damage_photo" class="w-full text-xs text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-800 file:text-neutral-200 hover:file:bg-neutral-700 cursor-pointer">
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-[11px] text-rose-400 leading-relaxed">
                    ⚠ Formu gönderdiğinizde, PayTR havuzundaki navlun bedeli kilitlenir ve şoföre hakem kararı çıkana kadar aktarılmaz.
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('createModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="submitDispute" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold shadow-lg shadow-rose-500/20 transition-all">
                        Dosyayı Aç & Ödemeyi Durdur
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
