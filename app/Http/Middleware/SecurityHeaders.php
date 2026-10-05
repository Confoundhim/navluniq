<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İçerik güvenliği politikası (CSP, I14). Önce yalnız RAPOR kipinde çalışır: tarayıcı politikaya aykırı bir şey görürse
 * sayfayı bozmadan /csp-rapor adresine bildirir, günlükte toplanır. Livewire/Alpine satır içi betik ve eval kullandığı
 * için script-src gevşektir; 1-2 hafta temiz rapor sonrası panel ayarı csp_enforce=1 ile zorunlu kipe geçilir.
 */
class SecurityHeaders
{
    public const REPORT_PATH = '/csp-rapor';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set(self::headerName(), self::policy());
        }

        return $response;
    }

    public static function headerName(): string
    {
        try {
            $enforce = Settings::bool('csp_enforce');
        } catch (\Throwable) {
            $enforce = false; // veritabanı hazır değil (kurulum): rapor kipi
        }

        return $enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }

    public static function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://*.tile.openstreetmap.org",
            "font-src 'self' data:",
            "connect-src 'self'",
            'frame-src https://*.iyzipay.com',
            "form-action 'self' https://*.iyzipay.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            'report-uri '.self::REPORT_PATH,
        ]);
    }
}
