<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserConsent extends Model
{
    use HasFactory;

    /** Kayıtta ve yeniden onayda alınan metinler. */
    public const REQUIRED_TYPES = ['terms', 'kvkk'];

    protected $fillable = [
        'user_id',
        'consent_type',
        'document_version',
        'granted',
        'recorded_at',
        'revoked_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'granted' => 'boolean',
        'recorded_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Yürürlükteki sözleşme/KVKK metni sürümü (panelden artırılır; eski .env değeri yedek). */
    public static function currentVersion(): string
    {
        $v = trim(Settings::string('legal_document_version'));

        return $v !== '' ? $v : (string) config('company.legal_document_version', '1.0');
    }

    /** Kayıt (ya da yeniden onay) anındaki onayları yürürlükteki sürümle yazar. */
    public static function recordRegistration(User $user, ?string $version = null): void
    {
        $version ??= self::currentVersion();
        foreach (self::REQUIRED_TYPES as $type) {
            self::create([
                'user_id' => $user->id,
                'consent_type' => $type,
                'document_version' => $version,
                'granted' => true,
                'recorded_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
            ]);
        }
    }

    /** Kullanıcının her zorunlu metin için yürürlükteki sürümü onaylamış olup olmadığı. */
    public static function upToDate(User $user): bool
    {
        $current = self::currentVersion();
        $latest = self::query()->where('user_id', $user->id)->where('granted', true)->whereNull('revoked_at')
            ->whereIn('consent_type', self::REQUIRED_TYPES)->orderByDesc('recorded_at')->orderByDesc('id')->get()
            ->unique('consent_type')->keyBy('consent_type');
        foreach (self::REQUIRED_TYPES as $type) {
            if (($latest[$type]->document_version ?? null) !== $current) {
                return false;
            }
        }

        return true;
    }
}
