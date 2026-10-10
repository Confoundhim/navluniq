<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Ödeme kuruluşunda saklanan kartın NavlunIQ'daki izi: kart numarası hiçbir zaman buraya gelmez; kuruluşun verdiği
 * kullanıcı anahtarı + kart anahtarı (şifreli), son 4 hane ve kart ailesi tutulur. Otomatik yenilenen premium abonelik
 * bu anahtarla çekim yapar.
 */
class StoredCard extends Model
{
    protected $fillable = ['user_id', 'provider', 'card_user_key', 'card_token', 'last_four', 'card_association', 'card_family', 'bank_name'];

    protected $casts = ['card_token' => 'encrypted'];

    /**
     * Kullanıcı anahtarı da şifreli yazılır; eski satırlar düz metin olduğundan okuma toleranslıdır (çözülemeyen değer olduğu
     * gibi döner, bir sonraki kayıtta şifrelenir). Kolon text (0001_01_64) ki şifreli değer sığsın.
     */
    protected function cardUserKey(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }
                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return $value; // eski düz metin kayıt
                }
            },
            set: fn (?string $value): ?string => $value === null || $value === '' ? $value : Crypt::encryptString($value),
        );
    }

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
