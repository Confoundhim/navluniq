<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Ödeme kuruluşu yöneticisi tekildir: etkin geçit ve test ortamında takılan sahte geçit süreç boyunca aynı kalır.
        $this->app->singleton(\App\Payments\GatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Uygulama ilk ayağa kalktığında çalışacak çekirdek servislerin
     * ve yetkilendirme kapılarının tanımlandığı alan.
     */
    public function boot(): void
    {
        // Spatie ve Laravel Gate Çekirdek Entegrasyonu:
        // "super_admin" rolüne sahip yöneticiler, veritabanında izinleri tek tek tanımlı olmasa dahi
        // sistemdeki tüm yetki (can/authorize) kontrollerinden otomatik olarak geçerler.
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
