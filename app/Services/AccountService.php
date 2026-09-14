<?php

namespace App\Services;

use App\Models\Load;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AccountService
{
    /** Hesabı kapatır: açık iş yoksa kişisel veriler anonimleştirilir ve kayıt yumuşak silinir. */
    public function deleteAccount(User $user, ?string $reason = null): void
    {
        $openStatuses = [Load::STATUS_ACTIVE, Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED, Load::STATUS_DISPUTED];

        $ownerOpen = $user->cargoOwnerProfile
            ? Load::query()->where('cargo_owner_profile_id', $user->cargoOwnerProfile->id)->whereIn('status', $openStatuses)->exists()
            : false;
        $driverOpen = $user->driverProfile
            ? Load::query()->where('driver_profile_id', $user->driverProfile->id)->whereIn('status', $openStatuses)->exists()
            : false;
        $pendingPayout = Payout::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->exists();

        if ($ownerOpen || $driverOpen) {
            throw new RuntimeException('Devam eden ilan veya sevkiyatınız varken hesabınızı kapatamazsınız. Önce bunları tamamlayın ya da iptal edin.');
        }
        if ($pendingPayout) {
            throw new RuntimeException('Ödenmemiş hakedişiniz varken hesabınız kapatılamaz. Ödeme tamamlandıktan sonra tekrar deneyin.');
        }

        DB::transaction(function () use ($user, $reason): void {
            $user->consents()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->bankAccounts()->delete();
            $user->savedAddresses()->delete();
            $user->driverProfile?->vehicles()->update(['is_active' => false]);

            $user->forceFill([
                'first_name' => 'Silinmiş',
                'last_name' => 'Kullanıcı',
                'email' => 'silinmis+'.$user->id.'@hesap.kapatildi',
                'phone' => 'silinmis-'.$user->id,
                'password' => Str::random(40),
                'remember_token' => null,
                'otp_code' => null,
                'otp_expires_at' => null,
                'is_active' => false,
                'ban_reason' => $reason ? 'Kullanıcı talebiyle kapatıldı: '.mb_substr($reason, 0, 500) : 'Kullanıcı talebiyle kapatıldı',
            ])->save();

            $user->delete();
        });
    }

    /** OTP doğrulaması yapılmamış taslak hesapları temizler (zamanlanmış görev). */
    public function purgeUnverifiedDrafts(int $olderThanHours = 24): int
    {
        $drafts = User::query()->whereNull('email_verified_at')->where('is_active', false)
            ->where('created_at', '<', now()->subHours($olderThanHours))->get();

        foreach ($drafts as $draft) {
            DB::transaction(function () use ($draft): void {
                $draft->driverProfile?->vehicles()->forceDelete();
                $draft->driverProfile?->forceDelete();
                $draft->cargoOwnerProfile?->forceDelete();
                $draft->consents()->delete();
                $draft->forceDelete();
            });
        }

        return $drafts->count();
    }
}
