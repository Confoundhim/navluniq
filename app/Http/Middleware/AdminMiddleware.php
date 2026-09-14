<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /** Yönetim paneli rotalarını yalnız aktif panel personeline açar. */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('admin.login')->with('error', 'Lütfen önce giriş yapın.');
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdminPanelUser()) {
            if ($user->current_role === 'admin') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.login')->with('error', 'Hesabınızın yönetim paneli erişimi kapatılmış.');
            }

            return redirect()->route('panel');
        }

        return $next($request);
    }
}
