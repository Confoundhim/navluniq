<?php

namespace App\Http\Middleware;

use App\Models\BannedIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class FirewallMiddleware
{
    public const CACHE_KEY = 'firewall.banned_ips';

    /**
     * Gelen isteğin IP adresini yasaklı listesine göre denetler; liste 60 saniye önbellekte tutulur.
     * Yasaklama/kaldırma işlemleri önbelleği anında temizler.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = (string) $request->ip();
        $entry = $this->bannedList()[$clientIp] ?? null;

        if ($entry !== null) {
            $until = $entry['banned_until'] ? Carbon::parse($entry['banned_until']) : null;

            if ($until === null || $until->isFuture()) {
                return response()->view('errors.firewall-blocked', [
                    'ip' => $clientIp,
                    'reason' => $entry['reason'],
                    'until' => $until ? $until->format('Y-m-d H:i:s') : 'Kalıcı Engel',
                ], 403);
            }
        }

        return $next($request);
    }

    /** @return array<string, array{reason: string, banned_until: ?string}> */
    private function bannedList(): array
    {
        $load = static fn (): array => BannedIp::query()
            ->where(fn ($q) => $q->whereNull('banned_until')->orWhere('banned_until', '>', now()))
            ->get(['ip_address', 'reason', 'banned_until'])
            ->mapWithKeys(fn (BannedIp $ban) => [$ban->ip_address => [
                'reason' => $ban->reason,
                'banned_until' => $ban->banned_until?->toIso8601String(),
            ]])
            ->all();

        try {
            return Cache::remember(self::CACHE_KEY, 60, $load);
        } catch (\Throwable) {
            return $load();
        }
    }
}
