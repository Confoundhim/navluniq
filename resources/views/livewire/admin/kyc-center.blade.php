<?php

use App\Models\ActivityLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\GibService;
use App\Services\KycService;
use App\Services\NviService;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $role = 'driver';

    public string $statusFilter = 'pending';

    public string $search = '';

    #[Locked]
    public ?int $selectedId = null;

    /** @var array<int, string> Belge kimliğine göre inceleme notu */
    public array $notes = [];

    public ?array $nviResult = null;

    public ?array $gibResult = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view users'), 403);
    }

    public function updatedRole(): void
    {
        $this->resetPage();
        $this->closePanel();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function select(int $userId): void
    {
        $this->selectedId = $userId;
        $this->notes = [];
        $this->nviResult = null;
        $this->gibResult = null;
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
        $this->notes = [];
        $this->nviResult = null;
        $this->gibResult = null;
    }

    public function review(int $documentId, string $decision): void
    {
        if (! auth()->user()->can('verify kyc')) {
            session()->flash('error_message', 'Belge kararı için "verify kyc" izni gerekir.');

            return;
        }

        $document = $this->selectedId
            ? KycDocument::query()->whereKey($documentId)->where('user_id', $this->selectedId)->first()
            : null;

        if (! $document) {
            session()->flash('error_message', 'Belge bulunamadı.');

            return;
        }

        $note = trim((string) ($this->notes[$documentId] ?? ''));
        if ($decision === 'rejected' && mb_strlen($note) < 5) {
            $this->addError('notes.'.$documentId, 'Ret için en az 5 karakterlik gerekçe yazın.');

            return;
        }

        try {
            app(KycService::class)->review($document, auth()->user(), $decision, $note !== '' ? $note : null);
            unset($this->notes[$documentId]);
            session()->flash('success_message', $document->label().' belgesi '.($decision === 'approved' ? 'onaylandı.' : 'reddedildi.'));
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    public function verifyNvi(): void
    {
        if (! auth()->user()->can('verify kyc')) {
            session()->flash('error_message', 'NVİ sorgusu için "verify kyc" izni gerekir.');

            return;
        }

        $user = $this->selectedId ? User::query()->with('cargoOwnerProfile')->find($this->selectedId) : null;
        $profile = $user?->cargoOwnerProfile;

        if (! $profile || ! $profile->tc_no || ! $profile->birth_year) {
            $this->nviResult = ['success' => false, 'is_match' => false, 'message' => 'T.C. kimlik numarası veya doğum yılı eksik; sorgu yapılamaz.', 'source' => 'Sistem'];

            return;
        }

        $this->nviResult = app(NviService::class)->verify((string) $profile->tc_no, $user->first_name, $user->last_name, (string) $profile->birth_year);

        if ($this->nviResult['success'] && $this->nviResult['is_match'] && ! $profile->nvi_verified) {
            $profile->update(['nvi_verified' => true]);
            ActivityLog::record('kyc.nvi_verified', "NVİ kimlik doğrulaması eşleşti (kullanıcı #{$user->id})", auth()->id(), $profile);
        }
    }

    public function verifyGib(): void
    {
        if (! auth()->user()->can('verify kyc')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $user = $this->selectedId ? User::query()->with('cargoOwnerProfile')->find($this->selectedId) : null;
        $profile = $user?->cargoOwnerProfile;

        if (! $profile || ! $profile->tax_no) {
            $this->gibResult = ['is_match' => false, 'message' => 'Vergi kimlik numarası kayıtlı değil.'];

            return;
        }

        $result = app(GibService::class)->verifyTax((string) $profile->tax_no);
        $this->gibResult = ['is_match' => (bool) $result['is_match'], 'message' => (string) $result['message']];
    }

    private function mask(?string $value, int $head = 3, int $tail = 2): string
    {
        $value = (string) $value;
        if ($value === '') {
            return 'Kayıtlı değil';
        }
        if (strlen($value) <= $head + $tail) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, $head).str_repeat('*', strlen($value) - $head - $tail).substr($value, -$tail);
    }

    public function with(): array
    {
        $relation = $this->role === 'driver' ? 'driverProfile' : 'cargoOwnerProfile';

        $query = User::query()
            ->whereHas($relation, function ($q): void {
                if ($this->statusFilter !== 'all') {
                    $q->where('kyc_status', $this->statusFilter);
                }
            })
            ->with($this->role === 'driver' ? ['driverProfile.activeVehicle'] : ['cargoOwnerProfile']);

        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByRaw('(select kyc_submitted_at from '.($this->role === 'driver' ? 'driver_profiles' : 'cargo_owner_profiles').' p where p.user_id = users.id limit 1) asc')
            ->orderBy('id')
            ->paginate(15);

        $selected = null;
        $documents = collect();
        $profile = null;
        if ($this->selectedId) {
            $selected = User::query()->with(['driverProfile.activeVehicle', 'cargoOwnerProfile'])->find($this->selectedId);
            if ($selected) {
                $profile = $this->role === 'driver' ? $selected->driverProfile : $selected->cargoOwnerProfile;
                $types = $this->role === 'driver' ? array_keys(KycDocument::DRIVER_TYPES) : array_keys(KycDocument::CARGO_OWNER_TYPES);
                $documents = KycDocument::query()->where('user_id', $selected->id)->whereIn('document_type', $types)
                    ->with('reviewer')->orderBy('document_type')->get();
            }
        }

        return [
            'users' => $users,
            'selected' => $selected,
            'profile' => $profile,
            'documents' => $documents,
            'maskedTc' => $profile ? $this->mask($profile->tc_no ?? null) : null,
            'maskedVkn' => $profile ? $this->mask($profile->tax_no ?? null) : null,
            'canVerify' => auth()->user()->can('verify kyc'),
            'kycLabels' => ['pending' => 'İnceleniyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'unsubmitted' => 'Gönderilmedi'],
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $badge = ['pending' => 'bg-amber-500/10 text-amber-600', 'approved' => 'bg-emerald-500/10 text-emerald-600', 'rejected' => 'bg-red-500/10 text-red-600', 'unsubmitted' => 'bg-neutral-500/10 text-neutral-500'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">KYC ve Evrak Doğrulama</h1>
        <p class="page-subtitle">Belgeler tek tek incelenir; zorunlu belgelerin tamamı onaylanınca profil otomatik onaylanır.</p>
    </div>

    <div class="flex flex-col lg:flex-row lg:items-center gap-3 apple-glass p-3 rounded-2xl">
        <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
            <button type="button" wire:click="$set('role', 'driver')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $role === 'driver' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Şoförler</button>
            <button type="button" wire:click="$set('role', 'cargo_owner')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $role === 'cargo_owner' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Yük sahipleri</button>
        </div>
        <select wire:model.live="statusFilter" class="{{ $input }} lg:w-44">
            <option value="pending">İncelenmeyi bekleyenler</option>
            <option value="approved">Onaylananlar</option>
            <option value="rejected">Reddedilenler</option>
            <option value="unsubmitted">Belge göndermeyenler</option>
            <option value="all">Tümü</option>
        </select>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Ad, e-posta veya telefon" class="{{ $input }} lg:flex-1">
    </div>

    <div class="grid grid-cols-1 {{ $selected ? 'xl:grid-cols-2' : '' }} gap-6 items-start">
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Kullanıcı</th>
                            <th class="p-4">{{ $role === 'driver' ? 'Aktif araç' : 'Tür' }}</th>
                            <th class="p-4">Başvuru</th>
                            <th class="p-4">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($users as $user)
                            @php $p = $role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile; @endphp
                            <tr wire:click="select({{ $user->id }})" class="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-800/30 {{ $selectedId === $user->id ? 'bg-brand-500/5' : '' }}">
                                <td class="p-4">
                                    <div class="font-bold text-neutral-900 dark:text-white">{{ $user->full_name }}</div>
                                    <div class="text-[11px] text-neutral-400">{{ $user->email }}</div>
                                </td>
                                <td class="p-4 text-neutral-500">
                                    @if($role === 'driver')
                                        {{ $p?->activeVehicle?->plate ?? 'Araç kaydı yok' }}
                                    @else
                                        {{ $p?->type === 'corporate' ? 'Kurumsal' : 'Bireysel' }}
                                    @endif
                                </td>
                                <td class="p-4 text-neutral-500 whitespace-nowrap">{{ $p?->kyc_submitted_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td class="p-4">
                                    @php $st = $p?->kyc_status ?? 'unsubmitted'; @endphp
                                    <span class="px-2.5 py-1 rounded-full font-semibold text-[10px] {{ $badge[$st] ?? '' }}">{{ $kycLabels[$st] ?? $st }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-10 text-center text-neutral-500">Bu filtreye uyan kullanıcı yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $users->links() }}</div>
        </div>

        @if($selected)
            <div class="apple-glass rounded-3xl p-6 space-y-6">
                <div class="flex justify-between items-start border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                    <div>
                        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">{{ $selected->full_name }}</h2>
                        <p class="text-[11px] text-neutral-400">{{ $selected->email }} · {{ \App\Support\Phone::format($selected->phone) }}</p>
                    </div>
                    <button type="button" wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if(! $profile)
                    <p class="text-xs text-neutral-500">Bu kullanıcının {{ $role === 'driver' ? 'şoför' : 'yük sahibi' }} profili yok.</p>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs bg-neutral-50 dark:bg-neutral-900 p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40">
                        <div><span class="text-neutral-400 block">Profil durumu</span><span class="font-bold">{{ $kycLabels[$profile->kyc_status] ?? $profile->kyc_status }}</span></div>
                        <div><span class="text-neutral-400 block">Başvuru tarihi</span><span class="font-bold">{{ $profile->kyc_submitted_at?->format('d.m.Y H:i') ?? '—' }}</span></div>
                        @if($role === 'cargo_owner')
                            <div><span class="text-neutral-400 block">Tür</span><span class="font-bold">{{ $profile->type === 'corporate' ? 'Kurumsal' : 'Bireysel' }}</span></div>
                            <div><span class="text-neutral-400 block">T.C. kimlik no</span><span class="font-bold tracking-wider">{{ $maskedTc }}</span></div>
                            <div><span class="text-neutral-400 block">Doğum yılı</span><span class="font-bold">{{ $profile->birth_year ?: 'Kayıtlı değil' }}</span></div>
                            <div><span class="text-neutral-400 block">NVİ doğrulaması</span><span class="font-bold">{{ $profile->nvi_verified ? 'Eşleşti' : 'Yapılmadı' }}</span></div>
                            @if($profile->type === 'corporate')
                                <div><span class="text-neutral-400 block">Vergi kimlik no</span><span class="font-bold tracking-wider">{{ $maskedVkn }}</span></div>
                                <div><span class="text-neutral-400 block">Şirket unvanı</span><span class="font-bold">{{ $profile->company_title ?: 'Kayıtlı değil' }}</span></div>
                                <div><span class="text-neutral-400 block">Vergi dairesi</span><span class="font-bold">{{ $profile->tax_office ?: 'Kayıtlı değil' }}</span></div>
                            @endif
                        @else
                            <div><span class="text-neutral-400 block">Aktif araç</span><span class="font-bold">{{ $profile->activeVehicle ? $profile->activeVehicle->plate.' · '.$profile->activeVehicle->brand.' '.$profile->activeVehicle->model : 'Araç kaydı yok' }}</span></div>
                            <div><span class="text-neutral-400 block">Premium</span><span class="font-bold">{{ $profile->isPremium() ? 'Aktif ('.$profile->premium_until->format('d.m.Y').' tarihine kadar)' : 'Yok' }}</span></div>
                        @endif
                        @if($profile->kyc_notes)
                            <div class="sm:col-span-2"><span class="text-neutral-400 block">Son not</span><span>{{ $profile->kyc_notes }}</span></div>
                        @endif
                    </div>

                    @if($role === 'cargo_owner')
                        <div class="space-y-3">
                            @if($profile->type !== 'corporate' || $profile->tc_no)
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-neutral-50 dark:bg-neutral-900 p-3.5 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40">
                                    <div class="text-xs">
                                        <span class="font-semibold block">NVİ kimlik sorgusu</span>
                                        <span class="text-[11px] text-neutral-400">Ad, soyad, T.C. no ve doğum yılı KPS servisine gönderilir.</span>
                                    </div>
                                    <button type="button" wire:click="verifyNvi" wire:loading.attr="disabled" @disabled(! $canVerify || ! $profile->birth_year || ! $profile->tc_no) class="btn-apple-secondary py-1.5 px-3 text-[11px] disabled:opacity-40">Sorgula</button>
                                </div>
                                @if(! $profile->birth_year)
                                    <p class="text-[11px] text-amber-600">Doğum yılı kayıtlı olmadığından NVİ sorgusu yapılamaz.</p>
                                @endif
                                @if($nviResult)
                                    <div class="p-3 rounded-xl text-[11px] {{ $nviResult['is_match'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600' }}">{{ $nviResult['message'] }} <span class="text-neutral-400">({{ $nviResult['source'] }})</span></div>
                                @endif
                            @endif

                            @if($profile->type === 'corporate')
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-neutral-50 dark:bg-neutral-900 p-3.5 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40">
                                    <div class="text-xs">
                                        <span class="font-semibold block">VKN algoritma kontrolü</span>
                                        <span class="text-[11px] text-neutral-400">Yalnız numaranın biçimsel geçerliliğini denetler; unvan ve vergi dairesi vergi levhasından teyit edilir.</span>
                                    </div>
                                    <button type="button" wire:click="verifyGib" wire:loading.attr="disabled" @disabled(! $profile->tax_no) class="btn-apple-secondary py-1.5 px-3 text-[11px] disabled:opacity-40">Kontrol et</button>
                                </div>
                                @if($gibResult)
                                    <div class="p-3 rounded-xl text-[11px] {{ $gibResult['is_match'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600' }}">{{ $gibResult['message'] }}</div>
                                @endif
                            @endif
                        </div>
                    @endif

                    <div class="space-y-3">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">Yüklenen belgeler</h3>
                        @forelse($documents as $doc)
                            <div class="p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40 space-y-3 text-xs">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <div>
                                        <span class="font-bold text-neutral-900 dark:text-white">{{ $doc->label() }}</span>
                                        <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $badge[$doc->status] ?? '' }}">{{ \App\Models\KycDocument::STATUS_LABELS[$doc->status] ?? $doc->status }}</span>
                                        <div class="text-[11px] text-neutral-400 mt-1">Yüklendi: {{ $doc->created_at?->format('d.m.Y H:i') }}@if($doc->expires_at) · Geçerlilik: {{ $doc->expires_at->format('d.m.Y') }}@endif</div>
                                        @if($doc->reviewed_at)
                                            <div class="text-[11px] text-neutral-400">İnceleyen: {{ $doc->reviewer?->full_name ?? '—' }} · {{ $doc->reviewed_at->format('d.m.Y H:i') }}@if($doc->review_notes) · {{ $doc->review_notes }}@endif</div>
                                        @endif
                                    </div>
                                    <a href="{{ route('files.kyc', $doc->id) }}" target="_blank" rel="noopener" class="btn-apple-secondary py-1.5 px-3 text-[11px] text-center">Belgeyi aç</a>
                                </div>
                                @if($canVerify && $doc->status !== 'approved')
                                    <div class="space-y-2">
                                        <input type="text" wire:model="notes.{{ $doc->id }}" placeholder="İnceleme notu (ret için zorunlu)" class="{{ $input }}">
                                        @error('notes.'.$doc->id) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                                        <div class="flex flex-col sm:flex-row gap-2">
                                            <button type="button" wire:click="review({{ $doc->id }}, 'approved')" wire:loading.attr="disabled" class="flex-1 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold">Onayla</button>
                                            <button type="button" wire:click="review({{ $doc->id }}, 'rejected')" wire:loading.attr="disabled" class="flex-1 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-[11px] font-semibold">Reddet</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-neutral-500 p-4 rounded-2xl border border-dashed border-neutral-300 dark:border-neutral-700">Bu rol için yüklenmiş belge yok.</p>
                        @endforelse
                        @if(! $canVerify)
                            <p class="text-[11px] text-neutral-400">Belge kararı vermek için "verify kyc" izni gerekir; bu hesap yalnız görüntüleyebilir.</p>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
