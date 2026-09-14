<?php

use App\Models\Dispute;
use App\Models\Load;
use App\Services\DisputeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Uyuşmazlık Merkezi')]
class extends Component {
    use WithFileUploads, WithPagination;

    public bool $createModalOpen = false;

    public string $selected_load_id = '';

    public string $claim = '';

    public $claim_photo = null;

    public function mount(): void
    {
        if (($preselect = request()->integer('load')) > 0) {
            $this->openModal($preselect);
        }
    }

    public function openModal(?int $loadId = null): void
    {
        $this->resetErrorBag();
        $this->reset(['claim', 'claim_photo']);
        $this->selected_load_id = $loadId ? (string) $loadId : '';
        $this->createModalOpen = true;
    }

    private function eligibleLoadsQuery()
    {
        return Load::query()
            ->where('cargo_owner_profile_id', (int) Auth::user()->cargoOwnerProfile?->id)
            ->whereIn('status', [Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED])
            ->where('escrow_status', Load::ESCROW_PAID)
            ->whereDoesntHave('disputes', fn ($q) => $q->where('status', 'open'));
    }

    public function submitDispute(DisputeService $disputes): void
    {
        $eligibleIds = $this->eligibleLoadsQuery()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->validate([
            'selected_load_id' => ['required', Rule::in($eligibleIds)],
            'claim' => 'required|string|min:20|max:3000',
            'claim_photo' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ], [
            'selected_load_id.required' => 'Lütfen uyuşmazlık konusu sevkiyatı seçin.',
            'selected_load_id.in' => 'Seçilen sevkiyat için uyuşmazlık açılamaz.',
            'claim.required' => 'Lütfen yaşanan sorunu açıklayın.',
            'claim.min' => 'Açıklama en az 20 karakter olmalıdır.',
            'claim_photo.mimes' => 'Dosya JPG, PNG veya PDF olmalıdır.',
            'claim_photo.max' => 'Dosya en fazla 10 MB olabilir.',
        ]);

        $load = $this->eligibleLoadsQuery()->whereKey((int) $this->selected_load_id)->first();
        if (! $load) {
            $this->addError('selected_load_id', 'Seçilen sevkiyat için uyuşmazlık açılamaz.');

            return;
        }

        try {
            $disputes->open($load, Auth::user(), $this->claim, $this->claim_photo);
        } catch (\RuntimeException $e) {
            $this->addError('selected_load_id', $e->getMessage());

            return;
        }

        $this->createModalOpen = false;
        $this->reset(['selected_load_id', 'claim', 'claim_photo']);
        $this->resetPage();
        session()->flash('success_message', 'Uyuşmazlık kaydı açıldı. Havuzdaki ödeme karar verilene kadar askıya alındı; şoförün savunması ve hakem kararı burada görünecek.');
    }

