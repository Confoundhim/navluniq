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
        'payment_order_id',
        'payout_id',
        'invoice_type',
        'invoice_no',
        'provider',
        'provider_reference',
        'base_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'tax_rate',
        'status',
        'issued_at',
        'cancelled_at',
    ];

    /** Durum ve tür etiketleri (ekranda ham İngilizce değer görünmesin). */
    public const STATUS_LABELS = [
        'pending' => 'Fatura hazırlanıyor',
        'issued' => 'Kesildi',
        'paid' => 'Ödendi',
        'cancelled' => 'İptal edildi',
    ];

    public const TYPE_LABELS = [
        'subscription' => 'Premium abonelik',
        'commission' => 'Hizmet bedeli',
        'payout' => 'Hakediş',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'base_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->invoice_type] ?? (string) $this->invoice_type;
    }

    /**
     * Faturanın Kesildiği Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
