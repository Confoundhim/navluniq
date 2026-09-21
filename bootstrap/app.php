<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\FirewallMiddleware;
use App\Models\User;
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
        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);

        $middleware->append(FirewallMiddleware::class);

        // Panelden güncelleme sırasında site bakım modundadır; durum adresi muaf tutulur ki Sistem Sağlığı
        // sayfası çıktıyı izleyip bitince kendini yenileyebilsin.
        $middleware->preventRequestsDuringMaintenance(except: ['adminsystem/health/update-status']);

        // Ödeme sağlayıcısı sunucudan sunucuya bildirir; CSRF yerine imza doğrulaması yapılır.
        $middleware->validateCsrfTokens(except: ['odeme/paytr/bildirim', 'odeme/bildirim/*']);

        // Uygulama bir yük dengeleyici veya CDN arkasına alınırsa gerçek istemci IP'si için
        // burada trustProxies(at: [...]) tanımlanmalıdır; aksi halde firewall ve hız sınırlayıcı proxy IP'sini görür.

        $middleware->redirectGuestsTo('/giris');

        // Oturumu olan kullanıcı giriş/kayıt sayfalarına gelirse rolüne uygun panele gönderilir.
        $middleware->redirectUsersTo(function () {
            /** @var User|null $user */
            $user = Auth::user();

            return $user && $user->current_role === 'admin' ? '/adminsystem/dashboard' : '/panel';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
