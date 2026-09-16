<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverFilterPreset extends Model
{
    protected $fillable = ['driver_profile_id', 'name', 'is_default', 'notify', 'filters', 'last_used_at'];

    protected $casts = [
        'is_default' => 'boolean',
        'notify' => 'boolean',
        'filters' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }
}
