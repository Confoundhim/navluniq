<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriver
{
    /**
     * Gelen isteği denetler.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Kullanıcı pasif veya yasaklı ise oturumu kapat
        if (!$user->is_active || $user->banned_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'auth' => 'Hesabınız askıya alınmıştır veya erişim yetkiniz bulunmamaktadır.',
            ]);
        }

        // Kullanıcının mevcut rolü driver veya admin olmalıdır
        if ($user->current_role !== 'driver' || !$user->driverProfile) {
            if ($user->current_role === 'cargo_owner') {
                return redirect()->route('cargo-owner.dashboard');
            }

            return redirect()->route('home');
        }

        return $next($request);
    }
}
