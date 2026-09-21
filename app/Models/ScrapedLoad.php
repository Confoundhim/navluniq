<?php

namespace App\Models;

use App\Support\Phone;
use App\Support\Settings;
use App\Support\VehicleTypes;
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
        'price_unit',
        'status',
        'parsed_by_llm',
        'parse_confidence',
        'ai_status',
        'ai_checked_at',
        'parse_metadata',
        'visibility',
        'available_to_free_at',
        'retention_expires_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'seen_sources' => 'array',
        'parse_metadata' => 'array',
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

    /**
     * Aynı ilandaki diğer numaralar (ilk numara dışındakiler); şifreli saklanır.
     *
     * @return list<string>
     */
    public function extraPhones(): array
    {
        $out = [];
        foreach ((array) $this->meta('extra_phones_enc', []) as $enc) {
            try {
                $phone = Phone::normalize(Crypt::decryptString((string) $enc));
            } catch (\Throwable) {
                continue;
            }
            if ($phone !== null && ! in_array($phone, $out, true)) {
                $out[] = $phone;
            }
        }

        return $out;
    }

    /**
     * İlanın tüm numaraları, ana numara önce.
     *
     * @return list<string>
     */
    public function allPhones(): array
    {
        $primary = $this->plainPhone();

        return array_values(array_unique(array_filter(array_merge([$primary], $this->extraPhones()))));
    }

    public static function maskPhone(?string $phone): string
    {
        return $phone ? '0'.substr($phone, 0, 3).' *** ** '.substr($phone, -2) : 'Bilinmiyor';
    }

    /** Standart başlık: "Diyarbakır → İstanbul Kartal". */
    public function routeLabel(): string
    {
        return ($this->pickup_location ?: 'Belirtilmemiş').' → '.($this->delivery_location ?: 'Belirtilmemiş');
    }

    /**
     * Şoförün WhatsApp'ta ilan sahibine göndereceği hazır mesaj (panelden düzenlenen şablon; {rota} {yuk} {arac} {ad}).
     * Şablon "-" ise null: sohbet mesajsız açılır (boş bırakılırsa varsayılan metin kullanılır).
     */
    public function contactMessage(?User $driver = null): ?string
    {
        $template = trim((string) Settings::get('scraper_contact_message'));
        if ($template === '' || $template === '-') { // boş: varsayılan metin (Settings), "-": mesajsız aç
            return null;
        }
        $details = array_values(array_filter([$this->goods_type, $this->weightLabel(), $this->vehicleLabel()]));
        $vehicle = $driver?->driverProfile?->activeVehicle;
        $vehicleLabel = $vehicle ? trim(VehicleTypes::label($vehicle->vehicle_type).' '.($vehicle->plate ?? '')) : '';
        $text = strtr($template, [
            '{rota}' => $this->routeLabel(),
            '{yuk}' => $details !== [] ? implode(' · ', $details) : 'yük',
            '{arac}' => $vehicleLabel,
            '{ad}' => trim((string) ($driver?->full_name ?? '')),
        ]);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
    }

    /** wa.me bağlantısı: numara + (varsa) hazır mesaj. */
    public function whatsappUrl(string $phone, ?User $driver = null): string
    {
        $message = $this->contactMessage($driver);

        return 'https://wa.me/90'.$phone.($message !== null ? '?text='.rawurlencode($message) : '');
    }

    /** "45.000 ₺", "1.000 ₺/ton", "1.200 $" ya da null (fiyat yok). */
    public function priceLabel(): ?string
    {
        if ($this->price === null || (float) $this->price <= 0) {
            return null;
        }
        $v = (float) $this->price;
        $symbol = ['USD' => '$', 'EUR' => '€'][$this->currency ?? 'TRY'] ?? '₺';

        return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, ',', '.').' '.$symbol.($this->price_unit === 'per_ton' ? '/ton' : '');
    }

    public function isPerTon(): bool
    {
        return $this->price_unit === 'per_ton';
    }

    /** "24 ton" / "800 kg" / null. */
    public function weightLabel(): ?string
    {
        $kg = (int) $this->weight;
        if ($kg <= 0) {
            return null;
        }
        if ($kg >= 1000) {
            $t = $kg / 1000;

            return (fmod($t, 1.0) === 0.0 ? number_format($t, 0, ',', '.') : number_format($t, 1, ',', '.')).' ton';
        }

        return number_format($kg, 0, ',', '.').' kg';
    }

    /** Araç etiketi: açıkça istenen tip ise adı, çıkarımsa "X ve üzeri". */
    public function vehicleLabel(): ?string
    {
        if (! VehicleTypes::isValid($this->vehicle_type)) {
            return null;
        }
        $exact = in_array($this->vehicle_type_source, ['keyword', 'ai', 'admin', 'template'], true) || $this->vehicle_type === 'tir';

        return $exact ? VehicleTypes::label($this->vehicle_type) : VehicleTypes::label($this->vehicle_type).' ve üzeri';
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return ((array) ($this->parse_metadata ?? []))[$key] ?? $default;
    }

    public function isUrgent(): bool
    {
        return (bool) $this->meta('urgent', false);
    }

    /** @return list<string> Soğuk zincir, Kırılgan, ADR, Gabari dışı */
    public function traitLabels(): array
    {
        $map = ['cold' => 'Soğuk zincir', 'fragile' => 'Kırılgan', 'hazmat' => 'ADR', 'oversize' => 'Gabari dışı'];

        return array_values(array_filter(array_map(fn ($t) => $map[$t] ?? null, (array) $this->meta('goods_traits', []))));
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
        return self::maskPhone($this->plainPhone());
    }
}
