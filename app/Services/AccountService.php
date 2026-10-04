<?php

namespace App\Services;

use App\Mail\SystemNoticeMail;
use App\Models\KycDocument;
use App\Models\Load;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
            // Kişisel veri taşıyan yan kayıtlar kalıcı silinir / anonimleştirilir (KVKK: unutulma hakkı).
            // Fatura, ödeme ve ilan geçmişi yasal saklama süresi gereği kalır; kullanıcı satırı anonim olduğu için kişiye bağlanamaz.
            $user->bankAccounts()->withTrashed()->forceDelete();
            $user->savedAddresses()->withTrashed()->forceDelete();
            $user->supportTickets()->update(['name' => 'Silinmiş Kullanıcı', 'email' => null, 'phone' => null]);

            KycDocument::withTrashed()->where('user_id', $user->id)->get()->each(function (KycDocument $doc): void {
                try {
                    Storage::disk($doc->storage_disk)->delete($doc->storage_path);
                } catch (\Throwable) {
                    // dosya zaten yoksa sorun değil
                }
                $doc->forceDelete();
            });

            if ($driver = $user->driverProfile) {
                foreach (['avatar_path', 'driver_license_path', 'src_document_path', 'psychotechnic_path', 'k_document_path', 'liability_insurance_path', 'selfie_with_id_path'] as $col) {
                    if ($driver->{$col}) {
                        try {
                            Storage::disk('kyc_private')->delete($driver->{$col});
                        } catch (\Throwable) {
                        }
                    }
                }
                $driver->forceFill(array_fill_keys(['avatar_path', 'driver_license_path', 'src_document_path', 'psychotechnic_path', 'k_document_path', 'liability_insurance_path', 'selfie_with_id_path', 'ocr_data', 'preferences'], null))->save();
                $driver->locations()->delete();
                $driver->vehicles()->withTrashed()->get()->each(function ($vehicle): void {
                    foreach (['ruhsat_path', 'vehicle_photo_path'] as $col) {
                        if ($vehicle->{$col}) {
                            try {
                                Storage::disk('private')->delete($vehicle->{$col});
                            } catch (\Throwable) {
                            }
                        }
                    }
                    $vehicle->forceFill(['plate' => 'SILINDI-'.$vehicle->id, 'ruhsat_path' => null, 'vehicle_photo_path' => null, 'is_active' => false])->save();
                    $vehicle->delete();
                });
            }
            if ($owner = $user->cargoOwnerProfile) {
                $owner->forceFill(['tc_no' => null, 'tax_no' => null, 'birth_year' => null, 'company_title' => null, 'tax_office' => null])->save();
            }

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
                Log::warning('Hesap kapatma e-postası gönderilemedi.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** OTP doğrulaması yapılmamış taslak hesapları temizler (saatlik görev). Kısa süre: başkasının numarası/e-postası taslakta rehin kalmasın. */
    public function purgeUnverifiedDrafts(int $olderThanHours = 2): int
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

    /** KVKK m.11 bilgi edinme hakkı: kullanıcının kendisiyle ilgili tutulan verilerin okunabilir dökümü (JSON). */
    public function export(User $user): array
    {
        $user->loadMissing(['driverProfile.vehicles', 'cargoOwnerProfile', 'consents', 'bankAccounts', 'savedAddresses', 'kycDocuments']);
        $driver = $user->driverProfile;
        $owner = $user->cargoOwnerProfile;

        return [
            'olusturma' => now()->toDateTimeString(),
            'hesap' => [
                'ad' => $user->first_name, 'soyad' => $user->last_name, 'e_posta' => $user->email, 'telefon' => $user->phone,
                'kayit' => $user->created_at?->toDateTimeString(), 'son_giris' => $user->last_login_at?->toDateTimeString(),
                'e_posta_dogrulandi' => $user->email_verified_at?->toDateTimeString(), 'roller' => $user->getRoleNames()->all(),
            ],
            'sofor_profili' => $driver ? [
                'belge_durumu' => $driver->kyc_status, 'premium_bitis' => $driver->premium_until?->toDateTimeString(), 'tercihler' => $driver->preferences,
                'araclar' => $driver->vehicles->map(fn ($v) => ['plaka' => $v->plate, 'arac_tipi' => $v->vehicle_type, 'kasa' => $v->body_type, 'aktif' => $v->is_active])->all(),
            ] : null,
            'yuk_sahibi_profili' => $owner ? ['tur' => $owner->type, 'unvan' => $owner->company_title, 'vergi_dairesi' => $owner->tax_office, 'belge_durumu' => $owner->kyc_status] : null,
            'belgeler' => $user->kycDocuments->map(fn ($d) => ['tur' => $d->document_type, 'durum' => $d->status, 'yuklenme' => $d->created_at?->toDateTimeString()])->all(),
            'banka_hesaplari' => $user->bankAccounts->map(fn ($b) => ['hesap_sahibi' => $b->account_holder, 'iban_son4' => $b->iban_last4, 'dogrulandi' => $b->is_verified])->all(),
            'kayitli_adresler' => $user->savedAddresses->map(fn ($a) => $a->only(['title', 'city', 'district', 'address_detail', 'type']))->all(),
            'onaylar' => $user->consents->map(fn ($c) => ['metin' => $c->consent_type, 'surum' => $c->document_version, 'tarih' => $c->recorded_at?->toDateTimeString(), 'geri_alma' => $c->revoked_at?->toDateTimeString()])->all(),
            'ilan_sayisi' => $owner ? Load::query()->where('cargo_owner_profile_id', $owner->id)->count() : 0,
            'sevkiyat_sayisi' => $driver ? Load::query()->where('driver_profile_id', $driver->id)->count() : 0,
        ];
    }
}
