<?php

namespace App\Models;

use App\Models\Concerns\FitsColumnWidths;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scraper extends Model
{
    use FitsColumnWidths;
    use HasFactory, SoftDeletes;

    /** Metin kolonlarının genişliği; telefondan gelen uzun grup adı kesilir, kaynak kaydı düşmez. */
    public const COLUMN_LIMITS = ['name' => 255, 'type' => 32, 'source_identifier' => 255];

    protected $fillable = [
        'name',
        'type',
        'source_identifier',
        'is_active',
        'last_scraped_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'messages_since_deleted',
        'last_message_at',
    ];

    protected $casts = [
        'last_scraped_at' => 'datetime',
        'last_message_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Kaynaktan Derlenen İlanlar (1-to-Many)
     */
    public function scrapedLoads(): HasMany
    {
        return $this->hasMany(ScrapedLoad::class);
    }
}
