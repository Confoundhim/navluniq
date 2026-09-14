<?php

use App\Models\ActivityLog;
use App\Models\CmsContent;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\SettingRevision;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $activeTab = 'trash';

    public string $trashType = 'users';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage settings'), 403);
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedTrashType(): void
    {
        $this->resetPage();
    }

    private function modelFor(string $type): ?string
    {
        return ['users' => User::class, 'loads' => Load::class, 'vehicles' => DriverVehicle::class][$type] ?? null;
    }

    public function restore(string $type, int $id): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $model = $this->modelFor($type);
        $record = $model ? $model::onlyTrashed()->find($id) : null;
        if (! $record) {
            session()->flash('error_message', 'Kayıt bulunamadı.');

            return;
        }

        $record->restore();
        ActivityLog::record('trash.restored', class_basename($model)." #{$id} geri yüklendi", auth()->id(), $record);
        session()->flash('success_message', 'Kayıt geri yüklendi.');
    }

    public function forceDelete(string $type, int $id): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $model = $this->modelFor($type);
        $record = $model ? $model::onlyTrashed()->find($id) : null;
        if (! $record) {
            session()->flash('error_message', 'Kayıt bulunamadı.');

            return;
        }

        try {
            $record->forceDelete();
            ActivityLog::record('trash.purged', class_basename($model)." #{$id} kalıcı olarak silindi", auth()->id());
            session()->flash('success_message', 'Kayıt kalıcı olarak silindi.');
        } catch (QueryException $e) {
            session()->flash('error_message', 'Kayıt başka tablolarca referans edildiği için kalıcı silinemedi; bağlı kayıtlar durdukça yalnız çöp kutusunda kalabilir.');
        }
    }

    public function rollback(int $revisionId): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $revision = SettingRevision::query()->find($revisionId);
        if (! $revision) {
            session()->flash('error_message', 'Revizyon bulunamadı.');

            return;
        }

        $current = CmsContent::getVal($revision->key);
        $current = $current === null ? null : (string) $current;
        $target = $revision->old_value === null ? null : (string) $revision->old_value;

        if ($current === $target) {
            session()->flash('error_message', 'Ayar zaten bu değerde.');

            return;
        }

        Settings::set($revision->key, $target, auth()->id());
        SettingRevision::create([
            'user_id' => auth()->id(),
            'key' => $revision->key,
            'setting_label' => $revision->setting_label.' (geri alma)',
            'old_value' => $current,
            'new_value' => $target,
        ]);
        ActivityLog::record('setting.rolled_back', "Ayar geri alındı: {$revision->setting_label} ({$revision->key})", auth()->id(), $revision, ['from' => $current, 'to' => $target]);
        session()->flash('success_message', "'{$revision->setting_label}' ayarı revizyondaki eski değere döndürüldü; işlem yeni bir revizyon olarak kaydedildi.");
    }

    public function with(): array
    {
        $trash = null;
        if ($this->activeTab === 'trash') {
            $model = $this->modelFor($this->trashType) ?? User::class;
            $query = $model::onlyTrashed()->latest('deleted_at');
            if ($model === Load::class) {
                $query->with('cargoOwnerProfile.user');
            } elseif ($model === DriverVehicle::class) {
                $query->with('driverProfile.user');
            }
            $trash = $query->paginate(15);
        }

        return [
            'trash' => $trash,
            'revisions' => $this->activeTab === 'revisions' ? SettingRevision::query()->with('user')->latest('id')->paginate(15) : null,
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
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Geri Yükleme</h1>
        <p class="page-subtitle">Yumuşak silinmiş kayıtlar geri yüklenebilir; kalıcı silme geri alınamaz ve bağlı kayıtlar varsa veritabanı tarafından reddedilir.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        <button type="button" wire:click="$set('activeTab', 'trash')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'trash' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Çöp kutusu</button>
        <button type="button" wire:click="$set('activeTab', 'revisions')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'revisions' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Ayar revizyonları</button>
    </div>

    @if($activeTab === 'trash')
        <div class="apple-glass p-3 rounded-2xl">
            <select wire:model.live="trashType" class="{{ $input }} sm:w-56">
                <option value="users">Kullanıcılar</option>
                <option value="loads">İlanlar</option>
                <option value="vehicles">Araçlar</option>
            </select>
        </div>
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Kayıt</th>
                            <th class="p-4">Ayrıntı</th>
                            <th class="p-4">Silinme</th>
                            <th class="p-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($trash as $record)
                            <tr>
                                <td class="p-4 font-bold">#{{ $record->id }}</td>
                                <td class="p-4">
                                    @if($trashType === 'users')
                                        {{ $record->full_name }} <span class="text-neutral-400">· {{ $record->email }}</span>
                                    @elseif($trashType === 'loads')
                                        {{ $record->pickup_location }} → {{ $record->delivery_location }} <span class="text-neutral-400">· {{ number_format((float) $record->price, 2, ',', '.') }} ₺ · {{ $record->cargoOwnerProfile?->displayName() }}</span>
                                    @else
                                        {{ $record->plate }} <span class="text-neutral-400">· {{ $record->brand }} {{ $record->model }} · {{ $record->driverProfile?->user?->full_name }}</span>
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap text-neutral-500">{{ $record->deleted_at?->format('d.m.Y H:i') }}</td>
                                <td class="p-4 whitespace-nowrap space-x-2">
                                    <button type="button" wire:click="restore('{{ $trashType }}', {{ $record->id }})" class="text-brand-500 font-semibold">Geri yükle</button>
                                    <button type="button" wire:click="forceDelete('{{ $trashType }}', {{ $record->id }})" wire:confirm="Kayıt kalıcı olarak silinecek ve geri alınamayacak. Devam edilsin mi?" class="text-red-500 font-semibold">Kalıcı sil</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-10 text-center text-neutral-500">Çöp kutusunda kayıt yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $trash->links() }}</div>
        </div>
    @endif

    @if($activeTab === 'revisions')
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Zaman</th>
                            <th class="p-4">Ayar</th>
                            <th class="p-4">Eski değer</th>
                            <th class="p-4">Yeni değer</th>
                            <th class="p-4">Personel</th>
                            <th class="p-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($revisions as $rev)
                            <tr>
                                <td class="p-4 whitespace-nowrap text-neutral-500">{{ $rev->created_at?->format('d.m.Y H:i') }}</td>
                                <td class="p-4">{{ $rev->setting_label }}<div class="font-mono text-[11px] text-neutral-400">{{ $rev->key }}</div></td>
                                <td class="p-4 max-w-xs truncate">{{ $rev->old_value ?? '—' }}</td>
                                <td class="p-4 max-w-xs truncate">{{ $rev->new_value ?? '—' }}</td>
                                <td class="p-4">{{ $rev->user?->full_name ?? '—' }}</td>
                                <td class="p-4 whitespace-nowrap"><button type="button" wire:click="rollback({{ $rev->id }})" wire:confirm="Ayar bu revizyondaki eski değere döndürülecek. Devam edilsin mi?" class="text-brand-500 font-semibold">Eski değere dön</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Henüz ayar revizyonu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $revisions->links() }}</div>
        </div>
    @endif
</div>
