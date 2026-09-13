<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Dispute;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.driver')]
#[Title('Uyuşmazlık & Savunma Merkezi')]
class extends Component {
    use WithFileUploads;

    // Savunma Yapma Modalı
    public bool $defenseModalOpen = false;
    public ?int $selectedDisputeId = null;
    public ?Dispute $selectedDispute = null;
    public string $driver_defense = '';
    public $defense_photo = null;

    // Yeni Destek Talebi
    public bool $supportModalOpen = false;
    public string $support_category = 'technical';
    public string $support_message = '';

    public array $categories = [
        'technical' => 'Teknik Hata & GPS Sorunları',
        'billing' => 'Hak Ediş & IBAN Ödemeleri',
        'kyc' => 'Evrak & Ruhsat Onay Süreçleri',
        'escrow' => 'PayTR Havuz Güvencesi',
        'dispute' => 'Yük Sahibi Uyuşmazlıkları',
        'other' => 'Diğer / Genel Sorular',
    ];

    public function openDefenseModal(int $disputeId): void
    {
        $this->selectedDisputeId = $disputeId;
        $this->selectedDispute = Dispute::find($disputeId);
        $this->driver_defense = $this->selectedDispute?->driver_defense ?? '';
        $this->defenseModalOpen = true;
    }

    public function submitDefense(): void
    {
        $this->validate([
            'driver_defense' => 'required|min:20',
            'defense_photo' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'driver_defense.required' => 'Lütfen savunmanızı ve olay anındaki durumu detaylıca yazınız.',
            'driver_defense.min' => 'Savunma metniniz en az 20 karakter olmalıdır.',
        ]);

        if ($this->selectedDispute) {
            $path = $this->selectedDispute->driver_proof_photo_path;
            if ($this->defense_photo) {
                $path = $this->defense_photo->store('private/defense_evidence', 'local');
            }

            $this->selectedDispute->update([
                'driver_defense' => $this->driver_defense,
                'driver_proof_photo_path' => $path,
            ]);

            $this->defenseModalOpen = false;
            session()->flash('success_message', 'Savunmanız ve kanıtlarınız uyuşmazlık dosyasına eklendi.');
        }
    }

