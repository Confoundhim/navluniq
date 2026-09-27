<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yavaş istekleri günlüğe yazar: yol, süre, veritabanında geçen süre ve sorgu sayısı, kullanıcı.
 * "Sayfa 2 dakikada açıldı" gibi şikâyetlerde hangi isteğin ve isteğin hangi kısmının (PHP mi, veritabanı mı)
 * yavaş olduğu storage/logs/laravel.log içinden okunur. Eşik saniye cinsindendir (config app.slow_request_seconds).
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $queries = 0;
        $dbMs = 0.0;
        DB::listen(function ($query) use (&$queries, &$dbMs): void {
            $queries++;
            $dbMs += (float) $query->time;
        });

        $response = $next($request);

        $seconds = microtime(true) - $started;
        $threshold = (float) config('app.slow_request_seconds', 3);
        if ($threshold > 0 && $seconds >= $threshold) {
            Log::warning('Yavaş istek', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'livewire' => (string) $request->header('X-Livewire', ''),
                'seconds' => round($seconds, 2),
                'db_seconds' => round($dbMs / 1000, 2),
                'queries' => $queries,
                'user_id' => $request->user()?->id,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576),
            ]);
        }

        return $response;
    }
}
