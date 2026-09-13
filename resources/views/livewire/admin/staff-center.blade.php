<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new class extends Component {
    use WithPagination;

    public const ROLE_LABELS = [
        'super_admin' => 'Süper yönetici',
        'kyc_validator' => 'KYC doğrulayıcı',
        'financial_officer' => 'Finans sorumlusu',
        'support_agent' => 'Destek temsilcisi',
    ];

    public string $activeTab = 'staff';

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $role = 'support_agent';

    /** @var array<string, array<int, string>> Rol adına göre seçili izinler */
    public array $rolePermissions = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage staff'), 403);
        $this->loadRolePermissions();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    private function assignableRoles(): array
    {
        $roles = User::ADMIN_PANEL_ROLES;
        if (! auth()->user()->hasRole('super_admin')) {
            $roles = array_values(array_diff($roles, ['super_admin']));
        }

        return $roles;
    }

    private function loadRolePermissions(): void
    {
        $this->rolePermissions = [];
        foreach (Role::query()->whereIn('name', User::ADMIN_PANEL_ROLES)->with('permissions')->get() as $role) {
            $this->rolePermissions[$role->name] = $role->permissions->pluck('name')->all();
        }
    }

    public function createStaff(): void
    {
        $phone = Phone::normalize($this->phone);

        $this->validate([
            'firstName' => 'required|string|min:2|max:60',
            'lastName' => 'required|string|min:2|max:60',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => ['required', Phone::RULE],
            'password' => 'required|string|min:12|max:255',
            'role' => ['required', Rule::in($this->assignableRoles())],
        ], [
            'email.unique' => 'Bu e-posta ile kayıtlı bir kullanıcı zaten var.',
            'phone.regex' => 'Geçerli bir Türkiye cep telefonu numarası girin.',
            'password.min' => 'Şifre en az 12 karakter olmalıdır.',
            'role.in' => 'Bu rolü atama yetkiniz yok.',
        ]);

        if (! $phone || User::query()->whereIn('phone', Phone::variants($phone))->exists()) {
            $this->addError('phone', 'Bu telefon numarası zaten kayıtlı.');

            return;
        }

        $user = User::create([
            'first_name' => trim($this->firstName),
            'last_name' => trim($this->lastName),
            'email' => mb_strtolower(trim($this->email)),
            'phone' => $phone,
            'password' => $this->password,
            'current_role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($this->role);

        ActivityLog::record('staff.created', "Personel hesabı oluşturuldu: {$user->full_name} ({$this->role})", auth()->id(), $user);
        $this->reset(['firstName', 'lastName', 'email', 'phone', 'password']);
        session()->flash('success_message', 'Personel hesabı oluşturuldu. Girişte e-posta doğrulama kodu istenir.');
    }

    public function toggleActive(int $userId): void
    {
        if ($userId === auth()->id()) {
            session()->flash('error_message', 'Kendi hesabınızı pasife alamazsınız.');

            return;
        }

        $user = User::query()->where('current_role', 'admin')->find($userId);
        if (! $user) {
            session()->flash('error_message', 'Personel bulunamadı.');

            return;
        }
        if ($user->hasRole('super_admin') && ! auth()->user()->hasRole('super_admin')) {
            session()->flash('error_message', 'Süper yönetici hesabını yalnız başka bir süper yönetici değiştirebilir.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        ActivityLog::record('staff.toggled', "Personel {$user->full_name} ".($user->is_active ? 'aktif edildi' : 'pasife alındı'), auth()->id(), $user);
        session()->flash('success_message', 'Hesap durumu güncellendi.');
    }

    public function savePermissions(string $roleName): void
    {
        if (! in_array($roleName, User::ADMIN_PANEL_ROLES, true) || $roleName === 'super_admin') {
            session()->flash('error_message', 'Bu rolün izinleri düzenlenemez.');

            return;
        }
        if (! auth()->user()->hasRole('super_admin')) {
            session()->flash('error_message', 'Rol izinlerini yalnız süper yönetici düzenleyebilir.');

            return;
        }

        $role = Role::query()->where('name', $roleName)->first();
        if (! $role) {
            return;
        }

        $valid = Permission::query()->pluck('name')->all();
        $selected = array_values(array_intersect($valid, $this->rolePermissions[$roleName] ?? []));
        $role->syncPermissions($selected);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ActivityLog::record('role.permissions_updated', "{$roleName} rolü izinleri güncellendi: ".implode(', ', $selected), auth()->id(), $role);
        $this->loadRolePermissions();
        session()->flash('success_message', (self::ROLE_LABELS[$roleName] ?? $roleName).' izinleri kaydedildi.');
    }

    public function with(): array
    {
        return [
            'roleLabels' => self::ROLE_LABELS,
            'assignable' => $this->assignableRoles(),
            'staff' => $this->activeTab === 'staff' ? User::query()->where('current_role', 'admin')->with('roles')->orderBy('id')->paginate(15) : null,
            'permissions' => $this->activeTab === 'roles' ? Permission::query()->orderBy('name')->pluck('name')->all() : [],
            'isSuperAdmin' => auth()->user()->hasRole('super_admin'),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-6">
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
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Personel ve İzinler</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Panele yalnız tanımlı personel rolleri girebilir; yeni rol türü eklemek kod değişikliği gerektirir.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        <button type="button" wire:click="$set('activeTab', 'staff')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'staff' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Personel</button>
        <button type="button" wire:click="$set('activeTab', 'roles')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'roles' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Rol izinleri</button>
    </div>

    @if($activeTab === 'staff')
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="createStaff" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yeni personel</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="text-[11px] font-semibold text-neutral-500">Ad</label><input type="text" wire:model="firstName" class="{{ $input }}">@error('firstName') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                    <div><label class="text-[11px] font-semibold text-neutral-500">Soyad</label><input type="text" wire:model="lastName" class="{{ $input }}">@error('lastName') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                </div>
                <div><label class="text-[11px] font-semibold text-neutral-500">E-posta</label><input type="email" wire:model="email" class="{{ $input }}">@error('email') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <div><label class="text-[11px] font-semibold text-neutral-500">Telefon</label><input type="text" wire:model="phone" placeholder="05XX XXX XX XX" class="{{ $input }}">@error('phone') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <div><label class="text-[11px] font-semibold text-neutral-500">Şifre (en az 12 karakter)</label><input type="password" wire:model="password" autocomplete="new-password" class="{{ $input }}">@error('password') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <div>
                    <label class="text-[11px] font-semibold text-neutral-500">Rol</label>
                    <select wire:model="role" class="{{ $input }}">
                        @foreach($assignable as $name)
                            <option value="{{ $name }}">{{ $roleLabels[$name] ?? $name }}</option>
                        @endforeach
                    </select>
                    @error('role') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Hesabı oluştur</button>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Personel</th>
                                <th class="p-4">Roller</th>
                                <th class="p-4">Son giriş</th>
                                <th class="p-4">Durum</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($staff as $member)
                                <tr>
                                    <td class="p-4"><div class="font-bold">{{ $member->full_name }}</div><div class="text-[11px] text-neutral-400">{{ $member->email }} · {{ \App\Support\Phone::format($member->phone) }}</div></td>
                                    <td class="p-4">{{ $member->getRoleNames()->map(fn ($r) => $roleLabels[$r] ?? $r)->join(', ') ?: 'Rol atanmadı' }}</td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500">{{ $member->last_login_at?->format('d.m.Y H:i') ?? 'Henüz giriş yapmadı' }}</td>
                                    <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $member->is_active && ! $member->banned_at ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600' }}">{{ $member->banned_at ? 'Yasaklı' : ($member->is_active ? 'Aktif' : 'Pasif') }}</span></td>
                                    <td class="p-4 whitespace-nowrap">
                                        @if($member->id !== auth()->id())
                                            <button type="button" wire:click="toggleActive({{ $member->id }})" wire:confirm="Hesap durumu değiştirilecek. Devam edilsin mi?" class="text-brand-500 font-semibold">{{ $member->is_active ? 'Pasife al' : 'Aktif et' }}</button>
                                        @else
                                            <span class="text-[11px] text-neutral-400">Siz</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-10 text-center text-neutral-500">Personel kaydı yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $staff->links() }}</div>
            </div>
        </div>
    @endif

    @if($activeTab === 'roles')
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach($rolePermissions as $roleName => $selected)
                <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">{{ $roleLabels[$roleName] ?? $roleName }} <span class="font-mono text-neutral-400 text-[11px]">{{ $roleName }}</span></h2>
                        @if($roleName === 'super_admin')
                            <span class="text-[11px] text-neutral-400">Tüm izinler; düzenlenemez</span>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($permissions as $permission)
                            <label class="flex items-center gap-2 p-2 rounded-lg border border-neutral-200/40 dark:border-neutral-700/40">
                                <input type="checkbox" wire:model="rolePermissions.{{ $roleName }}" value="{{ $permission }}" @disabled($roleName === 'super_admin' || ! $isSuperAdmin)>
                                <span class="font-mono">{{ $permission }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if($roleName !== 'super_admin' && $isSuperAdmin)
                        <button type="button" wire:click="savePermissions('{{ $roleName }}')" wire:loading.attr="disabled" class="btn-apple-brand py-2 px-4 text-xs">İzinleri kaydet</button>
                    @endif
                </div>
            @endforeach
        </div>
        @if(! $isSuperAdmin)
            <p class="text-[11px] text-neutral-400">Rol izinlerini yalnız süper yönetici değiştirebilir.</p>
        @endif
    @endif
</div>
