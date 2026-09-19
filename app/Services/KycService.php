<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class KycService
{
    public const DISK = 'kyc_private';

    public function __construct(private readonly NotificationService $notifications) {}

    /** Kullanıcının rolüne göre yüklenebilir belge türleri. */
    public function allowedTypes(User $user, string $role): array
    {
        if ($role === 'driver') {
            return KycDocument::DRIVER_TYPES;
        }

        $types = KycDocument::CARGO_OWNER_TYPES;
        if ($user->cargoOwnerProfile?->type !== 'corporate') {
            unset($types['tax_plate'], $types['signature_circular']);
        }

        return $types;
    }

    public function requiredTypes(User $user, string $role): array
    {
        if ($role === 'driver') {
            return KycDocument::DRIVER_REQUIRED;
        }

        return $user->cargoOwnerProfile?->type === 'corporate' ? KycDocument::CARGO_OWNER_CORPORATE_REQUIRED : KycDocument::CARGO_OWNER_REQUIRED;
    }

    public function upload(User $user, string $role, string $type, UploadedFile $file, ?string $expiresAt = null): KycDocument
    {
        if (! array_key_exists($type, $this->allowedTypes($user, $role))) {
            throw new RuntimeException('Geçersiz belge türü.');
        }

        return DB::transaction(function () use ($user, $role, $type, $file, $expiresAt): KycDocument {
            KycDocument::query()->where('user_id', $user->id)->where('document_type', $type)
                ->whereIn('status', ['pending', 'rejected'])->get()->each(function (KycDocument $old): void {
                    Storage::disk($old->storage_disk)->delete($old->storage_path);
                    $old->delete();
                });

            $path = $file->storeAs(
                'users/'.$user->id,
                $type.'-'.Str::lower(Str::random(12)).'.'.strtolower($file->getClientOriginalExtension()),
                self::DISK
            );

            $document = KycDocument::create([
                'user_id' => $user->id,
                'document_type' => $type,
                'storage_disk' => self::DISK,
                'storage_path' => $path,
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => 'pending',
                'expires_at' => $expiresAt ?: null,
            ]);

            $this->refreshProfileStatus($user, $role);

            return $document;
        });

        // Gerekli belgelerin tamamı yeni yüklendiyse: kullanıcıya "alındı", inceleme ekibine "bekliyor".
        $profile = ($role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile)?->fresh();
        if ($profile && $profile->kyc_status === 'pending' && $profile->kyc_submitted_at?->gt(now()->subMinute())) {
            $this->notifications->notify($user, 'Belgeleriniz alındı',
                ['Gerekli belgelerinizin tamamı yüklendi ve inceleme sırasına alındı. Ekibimiz genellikle 24 saat içinde sonucu bildirir.', 'Eksik ya da okunaksız bir belge olursa gerekçesiyle birlikte haber vereceğiz.'],
                route($role === 'driver' ? 'driver.profile.index' : 'cargo-owner.profile.index'), 'Belgelerimi görüntüle', 'kyc');
            $this->notifications->notifyAdmins('verify kyc', 'Yeni belge incelemesi bekliyor',
                [$user->full_name.' ('.($role === 'driver' ? 'şoför' : 'yük sahibi').') gerekli belgelerinin tamamını yükledi.', 'KYC Evrak Merkezi\'nden belgeleri inceleyip onaylayın ya da gerekçesiyle reddedin.'],
                route('admin.kyc'), 'KYC Evrak Merkezi', 'admin');
        }
    }

    /** Gerekli belgelerin tamamı yüklendiyse profili "pending" (inceleme) durumuna alır. */
    public function refreshProfileStatus(User $user, string $role): void
    {
        $profile = $role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile;
        if (! $profile || $profile->kyc_status === 'approved') {
            return;
        }

        $docs = KycDocument::query()->where('user_id', $user->id)->get()->keyBy('document_type');
        $required = $this->requiredTypes($user, $role);
        $allPresent = collect($required)->every(fn ($type) => isset($docs[$type]) && $docs[$type]->status !== 'rejected');

        if ($allPresent && $profile->kyc_status !== 'pending') {
            $profile->update(['kyc_status' => 'pending', 'kyc_submitted_at' => now(), 'kyc_notes' => null]);
        } elseif (! $allPresent && $profile->kyc_status === 'pending') {
            $profile->update(['kyc_status' => 'unsubmitted']);
        }
    }

    public function missingTypes(User $user, string $role): array
    {
        $present = KycDocument::query()->where('user_id', $user->id)->where('status', '!=', 'rejected')->pluck('document_type')->all();

        return array_values(array_diff($this->requiredTypes($user, $role), $present));
    }

    /** Yönetici belge kararı; tüm zorunlu belgeler onaylanınca profil onaylanır. */
    public function review(KycDocument $document, User $reviewer, string $decision, ?string $notes = null): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Geçersiz karar.');
        }

        DB::transaction(function () use ($document, $reviewer, $decision, $notes): void {
            $document->update([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes ? mb_substr($notes, 0, 1000) : null,
            ]);

            $user = $document->user;
            $role = array_key_exists($document->document_type, KycDocument::DRIVER_TYPES) ? 'driver' : 'cargo_owner';
            $profile = $role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile;
            if (! $profile) {
                return;
            }

            if ($decision === 'rejected') {
                $profile->update(['kyc_status' => 'rejected', 'kyc_notes' => $document->label().': '.($notes ?: 'Belge reddedildi.')]);
                ActivityLog::record('kyc.rejected', "KYC belgesi reddedildi: {$document->label()} (kullanıcı #{$user->id})", $reviewer->id, $document);

                return;
            }

            $docs = KycDocument::query()->where('user_id', $user->id)->get()->keyBy('document_type');
            $allApproved = collect($this->requiredTypes($user, $role))->every(fn ($type) => isset($docs[$type]) && $docs[$type]->status === 'approved');

            if ($allApproved) {
                $profile->update(['kyc_status' => 'approved', 'kyc_verified_at' => now(), 'kyc_verified_by' => $reviewer->id, 'kyc_notes' => null]);
                ActivityLog::record('kyc.approved', "KYC onaylandı (kullanıcı #{$user->id}, rol {$role})", $reviewer->id, $profile);
            }
        });

        $user = $document->user;
        $profile = array_key_exists($document->document_type, KycDocument::DRIVER_TYPES) ? $user->driverProfile : $user->cargoOwnerProfile;

        if ($decision === 'rejected') {
            $profileRoute = array_key_exists($document->document_type, KycDocument::DRIVER_TYPES) ? 'driver.profile.index' : 'cargo-owner.profile.index';
            $this->notifications->notify($user, 'Belgeniz reddedildi',
                [$document->label().' belgeniz reddedildi.', 'Gerekçe: '.($notes ?: 'Belirtilmedi'), 'Lütfen belgeyi yeniden yükleyin; yeni yükleme inceleme sırasına alınır.'],
                route($profileRoute), 'Belgeyi yeniden yükle', 'kyc');
        } elseif ($profile?->kyc_status === 'approved') {
            $isDriver = array_key_exists($document->document_type, KycDocument::DRIVER_TYPES);
            $this->notifications->notify($user, 'Belge doğrulamanız tamamlandı',
                ['Tüm belgeleriniz onaylandı. '.($isDriver ? 'Artık ilan havuzundaki yüklere teklif verebilirsiniz.' : 'Artık teklifleri kabul edip navlun ödemesi yapabilirsiniz.')],
                route($isDriver ? 'driver.loads.index' : 'cargo-owner.loads.index'), $isDriver ? 'İlan havuzuna git' : 'İlanlarıma git', 'kyc');
        }
    }
}
