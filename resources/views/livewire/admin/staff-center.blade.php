<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\ActivityLog;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

new class extends Component {
    // Sekme Yönetimi
    public string $activeTab = 'staff'; // 'staff', 'roles', 'audit_logs'

    // Yeni Personel Form Verileri
    public string $newFirstName = '';
    public string $newLastName = '';
    public string $newEmail = '';
    public string $newPhone = '';
    public string $newPassword = '';
    public string $selectedRole = 'kyc_validator';

    // Yeni Rol Form Verileri
    public string $newRoleName = '';
    public array $selectedPermissions = [];

    public function mount()
    {
        if (!auth()->user()->can('manage staff')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * Yeni Alt Personel Hesabı Tanımlama
     */
    public function addStaff()
    {
        $this->validate([
            'newFirstName' => 'required|string|min:2',
            'newLastName' => 'required|string|min:2',
            'newEmail' => 'required|email|unique:users,email',
            'newPhone' => 'required|string|unique:users,phone',
            'newPassword' => 'required|string|min:6',
        ], [
            'newEmail.unique' => 'Bu e-posta adresiyle kayıtlı bir personel zaten var.',
            'newPhone.unique' => 'Bu telefon numarası zaten kayıtlı.'
        ]);

        $user = User::create([
            'first_name' => $this->newFirstName,
            'last_name' => $this->newLastName,
            'email' => $this->newEmail,
            'phone' => $this->newPhone,
            'password' => Hash::make($this->newPassword),
            'current_role' => 'admin',
            'is_active' => true
        ]);

        // Rol ataması yap
        $user->assignRole($this->selectedRole);

        // Sicil Kütüğüne Kaydet
        ActivityLog::record('Personel Hesabı Oluşturuldu', "{$user->full_name} isimli personele {$this->selectedRole} rolü atandı.");

        $this->reset(['newFirstName', 'newLastName', 'newEmail', 'newPhone', 'newPassword']);
        session()->flash('success', 'Yeni alt personel hesabı başarıyla oluşturuldu ve yetkilendirildi!');
    }

    /**
     * Modüler Yeni Rol ve İzin Matrisi Oluşturma
     */
    public function addRole()
    {
        $this->validate([
            'newRoleName' => 'required|string|min:3|unique:roles,name',
            'selectedPermissions' => 'required|array|min:1'
        ], [
            'selectedPermissions.required' => 'Lütfen bu role atamak için en az bir izin kutucuğu seçiniz.'
        ]);

        $role = Role::create(['name' => $this->newRoleName]);
        $role->givePermissionTo($this->selectedPermissions);

        // Sicil Kütüğüne Kaydet
        ActivityLog::record('Yeni Rol Oluşturuldu', "{$this->newRoleName} adında modüler izinli yeni rol tanımlandı.");

        $this->reset(['newRoleName', 'selectedPermissions']);
        session()->flash('success', 'Yeni modüler rol ve izin matrisi başarıyla aktif edildi!');
    }

    /**
     * Tüm izinlerin listesi
     */
    private function getPermissions()
    {
        return Permission::all();
    }

    /**
     * Tanımlı roller
     */
    private function getRoles()
    {
        return Role::all();
    }

    /**
     * Admin personellerin listesi
     */
    private function getStaffUsers()
    {
        return User::where('current_role', 'admin')->latest()->get();
    }

    /**
     * Sicil kütüğü kayıtları
     */
    private function getAuditLogs()
    {
        return ActivityLog::with('user')->latest()->take(20)->get();
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Akıllı Tohumlayıcı -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Personel ve Rol Yetkilendirme</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Alt personelleri yetkilendirin, modüler izin matrisleri atayın ve sicil kütüğünü izleyin.</p>
        </div>

        @if(\App\Models\ActivityLog::count() === 0)
@endif
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm">
        <button wire:click="$set('activeTab', 'staff')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'staff' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Personel Kadrosu
        </button>
        <button wire:click="$set('activeTab', 'roles')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'roles' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Modüler Rol & İzin Matrisi
        </button>
        <button wire:click="$set('activeTab', 'audit_logs')" class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'audit_logs' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Personel Sicil Kütüğü (Audit Logs)
        </button>
    </div>

    @if($activeTab === 'staff')
        <!-- SEKME 1: PERSONEL KADROSU VE HESAP OLUŞTURUCU -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <!-- Personel Ekleme Form Kartı -->
            <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YENİ PERSONEL TANIMLA</h3>

                <form wire:submit.prevent="addStaff" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Ad</label>
                            <input type="text" wire:model="newFirstName" placeholder="Örn: Canan" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Soyad</label>
                            <input type="text" wire:model="newLastName" placeholder="Örn: Öztürk" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">E-Posta Adresi</label>
                        <input type="email" wire:model="newEmail" placeholder="canan@navluniq.com" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        @error('newEmail') <span class="text-red-500 text-[10px] block mt-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Telefon</label>
                            <input type="text" wire:model="newPhone" placeholder="+90555..." class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Giriş Şifresi</label>
                            <input type="password" wire:model="newPassword" placeholder="••••••••" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Atanacak Rol</label>
                        <select wire:model="selectedRole" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-bold">
                            @foreach($this->getRoles() as $r)
                                <option value="{{ $r->name }}">{{ strtoupper($r->name) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="w-full btn-apple-brand py-3.5 text-xs font-semibold">
                        Personeli Yetkilendir ve Kaydet
                    </button>
                </form>
            </div>

            <!-- Kayıtlı Personel Listesi -->
            <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YETKİLİ PERSONEL KADROSU</h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                            <th class="pb-3">Personel Adı</th>
                            <th class="pb-3">İletişim</th>
                            <th class="pb-3">Atanan Rol</th>
                            <th class="pb-3 text-right">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse($this->getStaffUsers() as $stf)
                            <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                                <td class="py-3.5 font-bold text-neutral-900 dark:text-white">
                                    {{ $stf->full_name }}
                                </td>
                                <td class="py-3.5 text-neutral-400">
                                    <div>{{ $stf->email }}</div>
                                    <div class="text-[10px] font-mono mt-0.5">{{ $stf->phone }}</div>
                                </td>
                                <td class="py-3.5">
                                    @foreach($stf->getRoleNames() as $rn)
                                        <span class="px-2.5 py-1 rounded-full font-bold text-[10px] bg-brand-500/10 text-brand-600 uppercase">
                                            {{ $rn }}
                                        </span>
                                    @endforeach
                                </td>
                                <td class="py-3.5 text-right">
                                    <span class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-emerald-500/10 text-emerald-600">AKTİF</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-neutral-400">Herhangi bir kayıtlı personel bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @elseif($activeTab === 'roles')
        <!-- SEKME 2: MODÜLER ROL VE İZİN MATRİSİ (CHECKBOX MATRIX) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start text-xs">
            <!-- Rol Tanımlama Form Kartı -->
            <div class="apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YENİ MODÜLER ROL TANIMLA</h3>

                <form wire:submit.prevent="addRole" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Rol Adı (Örn: 'evrak_onaycisi', 'operasyon_amiri')</label>
                        <input type="text" wire:model="newRoleName" placeholder="evrak_onaycisi" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        @error('newRoleName') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <!-- Modüler İzin Kutucukları (Checkboxes) -->
                    <div class="space-y-2 pt-2 border-t border-neutral-100 dark:border-neutral-800/50">
                        <label class="font-bold text-brand-500 block uppercase tracking-wider text-[10px]">Atanacak Modüler İzinler</label>

                        <div class="space-y-2 max-h-60 overflow-y-auto p-2 bg-neutral-50 dark:bg-neutral-900 rounded-xl border border-neutral-200/40">
                            @foreach($this->getPermissions() as $p)
                                <label class="flex items-center space-x-2 p-1.5 hover:bg-neutral-200/40 dark:hover:bg-neutral-800 rounded-lg cursor-pointer transition-colors">
                                    <input type="checkbox" wire:model="selectedPermissions" value="{{ $p->name }}" class="w-4 h-4 accent-brand-500 rounded">
                                    <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ $p->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('selectedPermissions') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" class="w-full btn-apple-brand py-3 text-xs">
                        Modüler Rolü Aktifleştir
                    </button>
                </form>
            </div>

            <!-- Mevcut Roller ve Bağlı Yetki Listesi -->
            <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">SİSTEMDEKİ MODÜLER ROLLER VE YETKİLERİ</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($this->getRoles() as $rl)
                        <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="font-extrabold uppercase text-neutral-900 dark:text-white">{{ $rl->name }}</span>
                                <span class="text-[10px] text-neutral-400 font-mono">{{ $rl->permissions->count() }} İzin Atandı</span>
                            </div>
                            <div class="flex flex-wrap gap-1 pt-2 border-t border-neutral-200/30 dark:border-neutral-800/50">
                                @forelse($rl->permissions as $p)
                                    <span class="px-2 py-0.5 rounded bg-neutral-200/60 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300 text-[9px] font-semibold">
                                        {{ $p->name }}
                                    </span>
                                @empty
                                    <span class="text-[10px] text-neutral-400 italic">Süper Admin (Sınırsız Tüm İzinler)</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    @elseif($activeTab === 'audit_logs')
        <!-- SEKME 3: PERSONEL SİCİL KÜTÜĞÜ (AUDIT LOGS) -->
        <div class="apple-glass rounded-3xl p-6 space-y-4">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">PERSONEL İŞLEM VE SİCİL LOG KAYITLARI</h3>

            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                        <th class="p-4">Tarih / Saat</th>
                        <th class="p-4">Personel</th>
                        <th class="p-4">İşlem Başlığı</th>
                        <th class="p-4">Sicil Detayı (Audit Log)</th>
                        <th class="p-4 text-right">IP Adresi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse($this->getAuditLogs() as $log)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200 font-mono text-[11px]">
                            <td class="p-4 text-neutral-400">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="p-4 font-bold font-sans text-neutral-900 dark:text-white">{{ $log->user->full_name ?? 'Sistem' }}</td>
                            <td class="p-4 font-sans font-bold text-brand-500">{{ $log->action }}</td>
                            <td class="p-4 font-sans text-neutral-600 dark:text-neutral-300 leading-relaxed">{{ $log->description }}</td>
                            <td class="p-4 text-right text-neutral-400">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-12 text-center text-neutral-400 font-sans">Henüz kaydedilmiş bir personel sicil kaydı bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
