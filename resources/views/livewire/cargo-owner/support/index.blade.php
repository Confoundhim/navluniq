<?php

use App\Models\SupportTicket;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Destek')]
class extends Component {
    use WithPagination;

    public bool $newTicketModalOpen = false;

    public string $category = 'other';

    public string $message = '';

    public function openModal(): void
    {
        $this->resetErrorBag();
        $this->reset(['message']);
        $this->category = 'other';
        $this->newTicketModalOpen = true;
    }

    public function createTicket(): void
    {
        $this->validate([
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'message' => 'required|string|min:15|max:3000',
        ], [
            'category.required' => 'Lütfen bir kategori seçin.',
            'category.in' => 'Geçersiz kategori.',
            'message.required' => 'Lütfen mesajınızı yazın.',
            'message.min' => 'Mesajınız en az 15 karakter olmalıdır.',
        ]);

        $user = Auth::user();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone ? Phone::format(Phone::normalize($user->phone) ?? $user->phone) : null,
            'role' => 'cargo_owner',
            'category' => $this->category,
            'subject' => SupportTicket::CATEGORIES[$this->category],
            'message' => trim($this->message),
            'status' => 'open',
        ]);

        $this->newTicketModalOpen = false;
        $this->reset(['message']);
        $this->category = 'other';
        $this->resetPage();
        session()->flash('success_message', 'Destek talebiniz #'.$ticket->id.' numarasıyla oluşturuldu. Yanıt verildiğinde bu sayfada görünür.');
    }

    public function with(): array
    {
        return [
            'tickets' => SupportTicket::query()->where('user_id', Auth::id())->latest()->paginate(15),
            'categories' => SupportTicket::CATEGORIES,
            'statusLabels' => ['open' => 'Yanıt bekliyor', 'answered' => 'Yanıtlandı', 'closed' => 'Kapatıldı'],
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Destek taleplerim</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Teknik, finansal veya operasyonel sorularınız için destek bileti açın; yanıtlar bu sayfada görünür.</p>
        </div>

        <button type="button" wire:click="openModal" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni destek bileti</span>
        </button>
    </div>

    <div class="space-y-4">
        @forelse($tickets as $ticket)
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-300 tabular-nums text-xs font-bold">#{{ $ticket->id }}</span>
                        <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 border border-brand-500/20 text-[11px] font-bold">{{ $categories[$ticket->category] ?? $ticket->subject ?? $ticket->category }}</span>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border
                            {{ $ticket->status === 'open' ? 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400' : ($ticket->status === 'answered' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-500 dark:text-neutral-400') }}">
                            {{ $statusLabels[$ticket->status] ?? $ticket->status }}
                        </span>
                    </div>
                    <span class="text-xs text-neutral-500">{{ $ticket->created_at?->format('d.m.Y H:i') }}</span>
                </div>

                <div class="text-xs text-neutral-700 dark:text-neutral-300 leading-relaxed bg-neutral-50 dark:bg-neutral-950 p-4 rounded-xl border border-neutral-200 dark:border-neutral-800 break-words">
                    <span class="text-neutral-500 block mb-1 font-semibold">Talebiniz</span>
                    {{ $ticket->message }}
                </div>

                @if($ticket->admin_reply)
                    <div class="text-xs text-neutral-800 dark:text-neutral-200 leading-relaxed bg-brand-500/5 p-4 rounded-xl border border-brand-500/20 space-y-1">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-brand-400 font-bold text-[11px]">
                            <span>NavlunIQ destek yanıtı</span>
                            <span class="text-neutral-500 font-normal">{{ $ticket->replied_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <p class="text-neutral-700 dark:text-neutral-300 pt-1 break-words">{{ $ticket->admin_reply }}</p>
                    </div>
                @elseif($ticket->status === 'open')
                    <p class="text-[11px] text-neutral-500">Destek ekibi henüz yanıt vermedi.</p>
                @endif

            </div>
        @empty
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-100 dark:bg-neutral-800/80 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-neutral-900 dark:text-white">Henüz destek talebiniz yok</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">İşlemlerinizle ilgili her konuda bilet oluşturabilirsiniz.</p>
                </div>
            </div>
        @endforelse
    </div>

    @if($tickets->hasPages())
        <div class="text-xs">{{ $tickets->links() }}</div>
    @endif

    @if($newTicketModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('newTicketModalOpen', false)"></div>
            <form wire:submit.prevent="createTicket" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-5 text-left">

                <div class="flex items-center justify-between border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Yeni destek bileti</h3>
                    <button type="button" wire:click="$set('newTicketModalOpen', false)" class="text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">Kategori <span class="text-brand-500">*</span></label>
                        <select wire:model="category" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-800 dark:text-neutral-200 focus:border-brand-500 focus:outline-none">
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('category') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">Mesajınız <span class="text-brand-500">*</span></label>
                        <textarea wire:model="message" rows="5" maxlength="3000" placeholder="Yaşadığınız durumu veya sorunuzu ayrıntılı açıklayın." class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl p-3 text-neutral-900 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('message') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <p class="text-[11px] text-neutral-500">İletişim bilgileriniz ({{ auth()->user()?->email }}) hesabınızdan alınır.</p>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    <button type="button" wire:click="$set('newTicketModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 text-xs font-semibold transition-colors">Kapat</button>
                    <button type="submit" wire:loading.attr="disabled" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        <span wire:loading.remove wire:target="createTicket">Talebi gönder</span>
                        <span wire:loading wire:target="createTicket">Gönderiliyor...</span>
                    </button>
                </div>

            </form>
        </div>
    @endif

</div>
