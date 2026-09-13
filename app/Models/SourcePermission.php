<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SourcePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'scraper_id',
        'permission_basis',
        'visibility',
        'approved_by',
        'approved_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'approved_at'=>'datetime',
        'expires_at'=>'datetime',
    ];
}
