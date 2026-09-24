<?php

use App\Http\Controllers\Admin\BackupDownloadController;
use App\Http\Controllers\Admin\PanelSwitchController;
use App\Http\Controllers\Admin\UpdateStatusController;
use App\Http\Controllers\Driver\LocationController;
use App\Http\Controllers\Files\ProtectedFileController;
use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Payment\PaytrController;
use App\Http\Middleware\EnsureCargoOwner;
use App\Http\Middleware\EnsureDriver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// =========================================================
// 1. ÖN YÜZ VE ZİYARETÇİ ROTALARI (FAZ 3)
// =========================================================

// Anasayfa
Route::get('/', function () {
    return view('frontend.home-page');
})->name('home');

// Özel Tanıtım ve Bilgi Sayfaları
Route::get('/hakkimizda', function () {
    return view('frontend.about-page');
})->name('about');

Route::get('/yuk-sahipleri-icin', function () {
    return view('frontend.cargo-owners-page');
})->name('for-cargo-owners');

Route::get('/soforler-icin', function () {
    return view('frontend.drivers-page');
})->name('for-drivers');

Route::get('/nasil-calisir', function () {
    return view('frontend.how-it-works-page');
})->name('how-it-works');

Route::get('/abonelik', function () {
    return view('frontend.subscription-page');
})->name('subscription');

Route::get('/hizmetlerimiz', function () {
    return view('frontend.services-page');
})->name('services');

// 12 Kategorili İletişim Sayfası
Route::get('/iletisim', function () {
    return view('frontend.contact-page');
})->name('contact');

// Yasal Sözleşmeler Sayfası
Route::get('/sozlesmeler/{slug?}', function (string $slug = 'kvkk') {
    $allowed = ['kvkk', 'kullanici-sozlesmesi', 'gizlilik-politikasi', 'mesafeli-satis', 'iade-politikasi'];
    abort_unless(in_array($slug, $allowed, true), 404);

    return view('frontend.contract-page', ['activeContract' => $slug]);
})->name('contracts');

// Bildirim iletici (MacroDroid) kurulum sayfası: gizli kodu bilen telefon sahibi kendi kurar.
Route::middleware('throttle:30,1')->group(function () {});

// Giriş, kayıt ve şifre sıfırlama (yalnız oturumu olmayan ziyaretçiler)
Route::middleware('guest')->group(function () {
    Route::get('/giris', fn () => view('frontend.login-page'))->name('login');
    Route::get('/kayit/yuk-sahibi', fn () => view('frontend.register-cargo-owner-page'))->name('register.cargo-owner');
    Route::get('/kayit/sofor', fn () => view('frontend.register-driver-page'))->name('register.driver');
    Route::get('/sifremi-unuttum', fn () => view('frontend.forgot-password-page'))->name('password.request');
    Route::get('/sifre-sifirla/{token}', fn (string $token) => view('frontend.reset-password-page', ['token' => $token]))->name('password.reset');
});

