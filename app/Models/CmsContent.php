<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CmsContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Anahtara göre hızlı veri getiren yardımcı statik metod
     */
    public static function getVal(string $key, $default = null)
    {
        $content = self::where('key', $key)->first();
        return $content ? $content->value : $default;
    }
}
