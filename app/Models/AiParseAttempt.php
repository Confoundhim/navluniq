<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiParseAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'scraped_load_id',
        'provider',
        'model',
        'status',
        'latency_ms',
        'error_code',
        'error_message',
        'response_metadata',
    ];

    protected $casts = [
        'response_metadata'=>'array',
    ];
}
