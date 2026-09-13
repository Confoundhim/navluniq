<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'load_id',
        'shipment_id',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'opened_at'=>'datetime',
        'closed_at'=>'datetime',
    ];
}