// Akıllı Panel Yönlendiricisi
Route::middleware('auth')->get('/panel', function () {
    $user = Auth::user();

    if ($user->current_role === 'cargo_owner') {
        return redirect()->route('cargo-owner.dashboard');
    } elseif ($user->current_role === 'driver') {
        return redirect()->route('driver.dashboard');
    } elseif ($user->current_role === 'admin') {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('cargo-owner.dashboard');
})->name('panel');

// Ödeme kuruluşu sunucu bildirimi (sağlayıcıdan bağımsız) ve eski PayTR adresleri
Route::post('/odeme/bildirim/{provider}', [PaymentWebhookController::class, 'handle'])->whereAlpha('provider')->name('payment.webhook');
Route::post('/odeme/paytr/bildirim', [PaytrController::class, 'callback'])->name('payment.paytr.callback');
Route::middleware('auth')->group(function () {
    // Kullanıcı ödeme ekranından döndüğünde: nihai durum yalnız sunucu bildirimiyle belirlenir, sayfa durumu sorgular.
    Volt::route('/odeme/sonuc/{order}/{outcome}', 'payment.result')->whereIn('outcome', ['basarili', 'basarisiz'])->name('payment.result');
    Route::get('/odeme/paytr/basarili/{load}', [PaytrController::class, 'success'])->name('payment.paytr.success');
    Route::get('/odeme/paytr/basarisiz/{load}', [PaytrController::class, 'fail'])->name('payment.paytr.fail');

    // Özel diskteki belgeler (yalnız ilgili taraflar ve yetkili personel)
    Route::get('/dosya/kyc/{document}', [ProtectedFileController::class, 'kyc'])->name('files.kyc');
    Route::get('/dosya/kanit/{evidence}', [ProtectedFileController::class, 'evidence'])->name('files.evidence');
    Route::get('/dosya/uyusmazlik/{dispute}/{side}', [ProtectedFileController::class, 'disputePhoto'])->whereIn('side', ['claim', 'defense'])->name('files.dispute');
    Route::get('/dosya/e-irsaliye/{load}', [ProtectedFileController::class, 'eIrsaliye'])->name('files.e-irsaliye');
});

// Oturum Kapatma
Route::post('/cikis', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

// =========================================================
// 2. ADMİN YÖNETİM PANELİ ROTALARI (FAZ 2)
// =========================================================
Route::prefix('adminsystem')->group(function () {
    Route::get('/', function () {
        if (Auth::check() && Auth::user()->current_role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login-page');
    })->name('admin.login');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('/dashboard', function () {
            return view('admin.dashboard');
        })->name('admin.dashboard');
        Route::get('/users', function () {
            return view('admin.users-page');
        })->name('admin.users');
        Route::get('/kyc', function () {
            return view('admin.kyc-page');
        })->name('admin.kyc');
        Volt::route('/notifications', 'admin.notifications.index')->name('admin.notifications');
        Route::get('/operations', function () {
            return view('admin.operations-page');
        })->name('admin.operations');
        Route::get('/scrapers', function () {
            return view('admin.scrapers-page');
        })->name('admin.scrapers');
        Route::get('/finance', function () {
            return view('admin.finance-page');
        })->name('admin.finance');
        Route::get('/disputes', function () {
            return view('admin.disputes-page');
        })->name('admin.disputes');
        Route::get('/crm', function () {
            return view('admin.crm-page');
        })->name('admin.crm');
        Route::get('/cms', function () {
            return view('admin.cms-page');
        })->name('admin.cms');
        Route::get('/languages', function () {
            return view('admin.languages-page');
        })->name('admin.languages');
        Route::get('/settings', function () {
            return view('admin.settings-page');
        })->name('admin.settings');
        Route::get('/staff', function () {
            return view('admin.staff-page');
        })->name('admin.staff');
        Route::get('/rollback', function () {
            return view('admin.rollback-page');
        })->name('admin.rollback');
        Route::get('/health', function () {
            return view('admin.health-page');
        })->name('admin.health');
        Route::get('/health/update-status', UpdateStatusController::class)->name('admin.health.update-status');
        Route::get('/firewall', function () {
            return view('admin.firewall-page');
        })->name('admin.firewall');
        Route::get('/backups', function () {
            return view('admin.backups-page');
        })->name('admin.backups');
        Route::get('/backups/{backup}/download', BackupDownloadController::class)->name('admin.backups.download');
        Route::get('/panel-gecis/{panel}', PanelSwitchController::class)->where('panel', 'driver|cargo_owner')->name('admin.panel-switch');

        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('admin.logout');
    });
});

// =========================================================
// 3. YÜK SAHİBİ YÖNETİM PANELİ ROTALARI (FAZ 4)
// =========================================================
Route::middleware(['auth', EnsureCargoOwner::class])->prefix('panel/yuk-sahibi')->name('cargo-owner.')->group(function () {
    // Kök Rota: /panel/yuk-sahibi yazıldığında doğrudan dashboard'a yönlendirir
    Route::get('/', function () {
        return redirect()->route('cargo-owner.dashboard');
    });

    Volt::route('/dashboard', 'cargo-owner.dashboard')->name('dashboard');
    Volt::route('/ilan-olustur', 'cargo-owner.loads.create')->name('loads.create');
    Volt::route('/ilanlarim', 'cargo-owner.loads.index')->name('loads.index');
    Volt::route('/ilanlar/{loadId}/teklifler', 'cargo-owner.loads.offers')->name('loads.offers')->whereNumber('loadId');
    Volt::route('/odeme/{loadId}', 'cargo-owner.finance.payment')->name('finance.payment')->whereNumber('loadId');
    Volt::route('/finans-ve-faturalar', 'cargo-owner.finance.index')->name('finance.index');
    Volt::route('/sevkiyatlarim', 'cargo-owner.shipments.index')->name('shipments.index');
    Volt::route('/sevkiyat/{loadId}', 'cargo-owner.shipments.show')->name('shipments.show')->whereNumber('loadId');
    Volt::route('/uyusmazliklar', 'cargo-owner.disputes.index')->name('disputes.index');
    Volt::route('/destek', 'cargo-owner.support.index')->name('support.index');
    Volt::route('/adres-defteri', 'cargo-owner.address-book.index')->name('address-book.index');
    Volt::route('/profil', 'cargo-owner.profile.index')->name('profile.index');
    Volt::route('/bildirimler', 'cargo-owner.notifications.index')->name('notifications.index');
});

// =========================================================
// 4. ŞOFÖR YÖNETİM PANELİ ROTALARI (FAZ 5)
// =========================================================
Route::middleware(['auth', EnsureDriver::class])->prefix('panel/sofor')->name('driver.')->group(function () {
    // Kök Rota: /panel/sofor yazıldığında doğrudan dashboard'a yönlendirir
    Route::get('/', function () {
        return redirect()->route('driver.dashboard');
    });

    Volt::route('/dashboard', 'driver.dashboard')->name('dashboard');
    Route::post('/konum', [LocationController::class, 'store'])->middleware('throttle:60,1')->name('location.store');
    Volt::route('/ilan-havuzu', 'driver.loads.index')->name('loads.index');
    // İşlerim: NavlunIQ işleri ve gruptan alınan işler tek listede. Eski adresler (Sevkiyatlarım, Seferlerim) buraya yönlenir.
    Volt::route('/islerim', 'driver.jobs.index')->name('jobs.index');
    Volt::route('/is/{loadId}', 'driver.jobs.show')->name('jobs.show')->whereNumber('loadId');
    Route::get('/sevkiyatlarim', fn () => redirect()->route('driver.jobs.index', array_filter(['sekme' => request()->query('tab') === 'past' ? 'past' : null]), 301));
    Route::get('/seferlerim', fn () => redirect()->route('driver.jobs.index', array_filter(['is' => request()->query('sefer'), 'sekme' => request()->query('sekme')]), 301));
    Route::get('/sevkiyat/{loadId}', fn (int $loadId) => redirect()->route('driver.jobs.show', $loadId, 301))->whereNumber('loadId');
    Volt::route('/premium', 'driver.premium.index')->name('premium.index');
    Volt::route('/premium/odeme', 'driver.premium.checkout')->name('premium.checkout');
    Volt::route('/odemelerim', 'driver.wallet.index')->name('wallet.index');
    Route::get('/cuzdan', fn () => redirect()->route('driver.wallet.index', status: 301));
    Volt::route('/uyusmazliklar', 'driver.disputes.index')->name('disputes.index');
    Volt::route('/araclarim', 'driver.vehicles.index')->name('vehicles.index');
    Volt::route('/profil', 'driver.profile.index')->name('profile.index');
    Volt::route('/bildirimler', 'driver.notifications.index')->name('notifications.index');
});
