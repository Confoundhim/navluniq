<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'key',
        'setting_label',
        'old_value',
        'new_value',
    ];

    /**
     * Değişikliği Yapan Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
