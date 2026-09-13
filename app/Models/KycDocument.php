<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KycDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'document_type',
        'storage_disk',
        'storage_path',
        'sha256',
        'status',
        'extracted_data',
        'expires_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'extracted_data'=>'array',
        'expires_at'=>'datetime',
        'reviewed_at'=>'datetime',
    ];
}
