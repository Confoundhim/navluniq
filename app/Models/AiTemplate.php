<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gönderen + yazım kalıbı → doğrulanmış çözüm (şablon hafızası). */
class AiTemplate extends Model
{
    protected $fillable = ['phone_hash', 'signature_hash', 'signature', 'is_load', 'pickup_index', 'delivery_index', 'vehicle_type', 'goods_category', 'confidence', 'uses', 'last_used_at', 'source_load_id'];

    protected $casts = ['is_load' => 'boolean', 'confidence' => 'float', 'uses' => 'integer', 'last_used_at' => 'datetime'];
}
