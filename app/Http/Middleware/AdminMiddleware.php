<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Gelen isteklerin sadece yetkilendirilmiş admin kullanıcıları tarafından
     * erişilebilir olduğunu kontrol eden güvenlik katmanı.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Kullanıcı giriş yapmış mı?
        if (!Auth::check()) {
            return redirect()->route('admin.login')->with('error', 'Lütfen önce giriş yapın.');
        }

        /**
         * Editörün hasRole gibi Spatie metotlarını tanıması için değişken tipini tanımlıyoruz.
         * @var \App\Models\User $user
         */
        $user = Auth::user();

        // 2. Kullanıcı aktif mi ve rolü "admin" mi? (Süper admin yetkisine de sahip olması gerekir)
        if ($user->current_role !== 'admin' || !$user->hasAnyRole(['super_admin', 'kyc_validator', 'financial_officer']) || !$user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->with('error', 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        return $next($request);
    }
}
