<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Component;

new class extends Component {
    public array $checks = [];

    public array $logLines = [];

    public ?string $logNote = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage settings'), 403);
        $this->runChecks();
    }

    public function startUpdate(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            return;
        }
        $started = app(\App\Services\DeployService::class)->start(auth()->id());
        session()->flash($started ? 'success_message' : 'error_message', $started
            ? 'Güncelleme başlatıldı; çıktı aşağıda birkaç saniyede bir yenilenir. Bitince "TAMAM" satırı görünür.'
            : 'Güncelleme zaten çalışıyor; bitmesini bekleyin.');
    }

    public function runChecks(): void
    {
        $this->checks = [];

        $this->checks[] = $this->probe('Veritabanı', function (): string {
            DB::selectOne('select 1 as ok');

            return (string) config('database.default');
        });

        $this->checks[] = $this->probe('Önbellek', function (): string {
            $key = 'health:'.bin2hex(random_bytes(6));
            Cache::put($key, 'ok', 10);
            if (Cache::get($key) !== 'ok') {
                throw new RuntimeException('Yazılan değer okunamadı.');
            }
            Cache::forget($key);

            return (string) config('cache.default');
        });

        $this->checks[] = $this->probe('Kuyruk (bekleyen iş)', function (): string {
            if (! Schema::hasTable('jobs')) {
                throw new RuntimeException('jobs tablosu yok.');
            }

            return DB::table('jobs')->count().' iş · sürücü: '.config('queue.default');
        });

        $this->checks[] = $this->probe('Kuyruk işçisi', function (): string {
            $age = \App\Jobs\QueueHeartbeat::ageSeconds();
            if ($age === null) {
                throw new RuntimeException('Hiç nabız yok: işçi çalışmıyor ya da yanlış bağlantıyı dinliyor ('.config('queue.default').' bekleniyor). Telefon mesajları istek içinde işleniyor; site yavaşlar.');
            }
            if ($age >= \App\Jobs\QueueHeartbeat::MAX_AGE_SECONDS) {
                throw new RuntimeException('Son nabız '.floor($age / 60).' dk önce; işçi durmuş görünüyor. Telefon mesajları istek içinde işleniyor.');
            }

            return 'çalışıyor · son nabız '.$age.' sn önce · telefon mesajları kuyrukta işleniyor';
        });

        $this->checks[] = $this->probe('Başarısız işler', function (): string {
            if (! Schema::hasTable('failed_jobs')) {
                throw new RuntimeException('failed_jobs tablosu yok.');
            }
            $count = DB::table('failed_jobs')->count();
            if ($count > 0) {
                throw new RuntimeException($count.' başarısız iş bekliyor.');
            }

            return '0 başarısız iş';
        });

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) !== 'local') {
                continue;
            }
            $root = (string) ($disk['root'] ?? '');
            $this->checks[] = $this->probe("Disk: {$name}", function () use ($root): string {
                if ($root === '' || ! is_dir($root)) {
                    throw new RuntimeException('Dizin yok: '.$root);
                }
                if (! is_writable($root)) {
                    throw new RuntimeException('Yazılamıyor: '.$root);
                }
                $free = @disk_free_space($root);

                return $free === false ? 'Yazılabilir' : 'Yazılabilir · '.number_format($free / 1073741824, 2, ',', '.').' GB boş';
            });
        }

        $this->checks[] = $this->probe('PHP yükleme sınırı', function (): string {
            $toBytes = static function (string $value): int {
                $unit = strtolower(substr(trim($value), -1));
                $number = (int) $value;

                return match ($unit) {
                    'g' => $number * 1024 ** 3,
                    'm' => $number * 1024 ** 2,
                    'k' => $number * 1024,
                    default => (int) $value,
                };
            };
            $upload = (string) ini_get('upload_max_filesize');
            $post = (string) ini_get('post_max_size');
            $required = 10 * 1024 ** 2; // KYC belgeleri için 10 MB kabul edilir

            if ($toBytes($upload) < $required || $toBytes($post) < $required) {
                throw new \RuntimeException("upload_max_filesize={$upload}, post_max_size={$post}; belge yüklemeleri için en az 10M gerekir. Sunucuda deploy/update.sh çalıştırın.");
            }

            return "upload_max_filesize {$upload} · post_max_size {$post}";
        });

        $this->checks[] = ['name' => 'Uygulama', 'ok' => true, 'detail' => app()->environment().' · Laravel '.app()->version().' · PHP '.PHP_VERSION.' · hata ayıklama '.(config('app.debug') ? 'AÇIK' : 'kapalı')];

        $this->logLines = [];
        $this->logNote = null;
        if (auth()->user()->hasRole('super_admin')) {
            $this->logLines = $this->tailLog(storage_path('logs/laravel.log'), 50, 65536);
            if ($this->logLines === []) {
                $this->logNote = 'Günlük dosyası yok veya boş.';
            }
        } else {
            $this->logNote = 'Uygulama günlükleri yalnız süper yöneticiye gösterilir.';
        }
    }

    private function probe(string $name, callable $callback): array
    {
        try {
            return ['name' => $name, 'ok' => true, 'detail' => $callback()];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** Dosyanın sonundan en çok $maxBytes okuyarak son $limit satırı döner; e-posta ve anahtar kalıplarını maskeler. */
    private function tailLog(string $path, int $limit, int $maxBytes): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return [];
        }

        try {
            $size = filesize($path) ?: 0;
            $start = max(0, $size - $maxBytes);
            fseek($handle, $start);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($chunk === '') {
            return [];
        }

        $lines = preg_split('/\R/', $chunk) ?: [];
        if ($start > 0) {
            array_shift($lines);
        }
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        $lines = array_slice($lines, -$limit);

        return array_map(function (string $line): string {
            $line = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-posta]', $line) ?? $line;
            $line = preg_replace('/(password|passwd|token|secret|key|salt|authorization|cookie|api[_-]?key)(["\']?\s*[=:]\s*["\']?)[^\s,;"\'}]+/i', '$1$2[gizli]', $line) ?? $line;

            return mb_substr($line, 0, 600);
        }, $lines);
    }
}; ?>

