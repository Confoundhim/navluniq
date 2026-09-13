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
        'storage_disk',
        'storage_path',
        'size_bytes',
        'size_mb',
        'status',
        'failure_message',
        'download_url',
        'completed_at',
    ];

    protected $casts = [
        'size_mb' => 'decimal:2',
    ];
}
