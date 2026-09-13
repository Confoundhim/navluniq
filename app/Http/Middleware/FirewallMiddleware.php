<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\BannedIp;

class FirewallMiddleware
{
    /**
     * Gelen isteğin IP adresini güvenlik duvarı (Firewall) kurallarına göre denetler.
     * Eğer IP yasaklılar listesindeyse isteği anında 403 Forbidden ile keser. [18]
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();

        // 1. Gelen IP veritabanında yasaklılar listesinde var mı? [18]
        $banned = BannedIp::where('ip_address', $clientIp)->first();

        if ($banned && $banned->isBanned()) {
            // Yasaklı kullanıcıya gösterilecek minimalist engelleme yanıtı
            return response()->view('errors.firewall-blocked', [
                'ip' => $clientIp,
                'reason' => $banned->reason,
                'until' => $banned->banned_until ? $banned->banned_until->format('Y-m-d H:i:s') : 'Kalıcı Engel'
            ], 403);
        }

        return $next($request);
    }
}