@php $update = \App\Services\DeployService::status(); @endphp
<div wire:poll.{{ $update['running'] ? '5s' : '15s' }} class="max-w-7xl mx-auto space-y-8">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sistem Sağlığı</h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Kontroller istek anında çalıştırılır; geçmiş ölçüm saklanmaz.</p>
        </div>
        <button type="button" wire:click="runChecks" wire:loading.attr="disabled" class="btn-apple-brand px-4 py-2.5 text-xs font-semibold disabled:opacity-50">
            <span wire:loading.remove wire:target="runChecks">Kontrolleri yenile</span>
            <span wire:loading wire:target="runChecks">Kontrol ediliyor</span>
        </button>
    </div>

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <section class="apple-glass rounded-3xl p-6 space-y-3" data-update-section data-update-running="{{ $update['running'] ? 1 : 0 }}">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Siteyi güncelle</h2>
                <p class="mt-1 text-xs text-neutral-500">GitHub'da birleştirilen son sürümü sunucuya alır (sunucudaki <span class="font-mono">update.sh</span> ile aynı iş). Telefondan da çalışır: PR'ı GitHub uygulamasında birleştirin, burada bu düğmeye basın.</p>
            </div>
            <button type="button" wire:click="startUpdate" wire:loading.attr="disabled" wire:confirm="Sunucu son sürüme güncellenecek; birkaç dakika sürer. Devam edilsin mi?" @disabled($update['running']) class="btn-apple-brand px-4 py-2.5 text-xs font-semibold disabled:opacity-50 shrink-0">
                {{ $update['running'] ? 'Güncelleme çalışıyor…' : 'Siteyi güncelle' }}
            </button>
        </div>
        <div class="flex flex-wrap gap-3 text-[11px] text-neutral-500" data-update-badge>
            @if($update['running'])<span class="badge bg-amber-500/10 text-amber-600">Çalışıyor · başladı {{ $update['started_at'] }}</span>
            @elseif($update['ok'] === true)<span class="badge bg-emerald-500/10 text-emerald-600">Son güncelleme tamamlandı · {{ $update['finished_at'] }}</span>
            @elseif($update['ok'] === false)<span class="badge bg-red-500/10 text-red-600">Son güncelleme hata verdi · {{ $update['finished_at'] }}</span>
            @elseif($update['interrupted'])<span class="badge bg-amber-500/10 text-amber-600">Son güncelleme sonuç yazamadan kesildi · {{ $update['finished_at'] }}</span>
            @else<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">Panelden henüz güncelleme yapılmadı</span>@endif
        </div>
        @if($update['running'])
            <p class="text-[11px] text-amber-600">Güncelleme sırasında site kısa süre bakım modundadır; bu sayfa açık kalabilir, çıktı kendiliğinden yenilenir.</p>
        @endif
        @if($update['error'])
            <div class="rounded-2xl border border-red-500/30 bg-red-500/5 p-3">
                <div class="text-[11px] font-bold text-red-600 mb-1">Hata satırları (günlüğün sonu)</div>
                <pre class="text-[11px] leading-relaxed font-mono whitespace-pre-wrap break-words text-red-700 dark:text-red-300">{{ $update['error'] }}</pre>
            </div>
        @endif
        @if($update['log'] !== '')
            <pre wire:key="update-log-{{ md5($update['log']) }}" data-update-log x-data x-init="$el.scrollTop = $el.scrollHeight" class="text-[11px] leading-relaxed font-mono whitespace-pre-wrap break-words max-h-64 overflow-auto rounded-2xl bg-neutral-950 text-neutral-200 p-4">{{ $update['log'] }}</pre>
        @endif
        <p class="text-[11px] text-neutral-400">Düğme "izin yok" ya da "komut bulunamadı" derse sunucuda bir kez şu çalıştırılır: <span class="font-mono">bash /var/www/navluniq/deploy/install-update-button.sh</span> (belgede anlatılır).</p>
    </section>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($checks as $check)
            <div class="apple-glass rounded-2xl border p-5 {{ $check['ok'] ? 'border-emerald-500/20' : 'border-red-500/30' }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-neutral-500">{{ $check['name'] }}</span>
                    <span class="h-2.5 w-2.5 rounded-full {{ $check['ok'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                </div>
                <p class="mt-3 text-xs font-semibold text-neutral-900 dark:text-white break-words">{{ $check['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Zamanlanmış görevler</h2>
            <p class="mt-1 text-xs text-neutral-500">Sunucuda her dakika çalışan "schedule:run" tetikleyicisine bağlıdır. Son çalışma zamanı kaydedilmediğinden burada gösterilemez.</p>
            <div class="mt-4 space-y-2 text-xs">
                @foreach([
                    ['offers:expire', 'Saatte bir', 'Süresi dolan teklifleri kapatır'],
                    ['shipments:auto-approve', 'Saatte bir', 'Onay süresi geçen teslimatları otomatik onaylar'],
                    ['accounts:purge-drafts', 'Günde bir', 'Tamamlanmamış kayıt taslaklarını temizler'],
                ] as [$command, $frequency, $description])
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 rounded-xl border border-neutral-200/70 p-3 dark:border-neutral-800">
                        <div><span class="font-mono font-semibold">{{ $command }}</span><div class="text-neutral-500">{{ $description }}</div></div>
                        <div class="text-neutral-400 whitespace-nowrap">{{ $frequency }} · son çalışma: bilinmiyor</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son uygulama günlükleri (son 50 satır)</h2>
            <p class="mt-1 text-xs text-neutral-500">E-posta adresleri ve anahtar kalıpları ekranda maskelenir.</p>
            <div class="mt-5 max-h-96 space-y-2 overflow-auto rounded-xl bg-neutral-950 p-4 font-mono text-[11px] text-neutral-300">
                @forelse($logLines as $line)
                    <div class="break-all">{{ $line }}</div>
                @empty
                    <div class="text-neutral-500">{{ $logNote ?? 'Günlük kaydı bulunamadı.' }}</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="apple-glass rounded-3xl p-6">
        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yedekler</h2>
        <p class="mt-1 text-xs text-neutral-500">Her gece 03:30'da veritabanı ve dosyaların tam yedeği alınır; elle yedek almak ve indirmek için <a href="{{ route('admin.backups') }}" class="text-brand-500 font-semibold hover:underline" wire:navigate>Yedekler</a> sayfası.</p>
    </section>
</div>

@script
<script>
    // Güncelleme sırasında site bakım modundadır: Livewire yoklamaları 503 alır. Hata penceresi açılmaz;
    // çıktı bakım modundan muaf durum adresinden (JSON) 3 sn'de bir çekilir, bitince bileşen yenilenir.
    const root = $wire.$el;
    const statusUrl = @js(route('admin.health.update-status'));
    let timer = null;

    const render = (d) => {
        const log = root.querySelector('[data-update-log]');
        if (log && d.log) { log.textContent = d.log; log.scrollTop = log.scrollHeight; }
        const badge = root.querySelector('[data-update-badge]');
        if (badge) {
            const text = d.running ? 'Çalışıyor · başladı ' + d.started_at
                : d.ok === true ? 'Son güncelleme tamamlandı · ' + d.finished_at
                : d.ok === false ? 'Son güncelleme hata verdi · ' + d.finished_at
                : 'Son güncelleme sonuç yazamadan kesildi · ' + d.finished_at;
            badge.innerHTML = '<span class="badge ' + (d.running ? 'bg-amber-500/10 text-amber-600' : d.ok === true ? 'bg-emerald-500/10 text-emerald-600' : d.ok === false ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') + '"></span>';
            badge.firstChild.textContent = text;
        }
    };
    const stop = () => { if (timer) { clearInterval(timer); timer = null; } };
    const tick = async () => {
        try {
            const r = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
            if (! r.ok) return; // bakım/yeniden başlatma anı: bir sonraki denemede
            const d = await r.json();
            render(d);
            if (! d.running) { stop(); setTimeout(() => $wire.$refresh(), 1500); }
        } catch (e) { /* PHP-FPM yeniden başlarken bağlantı kopabilir; sonraki denemede */ }
    };
    const sync = () => {
        const running = root.querySelector('[data-update-section]')?.dataset.updateRunning === '1';
        if (running && ! timer) { tick(); timer = setInterval(tick, 3000); }
    };
    sync();
    Livewire.hook('commit', ({ succeed }) => succeed(() => setTimeout(sync, 0)));
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 503) {
                preventDefault();
            }
        });
    });
</script>
@endscript
