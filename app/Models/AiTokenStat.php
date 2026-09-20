<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Yerel Bayes sınıflandırıcı sözcük sayacı. */
class AiTokenStat extends Model
{
    protected $table = 'ai_token_stats';

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['token', 'load_count', 'other_count'];
}
