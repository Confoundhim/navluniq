<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CargoOwnerProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'company_title',
        'tax_office',
        'tax_no',
        'tc_no',
        'nvi_verified',
        'gib_verified',
        'kyc_status',
        'kyc_notes',
    ];

    protected $casts = [
        'nvi_verified' => 'boolean',
        'gib_verified' => 'boolean',
    ];

    /**
     * Üst Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
