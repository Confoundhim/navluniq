<?php

use App\Models\DriverProfile;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycService;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Kullanıcılar: kayıtlı şoför ve yük sahipleri; belge onayı, premium hediye/kaldırma, engelleme.
 * Görüntüleme "view users"; belge kararı "verify kyc"; premium ve engelleme "manage users".
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'ara')]
    public string $search = '';

    #[Url(as: 'rol')]
    public string $role = '';

    #[Url(as: 'belge')]
    public string $kyc = '';

    #[Url(as: 'premium')]
    public string $premium = '';

    #[Url(as: 'durum')]
    public string $status = '';

    public ?int $openId = null;

    public string $banReason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view users'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'role', 'kyc', 'premium', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $userId): void
    {
        $this->openId = $this->openId === $userId ? null : $userId;
        $this->banReason = '';
    }

    private function allow(string $permission): bool
    {
        if (auth()->user()?->can($permission)) {
            return true;
        }
        session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

        return false;
    }

    private function member(int $userId): ?User
    {
        $user = User::query()->with(['driverProfile', 'cargoOwnerProfile'])->whereKey($userId)->first();
        if (! $user) {
            session()->flash('error_message', 'Kullanıcı bulunamadı.');
        }

        return $user;
    }

    public function approveKyc(int $userId, string $role): void
    {
        if (! $this->allow('verify kyc') || ! ($user = $this->member($userId))) {
            return;
        }
        try {
            app(KycService::class)->approveProfile($user, $role, auth()->user());
            session()->flash('success_message', $user->full_name.' için '.($role === 'driver' ? 'şoför' : 'yük sahibi').' belgeleri onaylandı; kullanıcıya bildirildi.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    public function resetKyc(int $userId, string $role): void
    {
        if (! $this->allow('verify kyc') || ! ($user = $this->member($userId))) {
            return;
        }
        app(KycService::class)->resetProfile($user, $role, auth()->user());
        session()->flash('success_message', $user->full_name.' için belge onayı geri alındı.');
    }

    public function grantPremium(int $userId, int $days): void
    {
        if (! $this->allow('manage users') || ! ($user = $this->member($userId))) {
            return;
        }
        try {
            app(SubscriptionService::class)->grantPremium($user, $days, auth()->user(), 'Kullanıcılar ekranı');
            session()->flash('success_message', $user->full_name.' hesabına '.$days.' gün premium tanımlandı; '.$user->driverProfile->fresh()->premium_until->format('d.m.Y H:i').' tarihine kadar geçerli.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    public function revokePremium(int $userId): void
    {
        if (! $this->allow('manage users') || ! ($user = $this->member($userId))) {
            return;
        }
        app(SubscriptionService::class)->revokePremium($user, auth()->user());
        session()->flash('success_message', $user->full_name.' hesabının premium üyeliği sonlandırıldı.');
    }

    public function ban(int $userId): void
    {
        if (! $this->allow('manage users') || ! ($user = $this->member($userId))) {
            return;
        }
        if ($user->id === auth()->id() || $user->isAdminPanelUser()) {
            session()->flash('error_message', 'Yönetici hesapları buradan engellenemez.');

            return;
        }
        $user->update(['banned_at' => now(), 'ban_reason' => mb_substr(trim($this->banReason) ?: 'Yönetici kararı', 0, 255)]);
        \App\Models\ActivityLog::record('user.banned', "Kullanıcı engellendi #{$user->id}: {$user->ban_reason}", auth()->id(), $user);
        $this->banReason = '';
        session()->flash('success_message', $user->full_name.' engellendi; giriş yapamaz.');
    }

    public function unban(int $userId): void
    {
        if (! $this->allow('manage users') || ! ($user = $this->member($userId))) {
            return;
        }
        $user->update(['banned_at' => null, 'ban_reason' => null]);
        \App\Models\ActivityLog::record('user.unbanned', "Kullanıcı engeli kaldırıldı #{$user->id}", auth()->id(), $user);
        session()->flash('success_message', $user->full_name.' için engel kaldırıldı.');
    }

    private function query(): Builder
    {
        $q = User::query()->with(['driverProfile', 'cargoOwnerProfile', 'roles'])
            ->where(fn (Builder $w) => $w->whereHas('driverProfile')->orWhereHas('cargoOwnerProfile'));

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $digits = preg_replace('/\D+/', '', $this->search) ?: '';
            $q->where(fn (Builder $w) => $w->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)
                ->when($digits !== '', fn (Builder $d) => $d->orWhere('phone', 'like', '%'.$digits.'%'))
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]));
        }
        match ($this->role) {
            'driver' => $q->whereHas('driverProfile'),
            'cargo_owner' => $q->whereHas('cargoOwnerProfile'),
            default => null,
        };
        if ($this->kyc !== '') {
            $q->where(fn (Builder $w) => $w->whereHas('driverProfile', fn ($p) => $p->where('kyc_status', $this->kyc))->orWhereHas('cargoOwnerProfile', fn ($p) => $p->where('kyc_status', $this->kyc)));
        }
        match ($this->premium) {
            'active' => $q->whereHas('driverProfile', fn ($p) => $p->where('premium_until', '>', now())),
            'none' => $q->whereHas('driverProfile', fn ($p) => $p->whereNull('premium_until')->orWhere('premium_until', '<=', now())),
            default => null,
        };
        match ($this->status) {
            'banned' => $q->whereNotNull('banned_at'),
            'active' => $q->whereNull('banned_at'),
            default => null,
        };

        return $q->latest('id');
    }

    public function with(): array
    {
        $users = $this->query()->paginate(20);
        $docCounts = KycDocument::query()->whereIn('user_id', $users->pluck('id'))->where('status', '!=', 'rejected')
            ->selectRaw('user_id, COUNT(*) AS c')->groupBy('user_id')->pluck('c', 'user_id')->all();

        return [
            'users' => $users,
            'docCounts' => $docCounts,
            'stats' => [
                'drivers' => DriverProfile::query()->count(),
                'owners' => \App\Models\CargoOwnerProfile::query()->count(),
                'pending' => DriverProfile::query()->where('kyc_status', 'pending')->count() + \App\Models\CargoOwnerProfile::query()->where('kyc_status', 'pending')->count(),
                'premium' => DriverProfile::query()->where('premium_until', '>', now())->count(),
            ],
            'kycLabels' => ['approved' => 'Onaylı', 'pending' => 'İnceleniyor', 'rejected' => 'Reddedildi', 'unsubmitted' => 'Belge yok'],
            'kycBadge' => ['approved' => 'bg-emerald-500/10 text-emerald-600', 'pending' => 'bg-amber-500/10 text-amber-600', 'rejected' => 'bg-red-500/10 text-red-600', 'unsubmitted' => 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500'],
            'canKyc' => auth()->user()->can('verify kyc'),
            'canManage' => auth()->user()->can('manage users'),
        ];
    }
}; ?>

@php $input = 'form-input'; @endphp
<div class="space-y-6" wire:poll.30s>
    @if(session('success_message'))<div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-xs">{{ session('success_message') }}</div>@endif
    @if(session('error_message'))<div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs">{{ session('error_message') }}</div>@endif

    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-neutral-900 dark:text-white">Kullanıcılar</h1>
            <p class="text-xs text-neutral-500 mt-1">Kayıtlı şoför ve yük sahipleri. Belgeleri tek tıkla onaylayın, premium hediye edin, gerekirse engelleyin. Belge belge inceleme için KYC Evrak Merkezi.</p>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([['Şoför', $stats['drivers'], 'text-neutral-900 dark:text-white'], ['Yük sahibi', $stats['owners'], 'text-neutral-900 dark:text-white'], ['Belge inceleme bekleyen', $stats['pending'], 'text-amber-600'], ['Premium aktif', $stats['premium'], 'text-brand-600']] as [$label, $value, $color])
            <div class="apple-glass rounded-3xl p-5"><span class="text-[11px] text-neutral-400">{{ $label }}</span><div class="text-2xl font-black {{ $color }} tabular-nums">{{ $value }}</div></div>
        @endforeach
    </div>

    <div class="apple-glass rounded-3xl p-4 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ad, telefon, e-posta" class="{{ $input }} md:col-span-2">
        <select wire:model.live="role" class="{{ $input }}"><option value="">Tüm roller</option><option value="driver">Şoförler</option><option value="cargo_owner">Yük sahipleri</option></select>
        <select wire:model.live="kyc" class="{{ $input }}"><option value="">Belge: tümü</option><option value="pending">İnceleniyor</option><option value="approved">Onaylı</option><option value="rejected">Reddedildi</option><option value="unsubmitted">Belge yok</option></select>
        <div class="flex gap-2">
            <select wire:model.live="premium" class="{{ $input }}"><option value="">Premium: tümü</option><option value="active">Premium aktif</option><option value="none">Premium değil</option></select>
            <select wire:model.live="status" class="{{ $input }}"><option value="">Durum: tümü</option><option value="active">Aktif</option><option value="banned">Engelli</option></select>
        </div>
    </div>

    <div class="apple-glass rounded-3xl overflow-hidden">
        <div class="responsive-scroll">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4">Kullanıcı</th><th class="p-4">Belgeler</th><th class="p-4">Premium</th><th class="p-4">Son giriş</th><th class="p-4"></th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                    @forelse($users as $user)
                        @php $dp = $user->driverProfile; $cp = $user->cargoOwnerProfile; $isPremium = $dp?->isPremium() ?? false; @endphp
                        <tr class="align-top {{ $user->banned_at ? 'bg-rose-500/5' : '' }}">
                            <td class="p-4">
                                <div class="font-bold text-neutral-900 dark:text-white">{{ $user->full_name }} @if($user->banned_at)<span class="badge bg-rose-500/10 text-rose-600 ml-1">Engelli</span>@endif</div>
                                <div class="text-[11px] text-neutral-500">{{ \App\Support\Phone::format($user->phone) }} · {{ $user->email }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if($dp)<span class="badge bg-sky-500/10 text-sky-600">Şoför</span>@endif
                                    @if($cp)<span class="badge bg-violet-500/10 text-violet-600">Yük sahibi{{ $cp->type === 'corporate' ? ' (kurumsal)' : '' }}</span>@endif
                                    <span class="text-[11px] text-neutral-400">kayıt {{ $user->created_at?->format('d.m.Y') }}</span>
                                </div>
                            </td>
                            <td class="p-4 space-y-1">
                                @if($dp)<div><span class="text-[11px] text-neutral-400 mr-1">Şoför:</span><span class="badge {{ $kycBadge[$dp->kyc_status] ?? '' }}">{{ $kycLabels[$dp->kyc_status] ?? $dp->kyc_status }}</span></div>@endif
                                @if($cp)<div><span class="text-[11px] text-neutral-400 mr-1">Yük sahibi:</span><span class="badge {{ $kycBadge[$cp->kyc_status] ?? '' }}">{{ $kycLabels[$cp->kyc_status] ?? $cp->kyc_status }}</span></div>@endif
                                <div class="text-[11px] text-neutral-400">{{ $docCounts[$user->id] ?? 0 }} belge yüklü</div>
                            </td>
                            <td class="p-4">
                                @if($dp)
                                    @if($isPremium)<span class="badge bg-brand-500/10 text-brand-600">Premium</span><div class="text-[11px] text-neutral-400">{{ $dp->premium_until->format('d.m.Y H:i') }}'e kadar (<x-time-ago :at="$dp->premium_until" />)</div>
                                    @else<span class="text-neutral-400">Standart</span>@if($dp->premium_until)<div class="text-[11px] text-neutral-400">bitti: {{ $dp->premium_until->format('d.m.Y') }}</div>@endif
                                    @endif
                                @else<span class="text-neutral-400">—</span>@endif
                            </td>
                            <td class="p-4 whitespace-nowrap text-neutral-500"><x-time-ago :at="$user->last_login_at" empty="Hiç" /></td>
                            <td class="p-4 whitespace-nowrap text-right"><button type="button" wire:click="toggle({{ $user->id }})" class="text-brand-600 font-semibold hover:underline">{{ $openId === $user->id ? 'Kapat' : 'İşlemler' }}</button></td>
                        </tr>
                        @if($openId === $user->id)
                            <tr class="bg-neutral-50 dark:bg-neutral-900/40">
                                <td colspan="5" class="p-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                                        <div class="space-y-2">
                                            <div class="font-bold text-neutral-900 dark:text-white">Belgeler</div>
                                            @if($canKyc)
                                                @if($dp)
                                                    @if($dp->kyc_status !== 'approved')<button type="button" wire:click="approveKyc({{ $user->id }}, 'driver')" wire:confirm="Şoför belgeleri eksik olsa bile profil onaylanacak. Devam edilsin mi?" class="btn-primary py-2 px-3 text-xs w-full">Şoför belgelerini onayla</button>
                                                    @else<button type="button" wire:click="resetKyc({{ $user->id }}, 'driver')" class="btn-secondary py-2 px-3 text-xs w-full">Şoför onayını geri al</button>@endif
                                                @endif
                                                @if($cp)
                                                    @if($cp->kyc_status !== 'approved')<button type="button" wire:click="approveKyc({{ $user->id }}, 'cargo_owner')" wire:confirm="Yük sahibi belgeleri eksik olsa bile profil onaylanacak. Devam edilsin mi?" class="btn-primary py-2 px-3 text-xs w-full">Yük sahibi belgelerini onayla</button>
                                                    @else<button type="button" wire:click="resetKyc({{ $user->id }}, 'cargo_owner')" class="btn-secondary py-2 px-3 text-xs w-full">Yük sahibi onayını geri al</button>@endif
                                                @endif
                                                <a href="{{ route('admin.kyc') }}" class="block text-[11px] text-brand-600 hover:underline">Belgeleri tek tek incele →</a>
                                            @else<span class="text-neutral-400">Belge kararı için yetkiniz yok.</span>@endif
                                        </div>
                                        <div class="space-y-2">
                                            <div class="font-bold text-neutral-900 dark:text-white">Premium hediye</div>
                                            @if($canManage && $dp)
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach([7 => '+1 hafta', 30 => '+1 ay', 90 => '+3 ay', 365 => '+1 yıl'] as $d => $l)
                                                        <button type="button" wire:click="grantPremium({{ $user->id }}, {{ $d }})" class="btn-secondary py-2 px-3 text-xs">{{ $l }}</button>
                                                    @endforeach
                                                </div>
                                                <p class="text-[11px] text-neutral-400">Mevcut sürenin üzerine eklenir; kullanıcıya bildirim gider, ödeme kaydı oluşmaz.</p>
                                                @if($isPremium)<button type="button" wire:click="revokePremium({{ $user->id }})" wire:confirm="Premium hemen sonlandırılacak. Devam edilsin mi?" class="text-rose-600 font-semibold hover:underline">Premium'u kaldır</button>@endif
                                            @elseif(! $dp)<span class="text-neutral-400">Premium yalnız şoför profiline tanımlanır.</span>
                                            @else<span class="text-neutral-400">Premium tanımlama için yetkiniz yok.</span>@endif
                                        </div>
                                        <div class="space-y-2">
                                            <div class="font-bold text-neutral-900 dark:text-white">Hesap</div>
                                            @if($canManage)
                                                @if($user->banned_at)
                                                    <p class="text-[11px] text-neutral-500">Engel: {{ $user->ban_reason }} ({{ $user->banned_at->format('d.m.Y H:i') }})</p>
                                                    <button type="button" wire:click="unban({{ $user->id }})" class="btn-secondary py-2 px-3 text-xs">Engeli kaldır</button>
                                                @else
                                                    <input type="text" wire:model="banReason" placeholder="Engel gerekçesi (isteğe bağlı)" class="{{ $input }}">
                                                    <button type="button" wire:click="ban({{ $user->id }})" wire:confirm="Kullanıcı giriş yapamayacak. Devam edilsin mi?" class="text-rose-600 font-semibold hover:underline">Kullanıcıyı engelle</button>
                                                @endif
                                            @else<span class="text-neutral-400">Hesap işlemleri için yetkiniz yok.</span>@endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="p-10 text-center text-neutral-500">Ölçütlere uyan kullanıcı yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $users->links() }}</div>
    </div>
</div>
