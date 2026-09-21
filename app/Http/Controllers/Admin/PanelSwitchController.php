<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Yönetici panelinden şoför ya da yük sahibi paneline geçiş ("tam yetkili görünüm"): yönetici hesabına gerekirse
 * onaylı bir şoför profili (premium, örnek araç) ve yük sahibi profili açılır; yönetici rolü değişmez, panel
 * ara katmanları yönetim paneli kullanıcısını profil varsa içeri alır.
 */
class PanelSwitchController extends Controller
{
    public function __invoke(string $panel): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless($user?->isAdminPanelUser(), 403);
        abort_unless(in_array($panel, ['driver', 'cargo_owner'], true), 404);

        if ($panel === 'driver') {
            $profile = DriverProfile::firstOrCreate(['user_id' => $user->id], ['kyc_status' => 'approved']);
            $changes = [];
            if ($profile->kyc_status !== 'approved') {
                $changes['kyc_status'] = 'approved';
            }
            if (! $profile->premium_until || $profile->premium_until->isPast()) {
                $changes['premium_until'] = now()->addYears(10);
            }
            if ($changes !== []) {
                $profile->forceFill($changes)->save();
            }
            if (! $profile->vehicles()->exists()) {
                DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => 'YONETIM', 'brand' => 'NavlunIQ', 'model' => 'Yönetici görünümü', 'vehicle_type' => 'tir', 'is_active' => true]);
            }
            ActivityLog::record('admin.panel_switch', 'Yönetici şoför paneline geçti', $user->id);

            return redirect()->route('driver.dashboard');
        }

        CargoOwnerProfile::firstOrCreate(['user_id' => $user->id], ['type' => 'individual']);
        ActivityLog::record('admin.panel_switch', 'Yönetici yük sahibi paneline geçti', $user->id);

        return redirect()->route('cargo-owner.dashboard');
    }
}
