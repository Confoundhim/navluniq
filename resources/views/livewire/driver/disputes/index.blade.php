<?php

use App\Models\Dispute;
use App\Models\SupportTicket;
use App\Services\DisputeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('components.layouts.driver')]
#[Title('Uyuşmazlık ve Destek')]
class extends Component {
    use WithFileUploads, WithPagination;

    #[Locked]
    public ?int $defendingId = null;

    public string $defense = '';

    public $defense_photo = null;

    public bool $ticketFormOpen = false;

    public string $ticket_category = 'other';

    public string $ticket_subject = '';

    public string $ticket_message = '';

    private function disputeQuery(): Builder
    {
        $profileId = Auth::user()->driverProfile?->id ?? 0;

        return Dispute::query()
            ->with(['cargoLoad.cargoOwnerProfile.user'])
            ->whereHas('cargoLoad', fn (Builder $q) => $q->where('driver_profile_id', $profileId));
    }

    public function mount(): void
    {
        $this->ticketFormOpen = request()->boolean('ticket');
    }

    public function openDefense(int $disputeId): void
    {
        $dispute = $this->disputeQuery()->whereKey($disputeId)->first();
        if (! $dispute || $dispute->status !== 'open') {
            session()->flash('error_message', 'Yalnız incelenmekte olan uyuşmazlıklara savunma eklenebilir.');

            return;
        }

        $this->defendingId = $dispute->id;
        $this->defense = (string) ($dispute->driver_defense ?? '');
        $this->defense_photo = null;
        $this->resetErrorBag();
    }

    public function closeDefense(): void
    {
        $this->reset(['defendingId', 'defense', 'defense_photo']);
        $this->resetErrorBag();
    }

    public function submitDefense(DisputeService $disputes): void
    {
        $this->validate([
            'defense' => 'required|string|min:20|max:3000',
            'defense_photo' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'defense.required' => 'Savunma metni zorunludur.',
            'defense.min' => 'Savunma en az 20 karakter olmalıdır.',
            'defense_photo.mimes' => 'Kanıt JPG, PNG veya PDF olmalıdır.',
            'defense_photo.max' => 'Kanıt dosyası en fazla 10 MB olabilir.',
        ]);

        $profile = Auth::user()->driverProfile;
        $dispute = $this->defendingId ? $this->disputeQuery()->whereKey($this->defendingId)->first() : null;

        if (! $profile || ! $dispute) {
            $this->addError('defense', 'Uyuşmazlık bulunamadı.');

            return;
        }

        try {
            $disputes->defend($dispute, $profile, $this->defense, $this->defense_photo);
        } catch (\RuntimeException $e) {
            $this->addError('defense', $e->getMessage());

            return;
        }

        $this->closeDefense();
        session()->flash('success_message', 'Savunmanız kaydedildi. Hakem incelemesi tamamlandığında bilgilendirileceksiniz.');
    }

    public function submitTicket(): void
    {
        $this->validate([
            'ticket_category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'ticket_subject' => 'nullable|string|max:150',
            'ticket_message' => 'required|string|min:15|max:3000',
        ], [
            'ticket_message.required' => 'Mesaj zorunludur.',
            'ticket_message.min' => 'Mesajınız en az 15 karakter olmalıdır.',
        ]);

        $user = Auth::user();

        SupportTicket::create([
            'user_id' => $user->id,
            'name' => $user->full_name,
            'email' => (string) $user->email,
            'phone' => $user->phone,
            'role' => 'driver',
            'category' => $this->ticket_category,
            'subject' => trim($this->ticket_subject) ?: null,
            'message' => trim($this->ticket_message),
            'status' => 'open',
        ]);

        $this->reset(['ticketFormOpen', 'ticket_category', 'ticket_subject', 'ticket_message']);
        session()->flash('success_message', 'Destek talebiniz oluşturuldu. Destek ekibi yanıtladığında burada görünür.');
    }

