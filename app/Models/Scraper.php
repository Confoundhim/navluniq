<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scraper extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'source_identifier',
        'is_active',
        'last_scraped_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
    ];

    protected $casts = [
        'last_scraped_at' => 'datetime',
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
