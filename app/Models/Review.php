<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'load_id',
        'reviewer_id',
        'reviewee_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating'=>'integer',
    ];
}
