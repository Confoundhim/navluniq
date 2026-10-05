<?php

namespace App\Models;

use App\Models\Concerns\FitsColumnWidths;
use App\Services\AiParserService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dış kaynak hattına gelen her isteğin sonucu (canlı akış ve sorun giderme için). */
class IntakeEvent extends Model
{
    use FitsColumnWidths;

    public $timestamps = false;

    /** Metin kolonlarının genişliği (0001_01_09 migration'ı); uzun gerekçe/grup adı kesilir, canlı akış satırı düşmez. */
    public const COLUMN_LIMITS = ['source_name' => 160, 'status' => 24, 'reason' => 120, 'title' => 255, 'excerpt' => 300, 'ip' => 45];

    public const STATUS_LABELS = [
        'created' => 'Kuyruğa alındı',
        'duplicate' => 'Tekrar (sayaç arttı)',
        'filtered' => 'İlan değil, elendi',
        'source_pending' => 'Kaynak onay bekliyor',
        'skipped' => 'Atlandı',
        'unauthorized' => 'Anahtar hatalı',
        'failed' => 'İşlenemedi',
        'ping' => 'Bağlantı sınaması (telefon sunucuya ulaştı)',
        'screen' => 'Ekran dökümü alındı (telefon sunucuya ulaştı)',
        'source_deleted' => 'Silinmiş kaynaktan mesaj (yok sayıldı)',
    ];

    protected $fillable = ['source_name', 'status', 'reason', 'title', 'excerpt', 'scraped_load_id', 'ip', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function scrapedLoad(): BelongsTo
    {
        return $this->belongsTo(ScrapedLoad::class);
    }

    public static function record(string $status, array $attributes = []): self
    {
        // KVKK: canlı akış alıntısında telefon numaraları maskelenir (ilk 4 hane kalır); numara yalnız adayın şifreli kolonundadır.
        if (is_string($attributes['excerpt'] ?? null)) {
            $attributes['excerpt'] = self::maskPhones($attributes['excerpt']);
        }

        return self::create(array_merge([
            'status' => $status,
            'created_at' => now(),
            'ip' => request()?->ip(),
        ], $attributes));
    }

    /** Metindeki cep numaralarını "0532…" biçimine indirger. */
    public static function maskPhones(string $text): string
    {
        return preg_replace_callback(AiParserService::PHONE_PATTERN, function (array $m): string {
            $digits = preg_replace('/\D+/', '', $m[0]) ?? '';
            if (str_starts_with($digits, '90') && strlen($digits) === 12) {
                $digits = substr($digits, 2);
            }
            $digits = ltrim($digits, '0');

            return (strlen($digits) === 7 ? '' : '0').substr($digits, 0, 3).'…'; // 444'lü kısa numarada sıfır yok
        }, $text) ?? $text;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Elenme / tekrar gerekçelerinin Türkçe karşılığı (canlı akış ve günlük özet). */
    public const REASON_LABELS = [
        'phone_missing' => 'telefon numarası yok', 'no_logistics_signal' => 'rota/tonaj/araç/yük işareti yok', 'route_missing' => 'kalkış-varış çözülemedi',
        'regex_required_fields_missing' => 'kalkış-varış çözülemedi', 'pickup_missing' => 'kalkış yeri yazmıyor (yalnız varış listesi)', 'ai_not_load' => 'yapay zeka: yük ilanı değil',
        'template_not_load' => 'şablon: gönderenin bu kalıbı ilan değil', 'lexicon_not_load' => 'sözlük: "ilan değil" ifadesi', 'foreign_script' => 'yabancı alfabe (Rusça/Arapça)',
        'not_load_pattern' => 'ilan değil: boş araç / şoför ilanı / reklam / satılık', 'local_not_load' => 'yerel sınıflandırıcı: ilan değil', 'token_missing' => 'istekte anahtar yok',
        'token_mismatch' => 'anahtar sunucudakiyle uyuşmuyor', 'summary_notification' => 'özet bildirim (N yeni mesaj)', 'empty' => 'başlık ya da metin boş', 'not_whatsapp' => 'WhatsApp dışı uygulama',
    ];

    public static function reasonLabel(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }
        // "summary_notification (json_repaired)" gibi ek bilgili gerekçeler: asıl kod etiketlenir, ek parantezde kalır
        $base = trim((string) strtok($reason, ' ('));
        $extra = trim(mb_substr($reason, mb_strlen($base)));

        return (self::REASON_LABELS[$base] ?? $base).($extra !== '' ? ' '.$extra : '');
    }
}
