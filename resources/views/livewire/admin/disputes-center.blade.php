<?php

use App\Mail\SystemNoticeMail;
use App\Models\ActivityLog;
use App\Models\Dispute;
use App\Models\SupportTicket;
use App\Services\DisputeService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $activeTab = 'disputes';

    #[Locked]
    public ?int $selectedId = null;

    public string $decision = 'driver_paid';

    public string $decisionNotes = '';

    public string $ticketStatus = 'open';

    #[Locked]
    public ?int $selectedTicketId = null;

    public string $reply = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->can('manage disputes') || $user->can('manage support tickets'), 403);
        $this->activeTab = $user->can('manage disputes') ? 'disputes' : 'tickets';
    }

    public function switchTab(string $tab): void
    {
        $user = auth()->user();
        if (($tab === 'disputes' && ! $user->can('manage disputes')) || ($tab === 'tickets' && ! $user->can('manage support tickets'))) {
            return;
        }
        $this->activeTab = $tab;
        $this->resetPage();
        $this->resetPage('historyPage');
    }

    public function updatedTicketStatus(): void
    {
        $this->resetPage();
        $this->selectedTicketId = null;
    }

    public function select(int $disputeId): void
    {
        $this->selectedId = $disputeId;
        $this->decision = 'driver_paid';
        $this->decisionNotes = '';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
        $this->decisionNotes = '';
    }

    public function resolve(): void
    {
        if (! auth()->user()->can('manage disputes')) {
            session()->flash('error_message', 'Karar vermek için "manage disputes" izni gerekir.');

            return;
        }

        $this->validate([
            'decision' => 'required|in:driver_paid,owner_refunded',
            'decisionNotes' => 'required|string|min:10|max:3000',
        ], [
            'decisionNotes.required' => 'Gerekçeli karar notu zorunludur.',
            'decisionNotes.min' => 'Karar notu en az 10 karakter olmalıdır.',
        ]);

        $dispute = Dispute::query()->find($this->selectedId);
        if (! $dispute) {
            session()->flash('error_message', 'Uyuşmazlık bulunamadı.');

            return;
        }

        try {
            app(DisputeService::class)->resolve($dispute, auth()->user(), $this->decision, $this->decisionNotes);
            $this->closePanel();
            session()->flash('success_message', 'Uyuşmazlık karara bağlandı; taraflar e-posta ile bilgilendirildi.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    public function selectTicket(int $ticketId): void
    {
        $this->selectedTicketId = $ticketId;
        $this->reply = '';
        $this->resetErrorBag();
    }

    public function closeTicketPanel(): void
    {
        $this->selectedTicketId = null;
        $this->reply = '';
    }

    public function replyTicket(): void
    {
        if (! auth()->user()->can('manage support tickets')) {
            session()->flash('error_message', 'Yanıt için "manage support tickets" izni gerekir.');

            return;
        }

        $this->validate(['reply' => 'required|string|min:10|max:5000'], ['reply.min' => 'Yanıt en az 10 karakter olmalıdır.']);

        $ticket = SupportTicket::query()->with('user')->find($this->selectedTicketId);
        if (! $ticket || $ticket->status === 'closed') {
            session()->flash('error_message', 'Kapatılmış veya bulunamayan bilete yanıt verilemez.');

            return;
        }

        $ticket->update([
            'admin_reply' => trim($this->reply),
            'replied_at' => now(),
            'status' => 'answered',
            'assigned_to' => auth()->id(),
        ]);

        $subject = 'Destek talebinize yanıt verildi';
        $lines = ['Konu: '.($ticket->subject ?: (SupportTicket::CATEGORIES[$ticket->category] ?? $ticket->category)), 'Yanıtımız:', trim($this->reply)];

        if ($ticket->user) {
            app(NotificationService::class)->notify($ticket->user, $subject, $lines, null, null, 'support');
        } else {
            try {
                Mail::to($ticket->email, $ticket->name)->send(new SystemNoticeMail($subject, $lines, null, null, $ticket->name));
            } catch (\Throwable $e) {
                Log::warning('Destek yanıtı e-postası gönderilemedi.', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
            }
        }

        ActivityLog::record('ticket.answered', "Destek bileti #{$ticket->id} yanıtlandı", auth()->id(), $ticket);
        $this->reply = '';
        session()->flash('success_message', 'Yanıt kaydedildi ve e-posta ile iletildi.');
    }

    public function closeTicket(): void
    {
        if (! auth()->user()->can('manage support tickets')) {
            session()->flash('error_message', 'Bilet kapatmak için "manage support tickets" izni gerekir.');

            return;
        }

        $ticket = SupportTicket::query()->find($this->selectedTicketId);
        if (! $ticket) {
            return;
        }

        $ticket->update(['status' => 'closed', 'closed_at' => now(), 'assigned_to' => $ticket->assigned_to ?? auth()->id()]);
        ActivityLog::record('ticket.closed', "Destek bileti #{$ticket->id} kapatıldı", auth()->id(), $ticket);
        $this->closeTicketPanel();
        session()->flash('success_message', 'Bilet kapatıldı.');
    }

    public function with(): array
    {
        $data = ['open' => null, 'history' => null, 'selected' => null, 'tickets' => null, 'ticket' => null,
            'canDisputes' => auth()->user()->can('manage disputes'), 'canTickets' => auth()->user()->can('manage support tickets')];

        if ($this->activeTab === 'disputes') {
            $data['open'] = Dispute::query()->where('status', 'open')
                ->with(['cargoLoad.cargoOwnerProfile.user', 'cargoLoad.driverProfile.user'])
                ->orderBy('id')->paginate(15);
            $data['history'] = Dispute::query()->where('status', '!=', 'open')
                ->with(['cargoLoad', 'resolver'])
                ->latest('resolved_at')->paginate(15, ['*'], 'historyPage');
            $data['selected'] = $this->selectedId
                ? Dispute::query()->with(['cargoLoad.cargoOwnerProfile.user', 'cargoLoad.driverProfile.user', 'cargoLoad.shipment', 'opener'])->find($this->selectedId)
                : null;
        } else {
            $query = SupportTicket::query()->with(['user', 'assignee']);
            if ($this->ticketStatus === 'open') {
                $query->whereIn('status', ['open', 'answered']);
            } else {
                $query->where('status', $this->ticketStatus);
            }
            $data['tickets'] = $query->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('id')->paginate(15);
            $data['ticket'] = $this->selectedTicketId ? SupportTicket::query()->with(['user', 'assignee'])->find($this->selectedTicketId) : null;
        }

        return $data;
    }
}; ?>

<div wire:poll.8s class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Uyuşmazlık ve Destek</h1>
        <p class="page-subtitle">Hakem kararı havuz ödemesini serbest bırakır ya da iade sürecini başlatır; karar geri alınamaz.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl overflow-x-auto">
        @if($canDisputes)
            <button type="button" wire:click="switchTab('disputes')" class="flex-none sm:flex-1 whitespace-nowrap px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'disputes' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Uyuşmazlıklar</button>
        @endif
        @if($canTickets)
            <button type="button" wire:click="switchTab('tickets')" class="flex-none sm:flex-1 whitespace-nowrap px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'tickets' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Destek biletleri</button>
        @endif
    </div>

    @if($activeTab === 'disputes' && $open)
        <div class="grid grid-cols-1 {{ $selected ? 'xl:grid-cols-2' : '' }} gap-6 items-start">
            <div class="apple-glass rounded-3xl overflow-hidden">
                <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50 text-sm font-bold">Açık uyuşmazlıklar</div>
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Dava</th>
                                <th class="p-4">İlan</th>
                                <th class="p-4">Taraflar</th>
                                <th class="p-4">Açılış</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($open as $dispute)
                                <tr wire:click="select({{ $dispute->id }})" class="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-800/30 {{ $selectedId === $dispute->id ? 'bg-brand-500/5' : '' }}">
                                    <td class="p-4 font-bold">#{{ $dispute->id }}</td>
                                    <td class="p-4">#{{ $dispute->load_id }} · {{ $dispute->cargoLoad?->pickup_location }} → {{ $dispute->cargoLoad?->delivery_location }}<div class="text-[11px] text-neutral-400">{{ number_format((float) ($dispute->cargoLoad?->price ?? 0), 2, ',', '.') }} ₺</div></td>
                                    <td class="p-4 text-neutral-500">{{ $dispute->cargoLoad?->cargoOwnerProfile?->displayName() ?: '—' }} / {{ $dispute->cargoLoad?->driverProfile?->user?->full_name ?? '—' }}</td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500">{{ $dispute->created_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-10 text-center text-neutral-500">Açık uyuşmazlık yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $open->links() }}</div>
            </div>

            @if($selected)
                <div class="apple-glass rounded-3xl p-6 space-y-5 text-xs">
                    <div class="flex justify-between items-start border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                        <div>
                            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Uyuşmazlık #{{ $selected->id }}</h2>
                            <p class="text-[11px] text-neutral-400">İlan #{{ $selected->load_id }} · {{ $selected->cargoLoad?->pickup_location }} → {{ $selected->cargoLoad?->delivery_location }} · {{ number_format((float) ($selected->cargoLoad?->price ?? 0), 2, ',', '.') }} ₺ · {{ $selected->cargoLoad?->escrowLabel() }}</p>
                        </div>
                        <button type="button" wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40 space-y-2">
                            <div class="font-bold">Yük sahibi: {{ $selected->cargoLoad?->cargoOwnerProfile?->displayName() ?: '—' }}</div>
                            <div class="text-[11px] text-neutral-400">{{ $selected->cargoLoad?->cargoOwnerProfile?->user?->email }} · Açan: {{ $selected->opener?->full_name ?? '—' }}</div>
                            <p class="whitespace-pre-line">{{ $selected->cargo_owner_claim }}</p>
                            @if($selected->claim_photo_path)
                                <a href="{{ route('files.dispute', [$selected->id, 'claim']) }}" target="_blank" rel="noopener" class="text-brand-500 font-semibold">Şikayet fotoğrafını aç</a>
                            @else
                                <span class="text-neutral-400">Fotoğraf eklenmedi.</span>
                            @endif
                        </div>
                        <div class="p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40 space-y-2">
                            <div class="font-bold">Şoför: {{ $selected->cargoLoad?->driverProfile?->user?->full_name ?? '—' }}</div>
                            <div class="text-[11px] text-neutral-400">{{ $selected->cargoLoad?->driverProfile?->user?->email }}</div>
                            @if($selected->driver_defense)
                                <p class="whitespace-pre-line">{{ $selected->driver_defense }}</p>
                            @else
                                <p class="text-neutral-400">Şoför henüz savunma yapmadı.</p>
                            @endif
                            @if($selected->driver_proof_photo_path)
                                <a href="{{ route('files.dispute', [$selected->id, 'defense']) }}" target="_blank" rel="noopener" class="text-brand-500 font-semibold">Savunma fotoğrafını aç</a>
                            @endif
                        </div>
                    </div>

                    @if($selected->cargoLoad?->shipment)
                        <div class="text-[11px] text-neutral-500">Sevkiyat: yola çıkış {{ $selected->cargoLoad->shipment->in_transit_at?->format('d.m.Y H:i') ?? '—' }} · teslim {{ $selected->cargoLoad->shipment->delivered_at?->format('d.m.Y H:i') ?? '—' }}</div>
                    @endif

                    <form wire:submit="resolve" class="space-y-3 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Hakem kararı</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $decision === 'driver_paid' ? 'border-emerald-500 bg-emerald-500/5' : 'border-neutral-200/60 dark:border-neutral-700/40' }}">
                                <input type="radio" wire:model="decision" value="driver_paid"> <span>Şoför haklı: hakediş ödenir</span>
                            </label>
                            <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer {{ $decision === 'owner_refunded' ? 'border-amber-500 bg-amber-500/5' : 'border-neutral-200/60 dark:border-neutral-700/40' }}">
                                <input type="radio" wire:model="decision" value="owner_refunded"> <span>Yük sahibi haklı: navlun iade edilir</span>
                            </label>
                        </div>
                        <textarea wire:model="decisionNotes" rows="4" placeholder="Gerekçeli karar notu (taraflara iletilir)" class="{{ $input }}"></textarea>
                        @error('decisionNotes') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                        <button type="submit" wire:confirm="Karar geri alınamaz. Onaylıyor musunuz?" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kararı kaydet</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50 text-sm font-bold">Karara bağlananlar</div>
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Dava</th>
                            <th class="p-4">İlan</th>
                            <th class="p-4">Karar</th>
                            <th class="p-4">Hakem</th>
                            <th class="p-4">Not</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($history as $dispute)
                            <tr>
                                <td class="p-4 font-bold">#{{ $dispute->id }}</td>
                                <td class="p-4">#{{ $dispute->load_id }} · {{ $dispute->cargoLoad?->pickup_location }} → {{ $dispute->cargoLoad?->delivery_location }}</td>
                                <td class="p-4">{{ \App\Models\Dispute::STATUS_LABELS[$dispute->status] ?? $dispute->status }}<div class="text-[11px] text-neutral-400">{{ $dispute->resolved_at?->format('d.m.Y H:i') }}</div></td>
                                <td class="p-4 text-neutral-500">{{ $dispute->resolver?->full_name ?? '—' }}</td>
                                <td class="p-4 text-neutral-500 max-w-xs">{{ \Illuminate\Support\Str::limit((string) $dispute->arbitration_notes, 120) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-10 text-center text-neutral-500">Henüz karara bağlanmış uyuşmazlık yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $history->links() }}</div>
        </div>
    @endif

    @if($activeTab === 'tickets' && $tickets)
        <div class="apple-glass p-3 rounded-2xl">
            <select wire:model.live="ticketStatus" class="{{ $input }} sm:w-56">
                <option value="open">Açık ve yanıtlananlar</option>
                <option value="answered">Yalnız yanıtlananlar</option>
                <option value="closed">Kapatılanlar</option>
            </select>
        </div>

        <div class="grid grid-cols-1 {{ $ticket ? 'xl:grid-cols-2' : '' }} gap-6 items-start">
            <div class="apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Bilet</th>
                                <th class="p-4">Gönderen</th>
                                <th class="p-4">Kategori</th>
                                <th class="p-4">Durum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($tickets as $row)
                                <tr wire:click="selectTicket({{ $row->id }})" class="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-800/30 {{ $selectedTicketId === $row->id ? 'bg-brand-500/5' : '' }}">
                                    <td class="p-4"><span class="font-bold">#{{ $row->id }}</span> {{ \Illuminate\Support\Str::limit((string) $row->subject, 50) ?: '—' }}<div class="text-[11px] text-neutral-400">{{ $row->created_at?->format('d.m.Y H:i') }}</div></td>
                                    <td class="p-4">{{ $row->name }}<div class="text-[11px] text-neutral-400">{{ $row->email }}{{ $row->user ? '' : ' · Üye değil' }}</div></td>
                                    <td class="p-4 text-neutral-500">{{ \App\Models\SupportTicket::CATEGORIES[$row->category] ?? $row->category }}</td>
                                    <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $row->status === 'open' ? 'bg-amber-500/10 text-amber-600' : ($row->status === 'answered' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500') }}">{{ ['open' => 'Açık', 'answered' => 'Yanıtlandı', 'closed' => 'Kapalı'][$row->status] ?? $row->status }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-10 text-center text-neutral-500">Bu durumda bilet yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $tickets->links() }}</div>
            </div>

            @if($ticket)
                <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
                    <div class="flex justify-between items-start border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                        <div>
                            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Bilet #{{ $ticket->id }} · {{ $ticket->subject ?: (\App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? $ticket->category) }}</h2>
                            <p class="text-[11px] text-neutral-400">{{ $ticket->name }} · {{ $ticket->email }}@if($ticket->phone) · {{ $ticket->phone }}@endif · {{ $ticket->role }} · {{ $ticket->created_at?->format('d.m.Y H:i') }}</p>
                        </div>
                        <button type="button" wire:click="closeTicketPanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 whitespace-pre-line">{{ $ticket->message }}</div>

                    @if($ticket->admin_reply)
                        <div class="p-4 rounded-2xl border border-emerald-500/20 bg-emerald-500/5">
                            <div class="text-[11px] text-neutral-400 mb-1">Yanıt · {{ $ticket->assignee?->full_name ?? '—' }} · {{ $ticket->replied_at?->format('d.m.Y H:i') }}</div>
                            <p class="whitespace-pre-line">{{ $ticket->admin_reply }}</p>
                        </div>
                    @endif

                    @if($ticket->status !== 'closed')
                        <form wire:submit="replyTicket" class="space-y-2">
                            <textarea wire:model="reply" rows="4" placeholder="Yanıtınız e-posta ile gönderilir" class="{{ $input }}"></textarea>
                            @error('reply') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                            <div class="flex flex-col sm:flex-row gap-2">
                                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Yanıtla</button>
                                <button type="button" wire:click="closeTicket" wire:confirm="Bilet kapatılacak. Devam edilsin mi?" wire:loading.attr="disabled" class="btn-apple-secondary py-2.5 px-5 text-xs">Kapat</button>
                            </div>
                        </form>
                    @else
                        <p class="text-[11px] text-neutral-400">Bilet {{ $ticket->closed_at?->format('d.m.Y H:i') }} tarihinde kapatıldı.</p>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
