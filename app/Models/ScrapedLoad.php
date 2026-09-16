<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class ScrapedLoad extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scraper_id',
        'source_permission_id',
        'content_hash',
        'normalized_hash',
        'route_key',
        'duplicate_count',
        'seen_sources',
        'auto_approved_at',
        'telegram_posted_at',
        'telegram_attempts',
        'raw_message',
        'sender_phone',
        'encrypted_sender_phone',
        'pickup_location',
        'pickup_province_code',
        'pickup_district',
        'pickup_lat',
        'pickup_lng',
        'delivery_location',
        'delivery_province_code',
        'delivery_district',
        'delivery_lat',
        'delivery_lng',
        'goods_type',
        'vehicle_type',
        'vehicle_type_source',
        'weight',
        'price',
        'currency',
        'status',
        'parsed_by_llm',
        'parse_confidence',
        'parse_metadata',
        'visibility',
        'available_to_free_at',
        'retention_expires_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'seen_sources' => 'array',
        'duplicate_count' => 'integer',
        'available_to_free_at' => 'datetime',
        'auto_approved_at' => 'datetime',
        'telegram_posted_at' => 'datetime',
    ];

    /**
     * Ait Olduğu Kazıma Kaynağı (BelongsTo)
     */
    public function scraper(): BelongsTo
    {
        return $this->belongsTo(Scraper::class);
    }

    /** Şifreli saklanan gönderen numarasını çözer; eski kayıtlar için düz kolona düşer. */
    public function plainPhone(): ?string
    {
        if ($this->encrypted_sender_phone) {
            try {
                return Phone::normalize(Crypt::decryptString($this->encrypted_sender_phone));
            } catch (\Throwable) {
                return null;
            }
        }

        return Phone::normalize($this->sender_phone);
    }

    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->plainPhone();

        return $phone ? Phone::format($phone) : 'Bilinmiyor';
    }

    /**
     * APPLE TARZI MİNİMALİST MASKELEYİCİ (Accessor)
     * "533 444 55 66" formatındaki telefonu "533 444 ** **" olarak kısaltır.
     * Fazla karakter kalabalığını tamamen önler.
     */
    public function getMaskedPhoneAttribute(): string
    {
        $phone = $this->plainPhone();

        return $phone ? '0'.substr($phone, 0, 3).' *** ** '.substr($phone, -2) : 'Bilinmiyor';
    }
}
