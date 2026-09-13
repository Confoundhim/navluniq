<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipmentEvidence extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'uploaded_by',
        'type',
        'storage_disk',
        'storage_path',
        'sha256',
        'metadata',
        'captured_at',
    ];

    protected $casts = [
        'metadata'=>'array',
        'captured_at'=>'datetime',
    ];
}
