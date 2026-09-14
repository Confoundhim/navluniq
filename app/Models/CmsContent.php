<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CmsContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'content_type',
        'updated_by',
    ];

    /**
     * Anahtara göre hızlı veri getiren yardımcı statik metod
     */
    public static function getVal(string $key, $default = null)
    {
        $values = Cache::remember('cms_contents.all', 300, fn () => self::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public static function setVal(string $key, ?string $value, ?int $updatedBy = null): self
    {
        return self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $updatedBy]);
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('cms_contents.all');
        static::saved($flush);
        static::deleted($flush);
    }
}
