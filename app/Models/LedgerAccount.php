<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'account_type',
        'user_id',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'is_active'=>'boolean',
    ];
}
