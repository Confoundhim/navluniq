<?php

use Livewire\Volt\Component;
use App\Models\Backup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

new class extends Component {
    public array $metrics = [];
    public array $recentLogs = [];
    public array $backups = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage settings'), 403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        $this->refreshHealth();
    }

    public function refreshHealth(): void
    {
        $database = $this->probe(fn () => DB::selectOne('select 1'));
        $cache = $this->probe(function (): void {
            $key = 'health:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            if (Cache::get($key) !== 'ok') {
                throw new RuntimeException('Önbellek okuma/yazma doğrulaması başarısız.');
            }
            Cache::forget($key);
        });

        $storagePath = storage_path();
        $freeBytes = @disk_free_space($storagePath);
        $this->metrics = [
            ['name' => 'Uygulama', 'ok' => true, 'detail' => app()->environment().' · Laravel '.app()->version()],
            ['name' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => PHP_VERSION],
            ['name' => 'Veritabanı', 'ok' => $database['ok'], 'detail' => $database['detail']],
            ['name' => 'Önbellek', 'ok' => $cache['ok'], 'detail' => $cache['detail']],
            ['name' => 'Kuyruk', 'ok' => true, 'detail' => (string) config('queue.default')],
            ['name' => 'Oturum', 'ok' => true, 'detail' => (string) config('session.driver')],
            ['name' => 'Disk', 'ok' => $freeBytes === false || $freeBytes > 536870912, 'detail' => $freeBytes === false ? 'Ölçülemedi' : number_format($freeBytes / 1073741824, 2, ',', '.').' GB boş'],
        ];

        $this->backups = Backup::query()->latest()->limit(10)->get()
            ->map(fn (Backup $backup) => [
                'filename' => $backup->filename,
                'status' => $backup->status,
                'size_mb' => (string) $backup->size_mb,
                'created_at' => optional($backup->created_at)->format('d.m.Y H:i'),
            ])->all();
        $this->recentLogs = $this->tailLog(storage_path('logs/laravel.log'), 30);
    }

    private function probe(callable $callback): array
    {
        try {
            $callback();
            return ['ok' => true, 'detail' => 'Çalışıyor'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => class_basename($exception)];
        }
    }

    private function tailLog(string $path, int $limit): array
    {
        if (!File::exists($path) || !File::isReadable($path)) {
            return [];
        }

        $file = new SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last = $file->key();
        $lines = [];
        for ($line = max(0, $last - $limit); $line <= $last; $line++) {
            $file->seek($line);
            $value = trim((string) $file->current());
            if ($value !== '') {
                $value = preg_replace('/(password|token|secret|key|authorization|cookie)([=:\s]+)[^\s,;]+/i', '$1$2[REDACTED]', $value) ?? $value;
                $lines[] = mb_substr($value, 0, 800);
            }
        }
        return $lines;
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sunucu ve Sistem Sağlığı</h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Gerçek çalışma zamanı kontrolleri; sahte metrik üretilmez.</p>
        </div>
        <button wire:click="refreshHealth" wire:loading.attr="disabled" class="btn-apple-brand px-4 py-2.5 text-xs font-semibold disabled:opacity-50">
            <span wire:loading.remove wire:target="refreshHealth">Kontrolleri Yenile</span>
            <span wire:loading wire:target="refreshHealth">Kontrol ediliyor…</span>
        </button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($metrics as $metric)
            <div class="apple-glass rounded-2xl border p-5 {{ $metric['ok'] ? 'border-emerald-500/20' : 'border-red-500/30' }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-neutral-500">{{ $metric['name'] }}</span>
                    <span class="h-2.5 w-2.5 rounded-full {{ $metric['ok'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                </div>
                <p class="mt-3 text-sm font-semibold text-neutral-900 dark:text-white">{{ $metric['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son yedek kayıtları</h2>
            <p class="mt-1 text-xs text-neutral-500">Yedek üretimi web isteğinde yapılmaz; zamanlanmış sunucu görevi kullanılmalıdır.</p>
            <div class="mt-5 space-y-3">
                @forelse($backups as $backup)
                    <div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200/70 p-3 text-xs dark:border-neutral-800">
                        <div class="min-w-0"><p class="truncate font-semibold">{{ $backup['filename'] }}</p><p class="text-neutral-500">{{ $backup['created_at'] }} · {{ $backup['size_mb'] }} MB</p></div>
                        <span class="rounded-full px-2 py-1 font-semibold {{ $backup['status'] === 'completed' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-amber-500/10 text-amber-500' }}">{{ $backup['status'] }}</span>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-neutral-300 p-4 text-xs text-neutral-500 dark:border-neutral-700">Henüz doğrulanmış yedek kaydı yok.</p>
                @endforelse
            </div>
        </section>

        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son uygulama günlükleri</h2>
            <p class="mt-1 text-xs text-neutral-500">Hassas anahtar kalıpları ekranda maskelenir.</p>
            <div class="mt-5 max-h-96 space-y-2 overflow-auto rounded-xl bg-neutral-950 p-4 font-mono text-[11px] text-neutral-300">
                @forelse($recentLogs as $line)
                    <div class="break-all">{{ $line }}</div>
                @empty
                    <div class="text-neutral-500">Günlük kaydı bulunamadı.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
