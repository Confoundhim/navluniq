<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gölgedeki okuma katmanının bir mesaj için ne yapacağı ve hakemin kararı (IntakeLayerReview bununla aşama kararı verir). */
class IntakeLayerSample extends Model
{
    public $timestamps = false;

    protected $fillable = ['layer', 'stage', 'scraped_load_id', 'predicted_pickup', 'predicted_delivery', 'judge_pickup', 'judge_delivery', 'verdict', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