    public function with(): array
    {
        $profileId = (int) Auth::user()->cargoOwnerProfile?->id;

        return [
            'disputes' => Dispute::query()
                ->whereHas('cargoLoad', fn ($q) => $q->where('cargo_owner_profile_id', $profileId))
                ->with(['cargoLoad.driverProfile.user', 'resolver'])
                ->latest()
                ->paginate(15),
            'eligibleLoads' => $this->eligibleLoadsQuery()->with('driverProfile.user')->latest('updated_at')->get(),
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-rose-500/10 text-rose-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </span>
                <span>Uyuşmazlık merkezi</span>
            </h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Hasar, eksik teslimat veya gecikme durumunda uyuşmazlık açın; havuzdaki ödeme karar verilene kadar askıya alınır.</p>
        </div>

        <button type="button" wire:click="openModal" class="px-5 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs shadow-lg shadow-rose-500/20 transition-all flex items-center justify-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Uyuşmazlık aç</span>
        </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400">1. Ödeme askıya alınır</div>
            <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">Uyuşmazlık açıldığında havuzdaki navlun bedeli karar verilene kadar şoföre aktarılmaz.</p>
        </div>
        <div class="p-4 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400">2. Şoför savunma yapar</div>
            <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">Şoför, açıklamanızı görüp savunmasını ve kendi kanıtlarını yükler.</p>
        </div>
        <div class="p-4 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 space-y-1">
            <div class="text-xs font-bold text-brand-400">3. Karar verilir</div>
            <p class="text-[11px] text-neutral-500 dark:text-neutral-400 leading-relaxed">NavlunIQ ekibi kanıtları inceler; karara göre bedel şoföre ödenir veya size iade edilir.</p>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($disputes as $dispute)
            @php $load = $dispute->cargoLoad; @endphp
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-300 font-mono text-xs font-bold">Uyuşmazlık #{{ $dispute->id }}</span>
                        <span class="px-2.5 py-0.5 rounded-full border text-[11px] font-bold
                            {{ $dispute->status === 'open' ? 'bg-amber-500/10 border-amber-500/20 text-amber-400' : ($dispute->status === 'resolved_owner_refunded' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-blue-500/10 border-blue-500/20 text-blue-400') }}">
                            {{ \App\Models\Dispute::STATUS_LABELS[$dispute->status] ?? $dispute->status }}
                        </span>
                    </div>
                    <span class="text-xs text-neutral-500">{{ $dispute->created_at?->format('d.m.Y H:i') }}</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 text-xs">
                    <div class="space-y-2">
                        <div class="text-neutral-500 dark:text-neutral-400">İlgili sevkiyat</div>
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 space-y-1">
                            @if($load)
                                <div class="text-neutral-900 dark:text-white font-bold break-words">#{{ $load->id }} · {{ $load->pickup_location }} &rarr; {{ $load->delivery_location }}</div>
                                <div class="text-neutral-500">Şoför: <span class="text-neutral-700 dark:text-neutral-300 font-medium">{{ $load->driverProfile?->user?->full_name ?: '—' }}</span></div>
                                <div class="text-neutral-500">Havuzdaki tutar: <span class="text-brand-400 tabular-nums font-bold">{{ number_format((float) ($load->price ?? 0), 2, ',', '.') }} ₺</span> · {{ $load->escrowLabel() }}</div>
                                <a href="{{ route('cargo-owner.shipments.show', $load->id) }}" wire:navigate class="text-brand-400 hover:underline inline-block pt-1">Sevkiyatı görüntüle</a>
                            @else
                                <div class="text-neutral-500">Sevkiyat kaydı bulunamadı.</div>
                            @endif
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="text-neutral-500 dark:text-neutral-400">Açıklamanız</div>
                        <div class="p-3 bg-neutral-50 dark:bg-neutral-950 rounded-xl border border-neutral-200 dark:border-neutral-800 text-neutral-700 dark:text-neutral-300 leading-relaxed break-words">{{ $dispute->cargo_owner_claim }}</div>
                        @if($dispute->claim_photo_path)
                            <a href="{{ route('files.dispute', [$dispute->id, 'claim']) }}" target="_blank" rel="noopener" class="text-brand-400 hover:underline inline-block">Yüklediğiniz fotoğraf / belge</a>
                        @endif
                    </div>
                </div>

                @if($dispute->driver_defense || $dispute->driver_proof_photo_path)
                    <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-2 text-xs">
                        <span class="font-bold text-neutral-700 dark:text-neutral-300">Şoförün savunması</span>
                        @if($dispute->driver_defense)
                            <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed break-words">{{ $dispute->driver_defense }}</p>
                        @endif
                        @if($dispute->driver_proof_photo_path)
                            <a href="{{ route('files.dispute', [$dispute->id, 'defense']) }}" target="_blank" rel="noopener" class="text-brand-400 hover:underline inline-block">Şoförün kanıt dosyası</a>
                        @endif
                    </div>
                @elseif($dispute->status === 'open')
                    <p class="text-[11px] text-neutral-500">Şoför henüz savunma yüklemedi.</p>
                @endif

                @if($dispute->status !== 'open')
                    <div class="p-4 rounded-xl bg-neutral-950/80 border border-brand-500/20 space-y-1 text-xs">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-bold text-brand-400">Karar notu</span>
                            <span class="text-neutral-500">{{ $dispute->resolved_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <p class="text-neutral-700 dark:text-neutral-300 leading-relaxed break-words">{{ $dispute->arbitration_notes ?: 'Karar notu girilmedi.' }}</p>
                    </div>
                @endif

            </div>
        @empty
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-3">
                <h4 class="text-base font-bold text-neutral-900 dark:text-white">Henüz uyuşmazlık kaydınız yok</h4>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">Yolda veya teslim edilmiş ve ödemesi havuzda bekleyen sevkiyatlarınız için uyuşmazlık açabilirsiniz.</p>
            </div>
        @endforelse
    </div>

    @if($disputes->hasPages())
        <div class="text-xs">{{ $disputes->links() }}</div>
    @endif

    @if($createModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('createModalOpen', false)"></div>
            <form wire:submit.prevent="submitDispute" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-5 text-left">

                <div class="flex items-center justify-between border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">Uyuşmazlık aç</h3>
                    <button type="button" wire:click="$set('createModalOpen', false)" class="text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">Sevkiyat <span class="text-brand-500">*</span></label>
                        <select wire:model="selected_load_id" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-800 dark:text-neutral-200 focus:border-brand-500 focus:outline-none">
                            <option value="">Sevkiyat seçin</option>
                            @foreach($eligibleLoads as $l)
                                <option value="{{ $l->id }}">#{{ $l->id }} · {{ $l->pickup_location }} &rarr; {{ $l->delivery_location }} ({{ number_format((float) ($l->price ?? 0), 2, ',', '.') }} ₺)</option>
                            @endforeach
                        </select>
                        @if($eligibleLoads->isEmpty())
                            <p class="text-[11px] text-neutral-500 mt-1">Şu anda uyuşmazlık açılabilecek sevkiyatınız yok. Uyuşmazlık yalnız yolda veya teslim edilmiş ve ödemesi havuzda bekleyen sevkiyatlar için açılabilir.</p>
                        @endif
                        @error('selected_load_id') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">Yaşanan sorun <span class="text-brand-500">*</span></label>
                        <textarea wire:model="claim" rows="4" maxlength="3000" placeholder="Hasar, eksik miktar veya gecikme gibi durumu ayrıntılı yazın." class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl p-3 text-neutral-900 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('claim') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1.5">Fotoğraf / belge (isteğe bağlı, JPG, PNG, PDF)</label>
                        <input type="file" wire:model="claim_photo" accept="image/jpeg,image/png,application/pdf" class="w-full text-xs text-neutral-500 dark:text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-800 dark:file:text-neutral-200 hover:file:bg-neutral-300 dark:hover:file:bg-neutral-700 cursor-pointer">
                        <div wire:loading wire:target="claim_photo" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                        @error('claim_photo') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-[11px] text-rose-300 leading-relaxed">
                    Uyuşmazlık açıldığında havuzdaki navlun bedeli karar verilene kadar askıya alınır ve şoföre aktarılmaz.
                </div>

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    <button type="button" wire:click="$set('createModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 text-xs font-semibold transition-colors">Vazgeç</button>
                    <button type="submit" wire:loading.attr="disabled" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold shadow-lg shadow-rose-500/20 transition-all">
                        <span wire:loading.remove wire:target="submitDispute">Uyuşmazlığı aç</span>
                        <span wire:loading wire:target="submitDispute">Gönderiliyor...</span>
                    </button>
                </div>

            </form>
        </div>
    @endif

</div>
