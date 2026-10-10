<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    use HasFactory, HasPublicId;

    /** Yönetici ekranlarında gösterilen Türkçe durum adları. */
    public const STATUS_LABELS = [
        'created' => 'Oluşturuldu',
        'pending' => 'Bekliyor',
        'paid' => 'Ödendi',
        'failed' => 'Başarısız',
        'cancelled' => 'İptal',
        'expired' => 'Süresi doldu',
        'refund_pending' => 'İade bekliyor',
        'refunded' => 'İade edildi',
    ];

    /** Ödeme emrinin amacı (PaymentService::PURPOSE_*). */
    public const PURPOSE_LABELS = [
        'subscription' => 'Premium üyelik',
        'escrow' => 'Navlun',
        'freight' => 'Navlun',
    ];

    protected $fillable = [
        'load_id',
        'user_id',
        'purpose',
        'subscription_months',
        'auto_renew',
        'stored_card_id',
        'provider',
        'merchant_oid',
        'provider_reference',
        'amount',
        'currency',
        'service_fee_amount',
        'commission_rate',
        'commission_amount',
        'driver_net_amount',
        'insurance_amount',
        'status',
        'request_snapshot',
        'authorized_at',
        'paid_at',
        'failed_at',
        'refunded_at',
        'failure_message',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'service_fee_amount' => 'decimal:4',
        'commission_rate' => 'decimal:3',
        'commission_amount' => 'decimal:4',
        'driver_net_amount' => 'decimal:4',
        'insurance_amount' => 'decimal:4',
        'auto_renew' => 'boolean',
        'request_snapshot' => 'array',
        'authorized_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function purposeLabel(): string
    {
        return self::PURPOSE_LABELS[$this->purpose] ?? (string) $this->purpose;
    }

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** Yenileme çekiminde kullanılan kayıtlı kart (kullanıcının açtığı ödemede boş). */
    public function storedCard(): BelongsTo
    {
        return $this->belongsTo(StoredCard::class);
    }
}
