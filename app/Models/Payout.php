<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payout extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'load_id',
        'user_id',
        'iban',
        'bank_name',
        'total_amount',
        'commission_amount',
        'net_amount',
        'status',
        'reference_no',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    /**
     * Ait Olduğu Yük / Sevkiyat İlişkisi
     * 🚀 İsim çakışmasını önlemek için 'cargoLoad' yapılmıştır [11.2].
     */
    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    /**
     * Hak Sahibi Şoför Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
