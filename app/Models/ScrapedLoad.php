<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScrapedLoad extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scraper_id',
        'raw_message',
        'sender_phone',
        'pickup_location',
        'delivery_location',
        'goods_type',
        'weight',
        'price',
        'status',
        'parsed_by_llm',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Ait Olduğu Kazıma Kaynağı (BelongsTo)
     */
    public function scraper(): BelongsTo
    {
        return $this->belongsTo(Scraper::class);
    }

    /**
     * 🚀 AKILLI TELEFON BİÇİMLENDİRİCİSİ (Accessor)
     * Telefon numarasını temizler ve tam olarak '533 444 55 66' formatında boşluklu döndürür.
     */
    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->sender_phone;

        if (!$phone || $phone === 'Bilinmiyor') {
            return 'Bilinmiyor';
        }

        // Sadece rakamları ayıkla
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Eğer numara başında 90 varsa kaldır
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '90') {
            $clean = substr($clean, 2);
        } elseif (strlen($clean) === 11 && $clean[0] === '0') {
            $clean = substr($clean, 1);
        }

        // Eğer tam 10 haneli standart TR cep telefonu ise biçimlendir: "533 444 55 66"
        if (strlen($clean) === 10) {
            return substr($clean, 0, 3) . ' ' .
                   substr($clean, 3, 3) . ' ' .
                   substr($clean, 6, 2) . ' ' .
                   substr($clean, 8, 2);
        }

        return $phone; // Fallback
    }

    /**
     * 🚀 APPLE TARZI MİNİMALİST MASKELEYİCİ (Accessor)
     * "533 444 55 66" formatındaki telefonu "533 444 ** **" olarak kısaltır.
     * Fazla karakter kalabalığını tamamen önler.
     */
    public function getMaskedPhoneAttribute(): string
    {
        $phone = $this->formatted_phone;

        if ($phone === 'Bilinmiyor' || strlen(preg_replace('/[^0-9]/', '', $phone)) !== 10) {
            return 'Bilinmiyor';
        }

        // "533 444 55 66" -> "533 444 ** **"
        return substr($phone, 0, 7) . ' ** **';
    }
}
