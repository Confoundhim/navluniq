<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ödeme kuruluşunda saklanan kartın NavlunIQ'daki izi: kart numarası hiçbir zaman buraya gelmez; kuruluşun verdiği
 * kullanıcı anahtarı + kart anahtarı (şifreli), son 4 hane ve kart ailesi tutulur. Otomatik yenilenen premium abonelik
 * bu anahtarla çekim yapar.
 */
class StoredCard extends Model
{
    protected $fillable = ['user_id', 'provider', 'card_user_key', 'card_token', 'last_four', 'card_association', 'card_family', 'bank_name'];

    protected $casts = ['card_token' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kullanıcıya gösterilen ad: "Visa •••• 1234" (aile/banka adı varsa onunla). */
    public function label(): string
    {
        $brand = match (strtoupper((string) $this->card_association)) {
            'VISA' => 'Visa',
            'MASTER_CARD', 'MASTERCARD' => 'Mastercard',
            'TROY' => 'Troy',
            'AMERICAN_EXPRESS', 'AMEX' => 'American Express',
            default => ($this->card_family ?: ($this->bank_name ?: 'Kart')),
        };

        return trim($brand.' •••• '.($this->last_four ?: '????'));
    }
}
