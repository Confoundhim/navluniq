<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bir sefer için şoföre bildirilmiş dönüş yükü (aynı ilan yeniden bildirilmez). */
class DriverTripMatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_trip_id', 'kind', 'matched_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
