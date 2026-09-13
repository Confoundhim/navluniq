<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }
}
