<?php

use App\Models\Backup;
use App\Services\BackupService;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Yedekleme: veritabanı + dosyalar tek zip. Şimdi yedek al, indir, sil; her gece 03:30'da otomatik yedek.
 */
new class extends Component {
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage settings'), 403);
    }

    public function createBackup(string $type = 'full'): void
    {
        if (! auth()->user()?->can('manage settings')) {
            return;
        }
        set_time_limit(0);
        $backup = app(BackupService::class)->create($type === 'database' ? 'database' : 'full', auth()->id());
        if ($backup->status === 'completed') {
            app(BackupService::class)->prune();
            session()->flash('success_message', "Yedek hazır: {$backup->filename} (".number_format((float) $backup->size_mb, 2, ',', '.').' MB). İndir düğmesiyle bilgisayarınıza alın.');
        } else {
            session()->flash('error_message', 'Yedek alınamadı: '.$backup->failure_message);
        }
    }

    public function deleteBackup(int $id): void
    {
        if (! auth()->user()?->can('manage settings')) {
            return;
        }
        if ($backup = Backup::query()->find($id)) {
            app(BackupService::class)->delete($backup, auth()->id());
            session()->flash('success_message', 'Yedek silindi.');
        }
    }

    public function with(): array
    {
        $dir = BackupService::directory();
        $free = @disk_free_space(is_dir($dir) ? $dir : storage_path());

        return [
            'backups' => Backup::query()->latest('id')->paginate(20),
            'total' => (float) Backup::query()->where('status', 'completed')->sum('size_mb'),
            'freeGb' => $free ? round($free / 1073741824, 1) : null,
            'directory' => $dir,
            'lastOk' => Backup::query()->where('status', 'completed')->latest('id')->first(),
        ];
    }
}; ?>

<div class="max-w-6xl mx-auto space-y-6">
    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Yedekleme</h1>
            <p class="page-subtitle">Veritabanı (tüm ayarlar, kaynaklar, ilanlar, kullanıcılar), .env ve yüklenen dosyalar tek zip'te. Her gece 03:30'da otomatik yedek alınır; son {{ \App\Services\BackupService::DEFAULT_KEEP }} yedek sunucuda tutulur.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="createBackup('full')" wire:loading.attr="disabled" class="btn-apple-brand px-4 py-2.5 text-xs font-semibold disabled:opacity-50">
                <span wire:loading.remove wire:target="createBackup">Şimdi tam yedek al</span>
                <span wire:loading wire:target="createBackup">Yedek alınıyor… bekleyin</span>
            </button>
            <button type="button" wire:click="createBackup('database')" wire:loading.attr="disabled" class="btn-secondary px-4 py-2.5 text-xs font-semibold disabled:opacity-50">Yalnız veritabanı</button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Son başarılı yedek</span><span class="text-sm font-black text-neutral-900 dark:text-white">{{ $lastOk?->completed_at?->diffForHumans() ?? 'Henüz yok' }}</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Sunucudaki yedekler</span><span class="text-sm font-black text-neutral-900 dark:text-white">{{ number_format($total, 1, ',', '.') }} MB</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Boş disk</span><span class="text-sm font-black text-neutral-900 dark:text-white">{{ $freeGb !== null ? $freeGb.' GB' : '—' }}</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Sunucu klasörü</span><span class="text-[11px] font-mono text-neutral-700 dark:text-neutral-200 break-all">{{ $directory }}</span></div>
    </div>

    <div class="apple-glass rounded-3xl overflow-hidden">
        <div class="responsive-scroll">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4">Dosya</th><th class="p-4">Tür</th><th class="p-4">Boyut</th><th class="p-4">Durum</th><th class="p-4">Tarih</th><th class="p-4"></th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                    @forelse($backups as $backup)
                        <tr class="align-top">
                            <td class="p-4"><div class="font-mono font-bold">{{ $backup->filename }}</div>@if($backup->sha256)<div class="text-[10px] text-neutral-400 font-mono">sha256 {{ substr($backup->sha256, 0, 16) }}…</div>@endif</td>
                            <td class="p-4">{{ $backup->backup_type === 'database' ? 'Veritabanı' : 'Tam (veritabanı + dosyalar)' }}</td>
                            <td class="p-4 whitespace-nowrap tabular-nums">{{ number_format((float) $backup->size_mb, 2, ',', '.') }} MB</td>
                            <td class="p-4">
                                <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $backup->status === 'completed' ? 'bg-emerald-500/10 text-emerald-600' : ($backup->status === 'failed' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ ['completed' => 'Hazır', 'failed' => 'Başarısız', 'running' => 'Alınıyor'][$backup->status] ?? $backup->status }}</span>
                                @if($backup->failure_message)<div class="text-[11px] text-red-500 mt-1 max-w-xs">{{ $backup->failure_message }}</div>@endif
                            </td>
                            <td class="p-4 whitespace-nowrap text-neutral-500">{{ $backup->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="p-4 whitespace-nowrap space-x-3">
                                @if($backup->status === 'completed')<a href="{{ route('admin.backups.download', $backup) }}" class="text-brand-600 font-semibold hover:underline">İndir</a>@endif
                                <button type="button" wire:click="deleteBackup({{ $backup->id }})" wire:confirm="Bu yedek sunucudan silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-neutral-500">Henüz yedek yok. "Şimdi tam yedek al" ile ilk yedeği oluşturun.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($backups->hasPages())<div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $backups->links() }}</div>@endif
    </div>

    <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yedeği bilgisayarınıza alma</h2>
        <p class="text-neutral-500">En kolayı listedeki <strong>İndir</strong> düğmesi. Sunucudan doğrudan almak için bilgisayarınızda PowerShell'de (İndirilenler klasörüne kopyalar):</p>
        <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">scp "root@185.22.187.140:{{ $directory }}/*.zip" "$HOME\Downloads\"</code>
        <p class="text-neutral-500">Sunucuda elle yedek: <span class="font-mono">cd /var/www/navluniq &amp;&amp; sudo -u www-data php artisan system:backup</span>. Zip'in içindeki <span class="font-mono">BENIOKU.txt</span> geri yükleme adımlarını anlatır. Yedek dosyaları .env ve belgeleri içerir; paylaşmayın.</p>
    </div>
</div>
