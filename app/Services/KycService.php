<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Support\FreightPayment;
use App\Support\UploadName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class KycService
{
    public const DISK = 'kyc_private';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * T.C. kimlik numarası biçim ve sağlama denetimi (NVİ algoritması: 11 hane, ilk hane 0 değil, 10. ve 11. hane sağlama).
     * NVİ servisine doğum yılı gerektiği için şoför tarafında yalnız bu yerel denetim yapılır; uydurma numara kabul edilmez.
     */
    public static function isValidTcNo(string $tc): bool
    {
        if (! preg_match('/^[1-9]\d{10}$/', $tc)) {
            return false;
        }
        $d = array_map('intval', str_split($tc));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        if ((($odd * 7) - $even) % 10 !== $d[9]) {
            return false;
        }

        return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    /** Kullanıcının rolüne göre yüklenebilir belge türleri. */
    public function allowedTypes(User $user, string $role): array
    {
        if ($role === 'driver') {
            return KycDocument::DRIVER_TYPES;
        }

        // Yük sahibinden belge fotoğrafı istenmez (karar 3): bireysel hesap hiç belge yüklemez, kurumsal hesap isterse vergi
        // levhası / imza sirküleri yükler (rozet teyidini hızlandırır).
        if ($user->cargoOwnerProfile?->type !== 'corporate') {
            return [];
        }
        $types = KycDocument::CARGO_OWNER_TYPES;
        unset($types['id_card']);

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

        $document = DB::transaction(function () use ($user, $role, $type, $file, $expiresAt): KycDocument {
            KycDocument::query()->where('user_id', $user->id)->where('document_type', $type)
                ->whereIn('status', ['pending', 'rejected'])->get()->each(function (KycDocument $old): void {
                    Storage::disk($old->storage_disk)->delete($old->storage_path);
                    $old->delete();
                });

            $path = $file->storeAs(
                'users/'.$user->id,
                $type.'-'.Str::lower(Str::random(12)).'.'.UploadName::extension($file),
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

        return $document;
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

    /**
     * Yönetici toplu onayı (Kullanıcılar ekranı): yüklü belgelerin tamamı onaylanır, eksik belge olsa da profil
     * onaylanır (test hesapları ve elle doğrulanmış kullanıcılar için). Kullanıcıya bildirim gider.
     */
    public function approveProfile(User $user, string $role, User $reviewer, ?string $note = null): void
    {
        $profile = $role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile;
        if (! $profile) {
            throw new RuntimeException($role === 'driver' ? 'Kullanıcının şoför profili yok.' : 'Kullanıcının yük sahibi profili yok.');
        }
        $types = array_keys($role === 'driver' ? KycDocument::DRIVER_TYPES : KycDocument::CARGO_OWNER_TYPES);
        DB::transaction(function () use ($user, $profile, $reviewer, $types, $note, $role): void {
            KycDocument::query()->where('user_id', $user->id)->whereIn('document_type', $types)->where('status', '!=', 'approved')
                ->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $note ? mb_substr($note, 0, 1000) : 'Kullanıcılar ekranından toplu onay']);
            $profile->update(['kyc_status' => 'approved', 'kyc_verified_at' => now(), 'kyc_verified_by' => $reviewer->id, 'kyc_notes' => null]);
            ActivityLog::record('kyc.approved', "KYC yönetici onayı (kullanıcı #{$user->id}, rol {$role})".($note ? " · {$note}" : ''), $reviewer->id, $profile);
        });
        $isDriver = $role === 'driver';
        $this->notifications->notify($user, 'Belge doğrulamanız tamamlandı',
            ['Belgeleriniz onaylandı. '.($isDriver ? 'Artık ilan havuzunu görebilir ve yüklere teklif verebilirsiniz.' : (FreightPayment::direct() ? 'Artık teklifleri kabul edebilirsiniz; ilanlarınızda doğrulanmış yük sahibi rozeti görünür.' : 'Artık teklifleri kabul edip navlun ödemesi yapabilirsiniz.'))],
            route($isDriver ? 'driver.loads.index' : 'cargo-owner.loads.index'), $isDriver ? 'İlan havuzuna git' : 'İlanlarıma git', 'kyc');
        if ($isDriver) {
            $this->startTrialQuietly($user);
        }
    }

    /** Belgeleri onaylanan şoföre bir kez ücretsiz premium deneme; deneme kapalıysa ya da daha önce kullanıldıysa sessizce geçer. */
    private function startTrialQuietly(User $user): void
    {
        try {
            app(SubscriptionService::class)->startTrial($user->fresh(), automatic: true);
        } catch (\Throwable $e) {
            Log::warning('Ücretsiz premium deneme başlatılamadı.', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** Onayı geri alır: belgeler varsa "inceleniyor", yoksa "gönderilmedi". */
    public function resetProfile(User $user, string $role, User $reviewer): void
    {
        $profile = $role === 'driver' ? $user->driverProfile : $user->cargoOwnerProfile;
        if (! $profile) {
            return;
        }
        $hasDocs = KycDocument::query()->where('user_id', $user->id)->where('status', '!=', 'rejected')->exists();
        $profile->update(['kyc_status' => $hasDocs ? 'pending' : 'unsubmitted', 'kyc_verified_at' => null, 'kyc_verified_by' => null]);
        ActivityLog::record('kyc.reset', "KYC onayı geri alındı (kullanıcı #{$user->id}, rol {$role})", $reviewer->id, $profile);
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
                ['Tüm belgeleriniz onaylandı. '.($isDriver ? 'Artık ilan havuzundaki yüklere teklif verebilirsiniz.' : (FreightPayment::direct() ? 'Artık teklifleri kabul edebilirsiniz; ilanlarınızda doğrulanmış yük sahibi rozeti görünür.' : 'Artık teklifleri kabul edip navlun ödemesi yapabilirsiniz.'))],
                route($isDriver ? 'driver.loads.index' : 'cargo-owner.loads.index'), $isDriver ? 'İlan havuzuna git' : 'İlanlarıma git', 'kyc');
            if ($isDriver) {
                $this->startTrialQuietly($user);
            }
        }
    }
}
