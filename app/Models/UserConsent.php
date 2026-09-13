<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'consent_type',
        'document_version',
        'granted',
        'recorded_at',
        'revoked_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'granted'=>'boolean',
        'recorded_at'=>'datetime',
        'revoked_at'=>'datetime',
    ];
}
