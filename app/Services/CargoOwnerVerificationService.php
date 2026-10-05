<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Yük sahibi kimlik doğrulaması (karar 3, 2026-10-05; sadeleştirme aynı gün: "sorma, para anında bir kez doğrula").
 * Bireysel yük sahibi TC + doğum yılını yalnız ilk teklif kabulünde (ya da profilden) verir; NVİ eşleşirse bir daha sorulmaz.
 * Profil sayfası ve teklif kabul adımı aynı metodu kullanır; günde en çok NVI_RETRY_PER_DAY deneme.
 */
class CargoOwnerVerificationService
{
    public const NVI_RETRY_PER_DAY = 3;

    public function __construct(private readonly NviService $nvi) {}

    /** Doğrulama kuralları (Livewire validate ile aynı anahtarlar: tc, birth_year). */
    public static function rules(): array
    {
        return [
            'tc' => ['required', 'digits:11'],
            'birth_year' => ['required', 'digits:4', 'integer', 'min:1920', 'max:'.(date('Y') - 18)],
        ];
    }

    public static function messages(string $tcField = 'tc', string $yearField = 'birth_year'): array
    {
        return [
            $tcField.'.required' => 'T.C. kimlik numaranızı girin.',
            $tcField.'.digits' => 'T.C. kimlik numarası 11 haneli olmalıdır.',
            $yearField.'.required' => 'Doğum yılınızı girin.',
            $yearField.'.max' => 'Platformu 18 yaşından büyükler kullanabilir.',
        ];
    }

    /**
     * NVİ sorgusu yapar, profili günceller. Başarıda null, aksi halde kullanıcıya gösterilecek hata metni döner.
     */
    public function verifyIdentity(User $user, string $tc, string $birthYear): ?string
    {
        $profile = $user->cargoOwnerProfile;
        if (! $profile || $profile->type !== 'individual') {
            return 'Bu hesap için kimlik doğrulaması gerekmiyor.';
        }
        if ($profile->nvi_verified) {
            return null;
        }
        $taken = CargoOwnerProfile::query()->where('tc_no', $tc)->where('user_id', '!=', $user->id)->exists();
        if ($taken) {
            return 'Bu T.C. kimlik numarası başka bir hesapta kayıtlı.';
        }
        $limitKey = 'nvi:self:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, self::NVI_RETRY_PER_DAY)) {
            return 'Günlük doğrulama deneme sınırına ulaştınız; yarın yeniden deneyin.';
        }
        RateLimiter::hit($limitKey, 86400);

        $result = $this->nvi->verify($tc, (string) $user->first_name, (string) $user->last_name, $birthYear);
        $match = (bool) ($result['is_match'] ?? false);
        $profile->update([
            'tc_no' => $tc,
            'birth_year' => (int) $birthYear,
            'nvi_verified' => $match,
            'nvi_checked_at' => now(),
            'nvi_message' => $match ? null : mb_substr((string) ($result['message'] ?? 'Kimlik doğrulanamadı.'), 0, 200),
        ]);
        ActivityLog::record('kyc.nvi_checked', 'Yük sahibi kimliğini sorguladı: '.($match ? 'eşleşti' : 'eşleşmedi'), $user->id, $profile, [
            'success' => (bool) ($result['success'] ?? false), 'is_match' => $match,
        ]);

        if ($match) {
            return null;
        }

        return (string) ($result['message'] ?? 'Kimlik bilgileri eşleşmedi.').' Ad ve soyadınızın nüfus kaydıyla birebir aynı olduğundan emin olun.';
    }
}
