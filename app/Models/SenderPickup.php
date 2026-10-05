<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gönderen hafızası: kalkışı yazmayan liste gönderen numaranın bilinen kalkış yeri. */
class SenderPickup extends Model
{
    protected $fillable = ['phone_hash', 'pickup_label', 'province_code', 'district', 'source', 'hits', 'taught_by', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];
}
