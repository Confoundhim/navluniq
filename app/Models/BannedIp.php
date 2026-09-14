<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannedIp extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_address',
        'reason',
        'banned_by',
        'banned_until',
    ];

    protected $casts = [
        'banned_until' => 'datetime',
    ];

    /**
     * IP adresinin o an aktif olarak yasaklı olup olmadığını denetler
     */
    public function isBanned(): bool
    {
        if (is_null($this->banned_until)) {
            return true; // Kalıcı ban
        }

        return $this->banned_until->isFuture(); // Süresi henüz dolmamışsa yasaklıdır
    }
}
