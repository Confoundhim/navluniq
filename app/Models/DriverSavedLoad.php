<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Şoförün kaydettiği (yıldızladığı) sistem ya da dış kaynak ilanı. */
class DriverSavedLoad extends Model
{
    protected $fillable = ['driver_profile_id', 'load_id', 'scraped_load_id'];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function scrapedLoad(): BelongsTo
    {
        return $this->belongsTo(ScrapedLoad::class);
    }

    public function kind(): string
    {
        return $this->scraped_load_id ? 'external' : 'system';
    }
}
