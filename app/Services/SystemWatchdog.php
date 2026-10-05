<?php

namespace App\Services;

use App\Jobs\QueueHeartbeat;
use App\Models\Backup;
use App\Models\IntakeEvent;
use App\Models\UserNotification;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Sistem bekçisi (I1): 5 dakikada bir kuyruk, zamanlayıcı, başarısız iş, yedek, disk, telefon sessizliği, e-posta,
 * Redis, TLS ve yeniden konumlama denetimi. Sorun → Telegram'a (alert_telegram_chat_id) ve "manage settings" yetkili
 * yöneticilere bildirim; aynı uyarı 6 saatte bir tekrarlanır; düzelince "düzeldi" gider. Son durum ayarda saklanır,
 * sağlık ekranı lastStatus() ile okur. /up adresi de diagnose() ile gerçek sağlık döner (veritabanı, önbellek, zamanlayıcı).
 */
class SystemWatchdog
{
    public const ALERT_TTL_HOURS = 6;

    public const ACTIVE_KEY = 'watchdog:active';

    public const FAILED_JOBS_CURSOR = 'watchdog:failed_jobs_max_id';

    /** Uyarı anahtarı → insan dili başlık. */
    public const LABELS = [
        'queue' => 'Kuyruk işçisi',
        'jobs_backlog' => 'Kuyruk yığılması',
        'failed_jobs' => 'Başarısız işler',
        'backup' => 'Yedek',
        'disk' => 'Disk',
        'intake_silence' => 'Telefon sessiz',
        'mail' => 'E-posta',
        'redis' => 'Redis',
        'tls' => 'SSL sertifikası',
        'relocate' => 'Yeniden konumlama',
    ];

    /** Olay niteliğinde uyarılar: "düzeldi" mesajı anlamsız (yeni başarısız iş geldi → gitti değil). */
    public const NO_RECOVERY = ['failed_jobs'];

    public function __construct(private NotificationService $notifications, private TelegramPublisher $telegram) {}

