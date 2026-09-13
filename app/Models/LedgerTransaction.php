<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerTransaction extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
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
        'occurred_at' => 'datetime',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];
}
