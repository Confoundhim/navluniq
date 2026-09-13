<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCargoOwner
{
    /**
     * Gelen isteği denetler.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Kullanıcı giriş yapmamışsa genel giriş sayfasına yönlendir
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Kullanıcı hesabı pasif (is_active = false) veya yasaklı ise oturumu kapat
        if (!$user->is_active || $user->banned_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'auth' => 'Hesabınız askıya alınmıştır veya erişim yetkiniz bulunmamaktadır.',
            ]);
        }

        // Kullanıcının mevcut rolü cargo_owner veya admin olmalıdır
        if ($user->current_role !== 'cargo_owner' || !$user->cargoOwnerProfile) {
            // Eğer şoför rolündeyse şoför paneline yönlendir
            if ($user->current_role === 'driver') {
                return redirect()->route('for-drivers');
            }

            return redirect()->route('home');
        }

        return $next($request);
    }
}
