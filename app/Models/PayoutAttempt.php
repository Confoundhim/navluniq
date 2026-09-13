<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayoutAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payout_id',
        'provider',
        'provider_reference',
        'status',
        'attempt_no',
        'request_payload',
        'response_payload',
        'failure_message',
        'attempted_at',
    ];

    protected $casts = [
        'request_payload'=>'array',
        'response_payload'=>'array',
        'attempted_at'=>'datetime',
    ];
}
