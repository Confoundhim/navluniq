<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * İşlemi Yapan Personel İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Denetim izi kaydı. Kullanıcı verilmezse oturumdaki kullanıcı kullanılır. */
    public static function record(string $action, string $description, ?int $userId = null, ?Model $subject = null, array $metadata = []): ?self
    {
        $userId ??= Auth::id();
        if (! $userId) {
            return null;
        }

        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => mb_substr($description, 0, 2000),
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
        ]);
    }
}