    public function with(): array
    {
        return [
            'disputes' => $this->disputeQuery()->latest('id')->paginate(15),
            'defendingDispute' => $this->defendingId ? $this->disputeQuery()->whereKey($this->defendingId)->first() : null,
            'tickets' => SupportTicket::query()->where('user_id', Auth::id())->latest('id')->take(20)->get(),
            'categories' => SupportTicket::CATEGORIES,
        ];
    }
}; ?>

<div wire:poll.15s class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <h2 class="page-title">Uyuşmazlık ve Destek</h2>
        <p class="page-subtitle">Sevkiyatlarınız için açılan uyuşmazlıklara savunma ekleyin, destek ekibine talep iletin.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-3">
                <h3 class="section-title">Uyuşmazlıklar</h3>

                @forelse($disputes as $dispute)
                    @php $dLoad = $dispute->cargoLoad; @endphp
                    <div class="p-4 bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl space-y-3 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div class="text-sm font-bold text-neutral-900 dark:text-white">
                                @if($dLoad)
                                    {{ $dLoad->pickup_location }} <span class="text-brand-500">&rarr;</span> {{ $dLoad->delivery_location }}
                                @else
                                    İlan kaldırılmış
                                @endif
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold border
                                {{ $dispute->status === 'open' ? 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400' : ($dispute->status === 'resolved_driver_paid' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400') }}">
                                {{ \App\Models\Dispute::STATUS_LABELS[$dispute->status] ?? $dispute->status }}
                            </span>
                        </div>
                        <div class="text-neutral-500">
                            Açılış: {{ $dispute->created_at?->format('d.m.Y H:i') }}
                            @if($dLoad) · Yük sahibi: {{ $dLoad->cargoOwnerProfile?->displayName() ?: 'Belirtilmemiş' }} @endif
                        </div>

                        <div class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
                            <div class="text-[11px] uppercase text-neutral-500 font-bold">Yük sahibinin iddiası</div>
                            <div class="text-neutral-800 dark:text-neutral-200 leading-relaxed">{{ $dispute->cargo_owner_claim }}</div>
                            @if($dispute->claim_photo_path)
                                <a href="{{ route('files.dispute', [$dispute->id, 'claim']) }}" target="_blank" rel="noopener" class="inline-block text-brand-400 font-bold hover:underline">İddia fotoğrafını görüntüle</a>
                            @endif
                        </div>

                        @if($dispute->driver_defense)
                            <div class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
                                <div class="text-[11px] uppercase text-neutral-500 font-bold">Savunmanız</div>
                                <div class="text-neutral-800 dark:text-neutral-200 leading-relaxed">{{ $dispute->driver_defense }}</div>
                                @if($dispute->driver_proof_photo_path)
                                    <a href="{{ route('files.dispute', [$dispute->id, 'defense']) }}" target="_blank" rel="noopener" class="inline-block text-brand-400 font-bold hover:underline">Kanıtınızı görüntüle</a>
                                @endif
                            </div>
                        @endif

                        @if($dispute->status !== 'open')
                            <div class="p-3 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
                                <div class="text-[11px] uppercase text-neutral-500 font-bold">Hakem kararı</div>
                                <div class="text-neutral-800 dark:text-neutral-200 leading-relaxed">{{ $dispute->arbitration_notes ?: 'Karar notu girilmedi.' }}</div>
                                @if($dispute->resolved_at)
                                    <div class="text-[11px] text-neutral-500">{{ $dispute->resolved_at->format('d.m.Y H:i') }}</div>
                                @endif
                            </div>
                        @else
                            <div class="flex flex-col sm:flex-row gap-2 pt-1">
                                <button type="button" wire:click="openDefense({{ $dispute->id }})" class="px-4 py-2 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold">
                                    {{ $dispute->driver_defense ? 'Savunmayı güncelle' : 'Savunma yap' }}
                                </button>
                                @if($dLoad)
                                    <a href="{{ route('driver.shipments.show', $dLoad->id) }}" wire:navigate class="px-4 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-900 dark:text-white font-bold text-center">Sevkiyatı aç</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-6 bg-neutral-50 dark:bg-neutral-950 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-xl text-center text-xs text-neutral-500 dark:text-neutral-400">Sevkiyatlarınız için açılmış uyuşmazlık yok.</div>
                @endforelse

                @if($disputes->hasPages())
                    <div class="pt-2">{{ $disputes->links() }}</div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4 text-xs">
                <div class="flex items-center justify-between">
                    <h3 class="section-title">Destek talepleri</h3>
                    <button type="button" wire:click="$set('ticketFormOpen', {{ $ticketFormOpen ? 'false' : 'true' }})" class="text-brand-400 font-bold hover:underline">{{ $ticketFormOpen ? 'Kapat' : 'Yeni talep' }}</button>
                </div>

                @if($ticketFormOpen)
                    <form wire:submit.prevent="submitTicket" class="space-y-3 border-b border-neutral-200 dark:border-neutral-800 pb-4">
                        <div>
                            <label class="form-label">Kategori</label>
                            <select wire:model="ticket_category" class="form-input">
                                @foreach($categories as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('ticket_category') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="form-label">Konu (isteğe bağlı)</label>
                            <input type="text" wire:model="ticket_subject" maxlength="150" class="form-input">
                            @error('ticket_subject') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="form-label">Mesaj</label>
                            <textarea wire:model="ticket_message" rows="4" maxlength="3000" class="form-input"></textarea>
                            @error('ticket_message') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">Talebi gönder</button>
                    </form>
                @endif

                @forelse($tickets as $ticket)
                    <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-neutral-900 dark:text-white font-semibold">{{ $ticket->subject ?: ($categories[$ticket->category] ?? $ticket->category) }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold border
                                {{ $ticket->status === 'answered' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : ($ticket->status === 'closed' ? 'bg-neutral-100 dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300' : 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400') }}">
                                {{ ['open' => 'Açık', 'answered' => 'Yanıtlandı', 'closed' => 'Kapatıldı'][$ticket->status] ?? $ticket->status }}
                            </span>
                        </div>
                        <div class="text-[11px] text-neutral-500">{{ $categories[$ticket->category] ?? $ticket->category }} · {{ $ticket->created_at?->format('d.m.Y H:i') }}</div>
                        <div class="text-neutral-700 dark:text-neutral-300 leading-relaxed">{{ $ticket->message }}</div>
                        @if($ticket->admin_reply)
                            <div class="mt-2 p-2 rounded-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800">
                                <div class="text-[11px] uppercase text-neutral-500 font-bold">Destek yanıtı @if($ticket->replied_at) · {{ $ticket->replied_at->format('d.m.Y H:i') }} @endif</div>
                                <div class="text-neutral-800 dark:text-neutral-200 leading-relaxed">{{ $ticket->admin_reply }}</div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-neutral-500">Henüz destek talebiniz yok.</div>
                @endforelse
            </div>
        </div>
    </div>

    @if($defendingDispute)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeDefense"></div>
            <form wire:submit.prevent="submitDefense" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Savunma</h3>
                    <p class="text-neutral-500 dark:text-neutral-400 mt-0.5">
                        @if($defendingDispute->cargoLoad)
                            {{ $defendingDispute->cargoLoad->pickup_location }} &rarr; {{ $defendingDispute->cargoLoad->delivery_location }}
                        @endif
                    </p>
                </div>
                <div>
                    <label class="form-label">Savunma metni (en az 20 karakter)</label>
                    <textarea wire:model="defense" rows="5" maxlength="3000" class="form-input"></textarea>
                    @error('defense') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">Kanıt fotoğrafı veya belgesi (isteğe bağlı)</label>
                    <input type="file" wire:model="defense_photo" accept="image/jpeg,image/png,application/pdf" class="w-full text-neutral-500 dark:text-neutral-400 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-900 dark:file:text-white">
                    @error('defense_photo') <span class="form-error">{{ $message }}</span> @enderror
                    <div wire:loading wire:target="defense_photo" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="closeDefense" class="btn-secondary flex-1">Vazgeç</button>
                    <button type="submit" class="btn-primary flex-1" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitDefense">Savunmayı gönder</span>
                        <span wire:loading wire:target="submitDefense">Gönderiliyor...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