    /**
     * Tüm denetimleri çalıştırır, yeni uyarıları ve düzelmeleri iletir, son durumu ayara yazar.
     *
     * @return array{at: string, alerts: array<string, string>, checks: array<string, array{ok: bool, note: string}>, sent: list<string>, recovered: list<string>}
     */
    public function run(): array
    {
        $checks = [];
        foreach ($this->checks() as $key => $fn) {
            try {
                $problem = $fn();
                $checks[$key] = ['ok' => $problem === null, 'note' => (string) ($problem ?? '')];
            } catch (Throwable $e) {
                // Denetimin kendisi patladıysa (tablo yok, bağlantı yok) bu da bir uyarıdır.
                $checks[$key] = ['ok' => false, 'note' => 'Denetim çalışmadı: '.mb_substr($e->getMessage(), 0, 200)];
            }
        }
        $alerts = [];
        foreach ($checks as $key => $c) {
            if (! $c['ok']) {
                $alerts[$key] = $c['note'];
            }
        }

        $previous = array_values(array_filter((array) Cache::get(self::ACTIVE_KEY, []), 'is_string'));
        $recovered = array_values(array_filter(array_diff($previous, array_keys($alerts)), fn ($k) => ! in_array($k, self::NO_RECOVERY, true)));

        $sent = [];
        foreach ($alerts as $key => $note) {
            // Aynı uyarı 6 saat boyunca tekrar gönderilmez (sürüyorsa 6 saatte bir hatırlatır).
            if (Cache::add('watchdog:alert:'.$key, now()->toDateTimeString(), now()->addHours(self::ALERT_TTL_HOURS))) {
                $sent[] = $key;
            }
        }
        foreach ($recovered as $key) {
            Cache::forget('watchdog:alert:'.$key);
        }

        if ($sent !== []) {
            $this->deliver('⚠️ NavlunIQ uyarı', array_map(fn ($k) => (self::LABELS[$k] ?? $k).': '.$alerts[$k], $sent));
        }
        if ($recovered !== []) {
            $this->deliver('✅ NavlunIQ düzeldi', array_map(fn ($k) => (self::LABELS[$k] ?? $k).' sorunu düzeldi.', $recovered));
        }

        // Aktif liste: düzelme bildirimi için (olay niteliğindekiler listeye girmez).
        Cache::put(self::ACTIVE_KEY, array_values(array_diff(array_keys($alerts), self::NO_RECOVERY)), now()->addDays(7));

        $result = ['at' => now()->toDateTimeString(), 'alerts' => $alerts, 'checks' => $checks, 'sent' => $sent, 'recovered' => $recovered];
        try {
            Settings::set('watchdog_last_run', json_encode($result, JSON_UNESCAPED_UNICODE));
            if ($sent !== [] || $recovered !== []) {
                Settings::set('watchdog_last_alert', json_encode(['at' => $result['at'], 'sent' => $sent, 'recovered' => $recovered, 'alerts' => array_intersect_key($alerts, array_flip($sent))], JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable $e) {
            Log::warning('Bekçi durumu yazılamadı.', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Sağlık ekranı için son çalışma ve son uyarı (ayarlardan). Hiç çalışmadıysa last_run null.
     *
     * @return array{last_run: ?array, last_alert: ?array, stale: bool}
     */
    public static function lastStatus(): array
    {
        try {
            $run = json_decode(Settings::string('watchdog_last_run') ?: 'null', true);
            $alert = json_decode(Settings::string('watchdog_last_alert') ?: 'null', true);
        } catch (Throwable) {
            $run = $alert = null;
        }
        $run = is_array($run) ? $run : null;
        $stale = $run === null || Carbon::parse($run['at'], config('app.timezone'))->lt(now()->subMinutes(15));

        return ['last_run' => $run, 'last_alert' => is_array($alert) ? $alert : null, 'stale' => $stale];
    }

    /**
     * /up adresi: veritabanı, önbellek ve zamanlayıcı gerçekten çalışıyor mu? Sorun varsa fırlatır (Laravel 500 döner).
     * Zamanlayıcı nabzı hiç yazılmamışsa (yeni kurulum) o denetim atlanır.
     */
    public static function diagnose(): void
    {
        DB::select('select 1');

        $probe = 'health.probe.'.random_int(1000, 9999);
        Cache::put($probe, 1, 60);
        if (Cache::get($probe) !== 1) {
            throw new RuntimeException('Önbellek yazıp okuyamıyor.');
        }
        Cache::forget($probe);

        $ts = Cache::get('scheduler.heartbeat');
        if ($ts !== null && now()->timestamp - (int) $ts > 300) {
            throw new RuntimeException('Zamanlayıcı 5 dakikadan uzun süredir çalışmıyor (crontab schedule:run).');
        }
    }

    /** @return array<string, callable(): ?string> anahtar → sorun metni ya da null */
    private function checks(): array
    {
        return [
            'queue' => fn () => $this->checkQueue(),
            'jobs_backlog' => fn () => $this->checkJobsBacklog(),
            'failed_jobs' => fn () => $this->checkFailedJobs(),
            'backup' => fn () => $this->checkBackup(),
            'disk' => fn () => $this->checkDisk(),
            'intake_silence' => fn () => $this->checkIntakeSilence(),
            'mail' => fn () => $this->checkMail(),
            'redis' => fn () => $this->checkRedis(),
            'tls' => fn () => $this->checkTls(),
            'relocate' => fn () => $this->checkRelocate(),
        ];
    }

    private function checkQueue(): ?string
    {
        $age = QueueHeartbeat::ageSeconds();
        if ($age === null) {
            return null; // hiç yazılmadı (yeni kurulum ya da işçi hiç çalışmadı): zamanlayıcı nabzı /up'ta izlenir
        }

        return $age > 300 ? sprintf("Kuyruk işçisi %d dk'dır iş almıyor (supervisor navluniq-worker durmuş olabilir).", intdiv($age, 60)) : null;
    }

    private function checkJobsBacklog(): ?string
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }
        $n = DB::table('jobs')->count();

        return $n > 500 ? "Kuyrukta {$n} iş bekliyor; işçiler yetişmiyor." : null;
    }

    private function checkFailedJobs(): ?string
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }
        $max = (int) (DB::table('failed_jobs')->max('id') ?? 0);
        $last = Cache::get(self::FAILED_JOBS_CURSOR);
        Cache::put(self::FAILED_JOBS_CURSOR, $max, now()->addDays(30));
        if ($last === null) {
            return null; // ilk çalışma: eşik kurulur, geçmiş sayılmaz
        }
        $new = DB::table('failed_jobs')->where('id', '>', (int) $last)->count();
        if ($new === 0) {
            return null;
        }
        $latest = DB::table('failed_jobs')->where('id', '>', (int) $last)->orderByDesc('id')->first();
        $payload = json_decode((string) ($latest->payload ?? ''), true);
        $name = class_basename((string) ($payload['displayName'] ?? 'bilinmiyor'));
        $reason = mb_substr(strtok((string) ($latest->exception ?? ''), "\n") ?: '', 0, 120);

        return "{$new} yeni başarısız iş (son: {$name}".($reason !== '' ? " — {$reason}" : '').').';
    }

    private function checkBackup(): ?string
    {
        $latest = Backup::query()->latest('id')->first();
        if (! $latest) {
            return null; // hiç yedek alınmadı (yeni kurulum)
        }
        if ($latest->status === 'failed') {
            return 'Son yedek başarısız: '.mb_substr((string) $latest->failure_message, 0, 160);
        }
        $full = Backup::query()->where('backup_type', 'full')->where('status', 'completed')->orderByDesc('completed_at')->first();
        if (! $full) {
            return Backup::query()->where('backup_type', 'full')->exists() ? 'Hiç tamamlanmış tam yedek yok.' : null;
        }
        if ($full->completed_at && $full->completed_at->lt(now()->subHours(26))) {
            return 'Son tamamlanan tam yedek '.$full->completed_at->diffInHours(now()).' saat önce (gece yedeği çalışmadı).';
        }

        return null;
    }

    private function checkDisk(): ?string
    {
        $override = config('watchdog.disk');
        if (is_array($override)) {
            $free = (float) ($override['free'] ?? 0);
            $total = (float) ($override['total'] ?? 0);
        } else {
            $free = (float) (@disk_free_space(base_path()) ?: 0);
            $total = (float) (@disk_total_space(base_path()) ?: 0);
        }
        if ($total <= 0) {
            return null;
        }
        $pct = $free / $total * 100;
        if ($free < 2 * 1024 ** 3 || $pct < 15) {
            return sprintf('Disk doluyor: %s boş (%%%d). Yedekler ve günlükler yer kaplıyor olabilir.', self::humanBytes($free), (int) $pct);
        }

        return null;
    }

    private function checkIntakeSilence(): ?string
    {
        $hours = max(1, Settings::int('intake_silence_alert_hours'));
        $now = now();
        if ($now->hour < 7 || $now->hour >= 23) {
            return null; // gece sessizliği beklenir
        }
        $lastRaw = IntakeEvent::query()->max('created_at');
        if (! $lastRaw) {
            return null; // telefon hiç bağlanmadı (kurulum)
        }
        $last = Carbon::parse($lastRaw, config('app.timezone'));
        // Sayaç sabah 07:00'dan başlar: gece 23:00-07:00 arası sessizlik sabah ilk çalıştırmada uyarı üretmez.
        $since = $last->max($now->copy()->setTime(7, 0));
        if ($since->lte($now->copy()->subHours($hours))) {
            return sprintf('Telefondan %d saattir istek gelmedi (son: %s). Toplayıcı uygulaması ya da telefon kapanmış olabilir.', (int) $since->diffInHours($now), $last->format('H:i'));
        }

        return null;
    }

    private function checkMail(): ?string
    {
        $n = UserNotification::query()->where('mail_status', UserNotification::MAIL_FAILED)->where('updated_at', '>=', now()->subHour())->count();

        return $n > 10 ? "Son bir saatte {$n} e-posta gönderilemedi (SMTP ayarı ya da sağlayıcı)." : null;
    }

    private function checkRedis(): ?string
    {
        if (config('cache.default') !== 'redis' && config('session.driver') !== 'redis' && config('queue.default') !== 'redis') {
            return null;
        }
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            return 'Redis cevap vermiyor: '.mb_substr($e->getMessage(), 0, 120).' (systemctl restart redis-server)';
        }

        return null;
    }

    private function checkTls(): ?string
    {
        $forced = config('watchdog.tls_expires_at');
        if ($forced === null && app()->environment(['local', 'testing'])) {
            return null;
        }
        $url = (string) config('app.url');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $expires = $forced !== null ? Carbon::parse($forced, config('app.timezone')) : $this->fetchCertificateExpiry($host);
        if ($expires === null) {
            return null; // bağlanılamadı; ağ sorunu başka denetimlerde görünür
        }
        $days = (int) now()->diffInDays($expires, false);
        if ($days < 14) {
            return $days < 0
                ? "SSL sertifikası {$host} için süresi dolmuş! (certbot renew)"
                : "SSL sertifikası {$host} için {$days} gün sonra bitiyor (certbot renew çalışmıyor olabilir).";
        }

        return null;
    }

    private function fetchCertificateExpiry(string $host): ?Carbon
    {
        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $client = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (! $client) {
                return null;
            }
            $params = stream_context_get_params($client);
            fclose($client);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            $info = $cert ? openssl_x509_parse($cert) : null;
            $to = $info['validTo_time_t'] ?? null;

            return $to ? Carbon::createFromTimestamp((int) $to, config('app.timezone')) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function checkRelocate(): ?string
    {
        $until = Settings::string('scraper_relocate_force_until');
        if ($until === '' || now()->gt(Carbon::parse($until, config('app.timezone')))) {
            return null;
        }
        $progress = json_decode(Settings::string('scraper_relocate_force_progress') ?: '{}', true) ?: [];
        $at = $progress['at'] ?? null;
        if ($at && Carbon::parse($at, config('app.timezone'))->lt(now()->subMinutes(30))) {
            return "Yeniden konumlama 30 dk'dır ilerlemiyor (son parça ".Carbon::parse($at, config('app.timezone'))->format('H:i').'; scraped-loads:relocate-force).';
        }

        return null;
    }

    /** @param  list<string>  $lines */
    private function deliver(string $title, array $lines): void
    {
        $chatId = Settings::string('alert_telegram_chat_id');
        if ($chatId !== '' && TelegramPublisher::hasBotToken()) {
            try {
                $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $this->telegram->sendTo($chatId, '<b>'.$e($title).'</b>'."\n".implode("\n", array_map(fn ($l) => '• '.$e($l), $lines)));
            } catch (Throwable $ex) {
                Log::warning('Bekçi Telegram mesajı gönderilemedi.', ['error' => $ex->getMessage()]);
            }
        }
        try {
            $url = null;
            try {
                $url = route('admin.health');
            } catch (Throwable) {
                // rota yoksa bağlantısız
            }
            $this->notifications->notifyAdmins('manage settings', $title, $lines, $url, $url ? 'Sistem sağlığı' : null, 'admin');
        } catch (Throwable $ex) {
            Log::warning('Bekçi panel bildirimi yazılamadı.', ['error' => $ex->getMessage()]);
        }
    }

    public static function humanBytes(float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return number_format($bytes, $unit === 'B' || $unit === 'KB' ? 0 : 1, ',', '.').' '.$unit;
            }
            $bytes /= 1024;
        }

        return number_format($bytes, 1, ',', '.').' TB';
    }
}
