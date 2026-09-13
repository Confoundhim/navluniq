<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProviderUsage extends Model
{
    use HasFactory;

    protected $table = 'ai_provider_usage';

    protected $fillable = [
        'provider',
        'usage_date',
        'request_count',
        'input_units',
        'output_units',
        'failure_count',
        'quota_exhausted',
        'quota_resets_at',
        'estimated_cost',
        'currency',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'quota_exhausted' => 'boolean',
        'quota_resets_at' => 'datetime',
        'estimated_cost' => 'decimal:6',
    ];
}