    public function createSupportTicket(): void
    {
        $this->validate([
            'support_message' => 'required|min:15',
        ]);

        $user = Auth::user();
        if ($user) {
            SupportTicket::create([
                'name' => $user->full_name,
                'email' => $user->email ?? 'sofor@navluniq.test',
                'phone' => $user->phone ?? '05000000000',
                'role' => 'driver',
                'category' => $this->support_category,
                'message' => $this->support_message,
                'status' => 'open',
            ]);

            $this->supportModalOpen = false;
            $this->reset(['support_message']);
            session()->flash('success_message', 'Destek talebiniz açıldı. Müşteri temsilcimiz en kısa sürede yanıtlayacaktır.');
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $disputes = collect();
        $tickets = collect();

        if ($user && $user->driverProfile) {
            $driverId = (int) $user->driverProfile->id;

            $disputes = Dispute::whereHas('cargoLoad', function ($q) use ($driverId) {
                $q->where('driver_profile_id', $driverId);
            })->with(['cargoLoad.cargoOwnerProfile.user'])->latest()->get();

            $tickets = SupportTicket::where('role', 'driver')
                ->where(function($q) use ($user) {
                    $q->where('email', $user->email)->orWhere('phone', $user->phone);
                })->latest()->get();
        }

        return [
            'disputes' => $disputes,
            'tickets' => $tickets,
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

    <!-- Başlık & Destek Talebi Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Kriz, Uyuşmazlık & Savunma Merkezi</h2>
            <p class="text-xs text-neutral-400 mt-1">Yük sahibinin itirazlarına karşı savunma kanıtlarınızı (yükleme fotoğrafları, GPS logları) sunun.</p>
        </div>

        <button type="button" wire:click="$set('supportModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Destek Ekibine Yaz</span>
        </button>
    </div>

    <!-- UYUŞMAZLIK DOSYALARI -->
    <div class="space-y-4">
        @forelse($disputes as $dispute)
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-neutral-800 pb-3">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-xs font-bold">
                            Dosya #DIS-{{ str_pad((string)$dispute->id, 5, '0', STR_PAD_LEFT) }}
                        </span>

                        @if($dispute->status === 'open')
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-bold uppercase flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                Hakem Heyeti İncelemesinde
                            </span>
                        @elseif($dispute->status === 'resolved_driver_paid')
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase">
                                ✓ Karar: Hak Edişiniz Onaylandı & Hesaba Aktarıldı
                            </span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[10px] font-bold uppercase">
                                Karar: Yük Sahibine İade Edildi
                            </span>
                        @endif
                    </div>

                    <span class="text-xs text-neutral-500">{{ $dispute->created_at?->format('d.m.Y H:i') }}</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 text-xs">
                    <!-- Yük Sahibinin İddiası -->
                    <div class="space-y-2">
                        <span class="text-neutral-400 block font-semibold">Yük Sahibinin İtirazı:</span>
                        <div class="p-3.5 bg-neutral-950 rounded-xl border border-neutral-800 text-rose-300 leading-relaxed italic">
                            "{{ $dispute->cargo_owner_claim }}"
                        </div>
                    </div>

                    <!-- Şoförün Savunması -->
                    <div class="space-y-2">
                        <span class="text-neutral-400 block font-semibold">Sizin Savunmanız:</span>
                        <div class="p-3.5 bg-neutral-950 rounded-xl border border-neutral-800 text-neutral-300 leading-relaxed">
                            {{ $dispute->driver_defense ?: 'Henüz bir savunma metni girmediniz.' }}
                        </div>
                    </div>
                </div>

                @if($dispute->status === 'open')
                    <div class="pt-2 flex justify-end">
                        <button type="button" wire:click="openDefenseModal({{ $dispute->id }})" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            <span>{{ $dispute->driver_defense ? 'Savunmayı & Kanıtı Güncelle' : 'Savunma & Kanıt Fotoğrafı Ekle' }}</span>
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center mx-auto text-2xl font-bold">
                    ✓
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Adınıza Açılmış Bir Uyuşmazlık Yok</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">Tüm sevkiyatlarınız sorunsuz tamamlanmış olup hak edişleriniz güvendedir.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- SAVUNMA MODALI -->
    @if($defenseModalOpen && $selectedDispute)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('defenseModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-400">🛡️</span>
                        <span>Hakem Heyetine Savunma Bildir</span>
                    </h3>
                    <button wire:click="$set('defenseModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Savunma Metniniz <span class="text-brand-500">*</span></label>
                        <textarea wire:model="driver_defense" rows="5" placeholder="Yükleme anında çekilen fotoğraflar, teslimatta alıcının beyanı veya yol durumu hakkında detaylı açıklama yazınız..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('driver_defense') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Kanıt Fotoğrafı / İrsaliye (Opsiyonel)</label>
                        <input type="file" wire:model="defense_photo" class="w-full text-xs text-neutral-400 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-800 file:text-white hover:file:bg-neutral-700 cursor-pointer">
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('defenseModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="submitDefense" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        Savunmayı Gönder
                    </button>
                </div>

            </div>
        </div>
    @endif

    <!-- DESTEK BİLETİ MODALI -->
    @if($supportModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('supportModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-md bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white">Destek Talebi Aç</h3>
                    <button wire:click="$set('supportModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Kategori</label>
                        <select wire:model="support_category" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3 py-2 text-white focus:border-brand-500 focus:outline-none">
                            @foreach($categories as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Mesajınız <span class="text-brand-500">*</span></label>
                        <textarea wire:model="support_message" rows="4" placeholder="Yaşadığınız durumu açıklayınız..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('support_message') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('supportModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 text-neutral-300 text-xs font-semibold">Kapat</button>
                    <button type="button" wire:click="createSupportTicket" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold">Talebi İlet</button>
                </div>

            </div>
        </div>
    @endif

</div>
