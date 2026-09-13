<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'invoice_type',
        'invoice_no',
        'base_amount',
        'tax_amount',
        'total_amount',
        'status',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'base_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Faturanın Kesildiği Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
