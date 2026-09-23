<?php

namespace App\Providers;

use App\Livewire\PausePollWhileInteracting;
use App\Payments\GatewayManager;
use App\Support\RuntimeMailConfig;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\ComponentHookRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Kullanıcı ekranla uğraşırken süreli yenileme (wire:poll) ekranı yeniden çizmez.
        // Livewire kancaları kendi sağlayıcısı önyüklenmeden önce kayıtlı olmalıdır (register aşaması).
        ComponentHookRegistry::register(PausePollWhileInteracting::class);
        // Ödeme kuruluşu yöneticisi tekildir: etkin geçit ve test ortamında takılan sahte geçit süreç boyunca aynı kalır.
        $this->app->singleton(GatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Uygulama ilk ayağa kalktığında çalışacak çekirdek servislerin
     * ve yetkilendirme kapılarının tanımlandığı alan.
     */
    public function boot(): void
    {
        // Panelden girilen SMTP ayarları .env yerine geçer (bkz. RuntimeMailConfig).
        RuntimeMailConfig::apply();

        // Spatie ve Laravel Gate Çekirdek Entegrasyonu:
        // "super_admin" rolüne sahip yöneticiler, veritabanında izinleri tek tek tanımlı olmasa dahi
        // sistemdeki tüm yetki (can/authorize) kontrollerinden otomatik olarak geçerler.
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
