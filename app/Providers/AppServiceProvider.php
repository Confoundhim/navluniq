<?php

namespace App\Providers;

use App\Livewire\PausePollWhileInteracting;
use App\Payments\GatewayManager;
use App\Support\RuntimeMailConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Telefon alım uçlarının istek sınırları. Sayısal "throttle:N,1" sınırı aynı IP için TÜM rotalarda tek sayaç tutar:
        // çok grubu olan bir telefon dakikada 30'dan fazla WhatsApp mesajı yollayınca sınama ucu (30/dk) ve Facebook dökümleri
        // 429 alıyordu (2026-10-03, Engin Abi). Her uç kendi sayacını tutar; mesaj ucu bir telefonun en yoğun dakikasına yeter.
        RateLimiter::for('intake', fn (Request $r) => Limit::perMinute(600)->by('intake|'.$r->ip()));
        RateLimiter::for('intake-ping', fn (Request $r) => Limit::perMinute(30)->by('ping|'.$r->ip()));
        RateLimiter::for('intake-version', fn (Request $r) => Limit::perMinute(60)->by('version|'.$r->ip()));
        RateLimiter::for('scraper-webhook', fn (Request $r) => Limit::perMinute(60)->by('scraper|'.$r->ip()));
        RateLimiter::for('marketing-unsubscribe', fn (Request $r) => Limit::perMinute(20)->by('unsub|'.$r->ip()));
        RateLimiter::for('driver-location', fn (Request $r) => Limit::perMinute(60)->by('loc|'.($r->user()?->getAuthIdentifier() ?: $r->ip()))); // şoför konumu: kullanıcı başına, paylaşımlı sayaç değil (A22)
    }
}
