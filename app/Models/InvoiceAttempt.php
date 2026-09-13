<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'provider',
        'attempt_no',
        'status',
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
