<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // NavlunIQ Özel Güvenlik Ara Yazılımları
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        // Güvenlik Duvarı (Firewall)
        $middleware->append(\App\Http\Middleware\FirewallMiddleware::class);

        // Misafir Yönlendirme Kapısı [6]
        $middleware->redirectGuestsTo('/giris');

        // 🚀 DİNAMİK KULLANICI YÖNLENDİRMESİ:
        // Intelephense uyarısını önlemek için doğrudan resmi Auth Facade sınıfı kullanılmıştır [1.1.3, 6].
        $middleware->redirectUsersTo(function () {
            /** @var \App\Models\User|null $user */
            $user = Auth::user();
            if ($user && $user->current_role === 'admin') {
                return '/adminsystem/dashboard';
            }
            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
