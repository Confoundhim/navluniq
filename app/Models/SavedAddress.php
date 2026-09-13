<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavedAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'contact_person',
        'contact_phone',
        'city',
        'district',
        'address_detail',
        'type',
        'is_default',
    ];

    protected $casts = [
        'is_default'=>'boolean',
    ];
}
