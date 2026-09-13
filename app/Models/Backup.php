<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'backup_type',
        'size_mb',
        'status',
        'download_url',
    ];

    protected $casts = [
        'size_mb' => 'decimal:2',
    ];
}
