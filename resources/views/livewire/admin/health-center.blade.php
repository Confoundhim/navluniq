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
        // Güncelleme, kuyruk temizliği ve yeniden konumlama sunucuyu etkiler: yalnız "manage system" (denetim Y5).
        if (! auth()->user()?->can('manage system')) {
            session()->flash('error_message', 'Siteyi güncellemek için sistem yönetimi yetkisi gerekir.');

            return;
        }
        $started = app(\App\Services\DeployService::class)->start(auth()->id());
        session()->flash($started ? 'success_message' : 'error_message', $started
            ? 'Güncelleme başlatıldı; çıktı aşağıda birkaç saniyede bir yenilenir. Bitince "TAMAM" satırı görünür.'
            : 'Güncelleme zaten çalışıyor; bitmesini bekleyin.');
    }

    /** Başarısız işleri kuyruğa geri koyar (queue:retry all) ve kontrolleri yeniler. */
    public function retryFailedJobs(): void
    {
        abort_unless(auth()->user()?->can('manage system'), 403);
        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => ['all']]);
        session()->flash('success_message', 'Başarısız işler yeniden kuyruğa alındı; işçi çalışıyorsa birazdan işlenir.');
        $this->runChecks();
    }

    /** Başarısız işleri siler (queue:flush). Telefon mesajı işleri zaten Canlı akışa hata olarak yazılmıştır; silmek ilan kaybettirmez. */
    public function flushFailedJobs(): void
    {
        abort_unless(auth()->user()?->can('manage system'), 403);
        \Illuminate\Support\Facades\Artisan::call('queue:flush');
        session()->flash('success_message', 'Başarısız işler temizlendi.');
        $this->runChecks();
    }

    /** Yeniden konumlamanın bir parçasını hemen çalıştırır (zamanlayıcıyı beklemeden; en çok 15 sn). */
    public function runRelocateBatch(): void
    {
        abort_unless(auth()->user()?->can('manage system'), 403);
        \Illuminate\Support\Facades\Artisan::call('scraped-loads:relocate-force', ['--seconds' => 15]);
        session()->flash('success_message', trim(\Illuminate\Support\Facades\Artisan::output()) ?: 'Bir parça çalıştırıldı.');
        $this->runChecks();
    }

    public function runChecks(): void
    {
        $this->checks = [];

        $this->checks[] = $this->probe('Çalışan sürüm', function (): string {
            $commit = \App\Support\AppVersion::commit();
            if ($commit === null) {
                throw new RuntimeException('Sürüm okunamadı (.git yok).');
            }

            return $commit.' · GitHub main ile aynıysa güncelleme uygulanmış demektir';
        });

        $this->checks[] = $this->probe('Yeniden konumlama', function (): string {
            $until = \App\Support\Settings::string('scraper_relocate_force_until');
            $p = json_decode(\App\Support\Settings::string('scraper_relocate_force_progress') ?: '{}', true) ?: [];
            $summary = isset($p['at']) ? ' · bakılan '.(int) ($p['done'] ?? 0).', değişen '.(int) ($p['changed'] ?? 0).' · son parça '.\Illuminate\Support\Carbon::parse($p['at'])->format('d.m H:i') : '';
            if ($until === '') {
                return ($p['finished'] ?? false) ? 'tamamlandı'.$summary : 'planlı değil'.$summary;
            }
            $cursor = (int) \App\Support\Settings::string('scraper_relocate_force_cursor');
            $remaining = \App\Models\ScrapedLoad::query()->where('status', '!=', 'rejected')->where('created_at', '>=', now()->subDays(14))->when($cursor > 0, fn ($q) => $q->where('id', '<', $cursor))->count();

            return 'sürüyor · kalan '.$remaining.' ilan (5 dk\'da bir parça, en yeniden eskiye)'.$summary.' · bitiş en geç '.\Illuminate\Support\Carbon::parse($until)->format('d.m H:i');
        });

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

        // ---- İşletim probları (2026-10-05, denetim Y15): her biri tek ucuz sorgu; kırmızı = hemen bakılmalı, sarı = izlenmeli.
        $this->checks[] = $this->probe('Zamanlayıcı', function (): string|array {
            $ts = Cache::get('scheduler.heartbeat');
            if (! $ts) {
                throw new RuntimeException('Hiç nabız yok: sunucuda "schedule:run" cron satırı çalışmıyor; süreli görevler (teklif süresi, otomatik onay, yedek) durur.');
            }
            $age = max(0, now()->timestamp - (int) $ts);
            if ($age > 600) {
                throw new RuntimeException('Son nabız '.floor($age / 60).' dk önce; zamanlayıcı durmuş görünüyor.');
            }

            return ($age > 180 ? ['warn' => true, 'detail' => 'son nabız '.floor($age / 60).' dk önce (gecikmeli)'] : 'çalışıyor · son nabız '.$age.' sn önce');
        });

        $this->checks[] = $this->probe('Son yedek', function (): string|array {
            $last = \App\Models\Backup::query()->where('status', 'completed')->latest('completed_at')->first();
            if (! $last || ! $last->completed_at) {
                throw new RuntimeException('Hiç tamamlanmış yedek yok; Yedekleme ekranından ilk yedeği alın.');
            }
            $hours = $last->completed_at->diffInHours(now());
            if ($hours > 26) {
                throw new RuntimeException('Son başarılı yedek '.floor($hours).' saat önce ('.$last->completed_at->format('d.m H:i').'); gece yedeği çalışmamış.');
            }
            $failed = \App\Models\Backup::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

            return $failed > 0
                ? ['warn' => true, 'detail' => 'son başarılı '.$last->completed_at->format('d.m H:i').' · son 24 saatte '.$failed.' başarısız deneme']
                : 'son başarılı '.$last->completed_at->format('d.m H:i').' ('.number_format((float) $last->size_mb, 1, ',', '.').' MB)';
        });

        $this->checks[] = $this->probe('Disk doluluğu', function (): string|array {
            $root = storage_path();
            $free = @disk_free_space($root);
            $total = @disk_total_space($root);
            if ($free === false || ! $total) {
                throw new RuntimeException('Disk bilgisi okunamadı.');
            }
            $pct = (int) round($free / $total * 100);
            $text = $pct.'% boş · '.number_format($free / 1073741824, 1, ',', '.').' / '.number_format($total / 1073741824, 1, ',', '.').' GB';
            if ($pct < 15) {
                throw new RuntimeException('Disk dolmak üzere: '.$text.'. Eski yedekleri ve günlükleri temizleyin.');
            }

            return $pct < 25 ? ['warn' => true, 'detail' => $text] : $text;
        });

        $this->checks[] = $this->probe('E-posta (24 saat)', function (): string|array {
            $since = now()->subDay();
            $failed = \App\Models\UserNotification::query()->where('created_at', '>=', $since)->where('mail_status', \App\Models\UserNotification::MAIL_FAILED)->count();
            $sent = \App\Models\UserNotification::query()->where('created_at', '>=', $since)->where('mail_status', \App\Models\UserNotification::MAIL_SENT)->count();
            if ($failed > 0 && $failed >= $sent) {
                throw new RuntimeException($failed.' e-posta gönderilemedi, '.$sent.' gönderildi; SMTP ayarlarını ve "Başarısızları yeniden dene" düğmesini kontrol edin.');
            }

            return $failed > 0 ? ['warn' => true, 'detail' => $sent.' gönderildi · '.$failed.' başarısız (yeniden denenir)'] : $sent.' gönderildi · 0 başarısız';
        });

        $this->checks[] = $this->probe('Ödeme kuruluşu', function (): string|array {
            $payments = app(\App\Services\PaymentService::class);
            $gateway = app(\App\Payments\GatewayManager::class)->selected();
            if (! $payments->isConfigured()) {
                throw new RuntimeException($gateway->label().': anahtarlar tanımlı değil; navlun ve premium ödemesi alınamaz.');
            }

            return $payments->isSandbox() ? ['warn' => true, 'detail' => $gateway->label().' · TEST (sandbox) modu; gerçek para çekilmez'] : $gateway->label().' · canlı';
        });

        $this->checks[] = $this->probe('Telefon akışı', function (): string|array {
            $last = \App\Models\IntakeEvent::query()->max('created_at');
            if (! $last) {
                return ['warn' => true, 'detail' => 'henüz hiç mesaj gelmedi'];
            }
            $at = \Illuminate\Support\Carbon::parse($last);
            $hours = $at->diffInHours(now());
            if ($hours >= 12) {
                throw new RuntimeException('Telefondan son mesaj '.floor($hours).' saat önce ('.$at->format('d.m H:i').'); iletici uygulama ya da telefon kapalı olabilir.');
            }

            return $hours >= 3 ? ['warn' => true, 'detail' => 'son mesaj '.floor($hours).' saat önce'] : 'son mesaj '.\App\Support\TimeAgo::label($at);
        });

        $this->checks[] = $this->probe('Yapay zeka sağlayıcıları', function (): string|array {
            $parser = app(\App\Services\AiParserService::class);
            if (! method_exists($parser, 'providerStatus')) {
                return 'durum okunamıyor';
            }
            $status = $parser->providerStatus();
            if ($status === []) {
                return ['warn' => true, 'detail' => 'tanımlı sağlayıcı yok; ilanlar yalnız kuralla çözülür'];
            }
            $ok = array_keys(array_filter($status, fn ($s) => $s['state'] === 'ok'));
            $down = array_keys(array_filter($status, fn ($s) => $s['state'] !== 'ok'));
            if ($ok === []) {
                throw new RuntimeException('Tüm sağlayıcılar kota/soğuma nedeniyle kapalı ('.implode(', ', $down).'); adaylar kural ve yerel sınıflandırıcıyla karar bekliyor.');
            }

            return $down === [] ? count($ok).' sağlayıcı hazır' : ['warn' => true, 'detail' => count($ok).' hazır · kapalı: '.implode(', ', $down)];
        });

        $this->checks[] = $this->probe('İade bekleyen emir', function (): string|array {
            $count = \App\Models\PaymentOrder::query()->where('status', 'refund_pending')->count();
            if ($count > 0) {
                throw new RuntimeException($count.' emir iade bekliyor (ödeme kuruluşu iadeyi yapamadı); Finans ekranında "İade yapıldı" ile elle kapatılır.');
            }

            return '0 emir';
        });

        $this->checks[] = $this->probe('Takılı hakediş', function (): string|array {
            $count = \App\Models\Payout::query()->where('status', 'processing')->where('updated_at', '<', now()->subHours(48))->count();
            if ($count > 0) {
                throw new RuntimeException($count.' hakediş 48 saatten uzun süredir "transfer yapılıyor" durumunda; banka sonucu girilmemiş olabilir.');
            }

            return '0 hakediş';
        });

        $this->checks[] = $this->probe('Başarısız işler', function (): string {
            if (! Schema::hasTable('failed_jobs')) {
                throw new RuntimeException('failed_jobs tablosu yok.');
            }
            $count = DB::table('failed_jobs')->count();
            if ($count > 0) {
                $last = DB::table('failed_jobs')->orderByDesc('id')->first();
                $payload = json_decode((string) ($last->payload ?? ''), true);
                $name = class_basename((string) ($payload['displayName'] ?? 'İş'));
                $reason = mb_substr(trim((string) strtok((string) ($last->exception ?? ''), "\n")), 0, 80);
                throw new RuntimeException($count.' başarısız iş bekliyor. Sonuncusu: '.$name.($reason !== '' ? ' — '.$reason : '').'. İlan kaybettirmez; yeniden deneyin ya da temizleyin (3 günden eskiler kendiliğinden silinir).');
            }

            return '0 başarısız iş';
        });

        $this->checks[] = $this->probe('Sabit kodla giriş', function (): string {
            if (! \App\Services\OtpService::reviewLoginActive()) {
                return 'Kapalı (herkes e-posta koduyla girer)';
            }
            $until = \App\Support\Settings::string('review_login_until');
            throw new RuntimeException('AÇIK: '.\App\Support\Settings::string('review_login_emails').' sabit kodla giriyor'.($until !== '' ? ' (bitiş '.$until.')' : ' (süresiz)').'. İnceleme bitince Ayarlar → Genel bölümünden kodu silin.');
        });

        $this->checks[] = $this->probe('Öğrenme çemberi', function (): string {
            $s = app(\App\Services\RuleFeedbackService::class)->weeklyStats();

            return "bu hafta kuralla çözülen {$s['rule']} · yapay zeka gereken {$s['ai']} · denetlenen {$s['audited']} (uyuşmazlık {$s['mismatched']}) · bekleyen öneri {$s['pending']} · kendiliğinden onaylanan {$s['auto_approved']}";
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

    /** Geri dönüş: metin (yeşil), ['warn' => true, 'detail' => …] (sarı) ya da istisna (kırmızı). */
    private function probe(string $name, callable $callback): array
    {
        try {
            $result = $callback();
            if (is_array($result)) {
                return ['name' => $name, 'ok' => true, 'warn' => ! empty($result['warn']), 'detail' => (string) ($result['detail'] ?? '')];
            }

            return ['name' => $name, 'ok' => true, 'warn' => false, 'detail' => (string) $result];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'warn' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** @return array{red:int, amber:int, names:list<string>} */
    public function summary(): array
    {
        $red = array_values(array_filter($this->checks, fn ($c) => ! $c['ok']));
        $amber = array_values(array_filter($this->checks, fn ($c) => $c['ok'] && ! empty($c['warn'])));

        return ['red' => count($red), 'amber' => count($amber), 'names' => array_map(fn ($c) => $c['name'], array_merge($red, $amber))];
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
            @if(auth()->user()->can('manage system'))
                <button type="button" wire:click="startUpdate" wire:loading.attr="disabled" wire:confirm="Sunucu son sürüme güncellenecek; birkaç dakika sürer. Devam edilsin mi?" @disabled($update['running']) class="btn-apple-brand px-4 py-2.5 text-xs font-semibold disabled:opacity-50 shrink-0">
                    {{ $update['running'] ? 'Güncelleme çalışıyor…' : 'Siteyi güncelle' }}
                </button>
            @else
                <span class="text-[11px] text-neutral-400 shrink-0">Güncelleme yalnız sistem yönetimi yetkisiyle başlatılır.</span>
            @endif
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

    @php $sum = $this->summary(); @endphp
    <div class="rounded-2xl border p-4 text-xs flex flex-wrap items-center gap-2 {{ $sum['red'] > 0 ? 'border-red-500/30 bg-red-500/5 text-red-700 dark:text-red-300' : ($sum['amber'] > 0 ? 'border-amber-500/30 bg-amber-500/5 text-amber-700 dark:text-amber-300' : 'border-emerald-500/20 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300') }}" data-health-summary>
        <span class="h-2.5 w-2.5 rounded-full {{ $sum['red'] > 0 ? 'bg-red-500' : ($sum['amber'] > 0 ? 'bg-amber-500' : 'bg-emerald-500') }}"></span>
        <span class="font-bold">{{ $sum['red'] > 0 ? $sum['red'].' kontrol kırmızı' : ($sum['amber'] > 0 ? 'Kırmızı yok' : 'Her şey yolunda') }}{{ $sum['amber'] > 0 ? ' · '.$sum['amber'].' sarı' : '' }}</span>
        @if($sum['names'] !== [])<span class="text-[11px] opacity-80">{{ implode(' · ', $sum['names']) }}</span>@endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($checks as $check)
            @php $tone = ! $check['ok'] ? 'red' : (! empty($check['warn']) ? 'amber' : 'emerald'); @endphp
            <div class="apple-glass rounded-2xl border p-5 {{ $tone === 'red' ? 'border-red-500/30' : ($tone === 'amber' ? 'border-amber-500/30' : 'border-emerald-500/20') }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-neutral-500">{{ $check['name'] }}</span>
                    <span class="h-2.5 w-2.5 rounded-full {{ $tone === 'red' ? 'bg-red-500' : ($tone === 'amber' ? 'bg-amber-500' : 'bg-emerald-500') }}"></span>
                </div>
                <p class="mt-3 text-xs font-semibold text-neutral-900 dark:text-white break-words">{{ $check['detail'] }}</p>
                @if($check['name'] === 'Yeniden konumlama' && str_starts_with($check['detail'], 'sürüyor') && auth()->user()->can('manage system'))
                    <div class="mt-3">
                        <button type="button" wire:click="runRelocateBatch" wire:loading.attr="disabled" class="btn-secondary py-1.5 px-3 text-xs">
                            <span wire:loading.remove wire:target="runRelocateBatch">Bir parça şimdi çalıştır</span>
                            <span wire:loading wire:target="runRelocateBatch">Çalışıyor…</span>
                        </button>
                    </div>
                @endif
                @if($check['name'] === 'Başarısız işler' && ! $check['ok'] && auth()->user()->can('manage system'))
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="retryFailedJobs" class="btn-secondary py-1.5 px-3 text-xs">Yeniden dene</button>
                        <button type="button" wire:click="flushFailedJobs" wire:confirm="Başarısız işler silinsin mi? Telefon mesajı işleri Canlı akışta hata olarak zaten görünür; ilan kaybolmaz." class="text-red-600 text-xs font-semibold hover:underline">Temizle</button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="apple-glass rounded-3xl p-6">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Zamanlanmış görevler</h2>
            <p class="mt-1 text-xs text-neutral-500">Sunucuda her dakika çalışan "schedule:run" tetikleyicisine bağlıdır; çalışıp çalışmadığı yukarıdaki "Zamanlayıcı" kartında görünür. Tam liste routes/console.php içindedir.</p>
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
