<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\FirewallMiddleware;
use App\Http\Middleware\LogSlowRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
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
        // Şifre değişince diğer cihazlardaki oturumlar düşer (oturumda şifre özeti tutulur).
        // SecurityHeaders: içerik güvenliği politikası (önce rapor kipinde; panel ayarı csp_enforce ile zorunlu).
        $middleware->web(append: [AuthenticateSession::class, SecurityHeaders::class]);
        // Yalnız APP_URL alan adı (ve www gibi alt alanları) kabul edilir: sahte Host başlığıyla üretilen
        // şifre sıfırlama bağlantısı saldırganın alanına gidemez. Yerel/test ortamında Laravel bu denetimi uygulamaz.
        $middleware->trustHosts(at: fn () => array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]), subdomains: true);
        // Yavaş istekler (varsayılan 3 sn ve üstü) laravel.log'a yol, süre ve sorgu bilgisiyle yazılır.
        $middleware->prepend(LogSlowRequests::class);

        // Panelden güncelleme sırasında site bakım modundadır; durum adresi muaf tutulur ki Sistem Sağlığı
        // sayfası çıktıyı izleyip bitince kendini yenileyebilsin. Ödeme kuruluşunun sunucudan sunucuya bildirimi de
        // muaftır: bakım sayfasına çarpan geri çağrı "para çekildi ama ilan ödenmedi" bırakıyordu (I3).
        $middleware->preventRequestsDuringMaintenance(except: ['adminsystem/health/update-status', 'odeme/bildirim/*', 'odeme/paytr/bildirim']);

        // Ödeme sağlayıcısı sunucudan sunucuya bildirir; CSRF yerine imza doğrulaması yapılır. CSP ihlal raporunu tarayıcı
        // kendisi gönderir (jeton yok); uç yalnız günlüğe yazar ve adlı sınırlayıcıyla korunur.
        $middleware->validateCsrfTokens(except: ['odeme/paytr/bildirim', 'odeme/bildirim/*', 'csp-rapor']);

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
