<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Müşteri Hizmetleri & Destek Biletleri')]
class extends Component {
    public bool $newTicketModalOpen = false;

    // Veritabanı ENUM'u ile tam uyumlu form alanları
    public string $category = 'technical';
    public string $message = '';

    // Veritabanı ENUM'u ile eşleştirilmiş kategori listesi
    public array $categoryOptions = [
        'technical' => 'Teknik Hata, Bug ve Sistem Sorunları',
        'billing' => 'Fatura, Abonelik ve Ödemeler',
        'kyc' => 'Evrak Doğrulama ve KYC İşlemleri',
        'escrow' => 'PayTR Güvenli Havuz (Escrow) Süreçleri',
        'dispute' => 'Uyuşmazlık ve Kriz Yönetimi',
        'other' => 'Diğer / Genel Sorular ve Öneriler',
    ];

    public function createTicket(): void
    {
        $this->validate([
            'category' => 'required|in:technical,billing,kyc,escrow,dispute,other',
            'message' => 'required|min:15',
        ], [
            'category.required' => 'Lütfen bir kategori seçiniz.',
            'message.required' => 'Lütfen mesajınızı yazınız.',
            'message.min' => 'Mesajınız en az 15 karakter olmalıdır.',
        ]);

        $user = Auth::user();
        if ($user) {
            SupportTicket::create([
                'name' => $user->full_name,
                'email' => $user->email ?? 'destek@navluniq.test',
                'phone' => $user->phone ?? '05000000000',
                'role' => 'cargo_owner',
                'category' => $this->category,
                'message' => $this->message,
                'status' => 'open',
            ]);

            $this->newTicketModalOpen = false;
            $this->reset(['message', 'category']);
            session()->flash('success_message', 'Destek talebiniz başarıyla oluşturuldu. Destek ekibimiz en kısa sürede yanıtlayacaktır.');
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $tickets = collect();

        if ($user) {
            $tickets = SupportTicket::where('email', $user->email)
                ->orWhere('phone', $user->phone)
                ->latest()
                ->get();
        }

        return [
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

    <!-- Üst Başlık & Yeni Talep Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Müşteri Hizmetleri & Destek Taleplerim</h2>
            <p class="text-xs text-neutral-400 mt-1">Teknik, finansal veya operasyonel tüm sorularınız için 7/24 destek ekibimize bilet açabilirsiniz.</p>
        </div>

        <button type="button" wire:click="$set('newTicketModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni Destek Bileti Aç</span>
        </button>
    </div>

    <!-- Destek Biletleri Listesi -->
    <div class="space-y-4">
        @forelse($tickets as $ticket)
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-neutral-800 pb-3">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-800 text-neutral-300 font-mono text-xs font-bold">
                            #TCK-{{ str_pad((string)$ticket->id, 5, '0', STR_PAD_LEFT) }}
                        </span>

                        <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 border border-brand-500/20 text-[10px] font-bold">
                            {{ $categoryOptions[$ticket->category] ?? $ticket->category }}
                        </span>

                        @if($ticket->status === 'open')
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 text-[10px] font-bold uppercase">
                                Yanıt Bekliyor
                            </span>
                        @elseif($ticket->status === 'replied')
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 text-[10px] font-bold uppercase">
                                ✓ Yanıtlandı
                            </span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full bg-neutral-800 text-neutral-400 text-[10px] font-bold uppercase">
                                Kapandı
                            </span>
                        @endif
                    </div>

                    <span class="text-xs text-neutral-500">
                        {{ $ticket->created_at?->format('d.m.Y H:i') }}
                    </span>
                </div>

                <!-- Kullanıcı Mesajı -->
                <div class="text-xs text-neutral-300 leading-relaxed bg-neutral-950 p-4 rounded-xl border border-neutral-800">
                    <span class="text-neutral-500 block mb-1 font-semibold">Talebiniz:</span>
                    {{ $ticket->message }}
                </div>

                <!-- Yönetici Yanıtı -->
                @if($ticket->admin_reply)
                    <div class="text-xs text-neutral-200 leading-relaxed bg-brand-500/5 p-4 rounded-xl border border-brand-500/20 space-y-1">
                        <div class="flex items-center justify-between text-brand-400 font-bold text-[11px]">
                            <span>NavlunIQ Müşteri Temsilcisi Yanıtı:</span>
                            <span class="text-neutral-500 font-normal">{{ $ticket->replied_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <p class="text-neutral-300 pt-1">{{ $ticket->admin_reply }}</p>
                    </div>
                @endif

            </div>
        @empty
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Henüz Bir Destek Talebiniz Yok</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">Sistemle veya işlemlerinizle ilgili her konuda bize 7/24 bilet oluşturabilirsiniz.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Yeni Bilet Modalı -->
    @if($newTicketModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('newTicketModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </span>
                        <span>Yeni Destek Bileti Oluştur</span>
                    </h3>
                    <button wire:click="$set('newTicketModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">Konu Kategorisi <span class="text-brand-500">*</span></label>
                        <select wire:model="category" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-200 focus:border-brand-500 focus:outline-none">
                            @foreach($categoryOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('category') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1.5">Destek Talebiniz / Sorunuz <span class="text-brand-500">*</span></label>
                        <textarea wire:model="message" rows="5" placeholder="Yaşadığınız durumu veya sorunuzu detaylı bir şekilde açıklayınız..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('message') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('newTicketModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Kapat
                    </button>
                    <button type="button" wire:click="createTicket" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        Talebi İlet
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
