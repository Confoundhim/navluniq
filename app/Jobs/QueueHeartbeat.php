<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Kuyruk nabzı: zamanlayıcı her dakika bu işi kuyruğa bırakır; bir işçi çalıştırınca önbelleğe zaman damgası yazar.
 * Nabız tazeyse (işçi gerçekten iş alıyorsa) telefondan gelen mesajlar kuyruğa verilir; değilse istek içinde işlenir.
 * Böylece işçi durursa ya da yanlış bağlantıyı dinlerse ilan akışı durmaz, yalnız yavaşlar.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'queue.heartbeat';

    /** Nabız bundan eskiyse kuyruk "çalışmıyor" sayılır. */
    public const MAX_AGE_SECONDS = 180;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, now()->timestamp, now()->addDay());
    }

    /** Son nabız kaç saniye önce; hiç yoksa null. */
    public static function ageSeconds(): ?int
    {
        $ts = Cache::get(self::CACHE_KEY);

        return $ts ? max(0, now()->timestamp - (int) $ts) : null;
    }

    /** Kuyruk işçisi son 3 dakikada iş almışsa true. */
    public static function alive(): bool
    {
        $age = self::ageSeconds();

        return $age !== null && $age < self::MAX_AGE_SECONDS;
    }
}
