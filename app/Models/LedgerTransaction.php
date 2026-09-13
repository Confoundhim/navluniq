<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPublicId;

class LedgerTransaction extends Model
{
    use HasFactory, SoftDeletes, HasPublicId;

    protected $fillable = [
        'public_id',
        'reference_type',
        'reference_id',
        'transaction_type',
        'description',
        'occurred_at',
        'posted_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'occurred_at'=>'datetime',
        'posted_at'=>'datetime',
        'metadata'=>'array',
    ];
}
