<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dış kaynak hattına gelen her isteğin sonucu (canlı akış ve sorun giderme için). */
class IntakeEvent extends Model
{
    public $timestamps = false;

    public const STATUS_LABELS = [
        'created' => 'Kuyruğa alındı',
        'duplicate' => 'Tekrar (sayaç arttı)',
        'filtered' => 'İlan değil, elendi',
        'source_pending' => 'Kaynak onay bekliyor',
        'skipped' => 'Atlandı',
        'unauthorized' => 'Anahtar hatalı',
        'failed' => 'İşlenemedi',
        'ping' => 'Bağlantı sınaması (telefon sunucuya ulaştı)',
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
        return self::create(array_merge([
            'status' => $status,
            'created_at' => now(),
            'ip' => request()?->ip(),
        ], array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 300) : $v, $attributes)));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
