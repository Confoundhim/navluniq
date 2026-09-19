<?php

namespace App\Services;

use App\Mail\SystemNoticeMail;
use App\Models\Load;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            throw new RuntimeException('Tamamlanmamış bir navlun ödemeniz varken hesabınız kapatılamaz. Ödeme tamamlandıktan sonra tekrar deneyin.');
        }

        $farewellEmail = $user->canReceiveMail() ? $user->email : null;
        $farewellName = $user->first_name;

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

        if ($farewellEmail) {
            try {
                Mail::to($farewellEmail, $farewellName)->send(new SystemNoticeMail('Hesabınız kapatıldı',
                    ['NavlunIQ hesabınız talebiniz üzerine '.now()->format('d.m.Y H:i').' tarihinde kapatıldı; kişisel verileriniz anonimleştirildi.',
                        'Yasal saklama süresi gereken fatura ve işlem kayıtları KVKK ve vergi mevzuatı uyarınca süresi boyunca saklanır.',
                        'Bu işlemi siz yapmadıysanız lütfen hemen destek ekibimize ulaşın.'],
                    null, null, $farewellName));
            } catch (\Throwable $e) {
                Log::warning('Hesap kapatma e-postası gönderilemedi.', ['email' => $farewellEmail, 'error' => $e->getMessage()]);
            }
        }
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
