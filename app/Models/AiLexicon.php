<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nakliye jargonu sözlüğü girdisi (yönetici ya da öğrenilmiş). */
class AiLexicon extends Model
{
    protected $table = 'ai_lexicon';

    public const KINDS = [
        'location' => 'Konum kısaltması / semt',
        'vehicle' => 'Araç sözcüğü',
        'goods' => 'Yük sözcüğü',
        'not_load' => '"İlan değil" ifadesi',
        'load_signal' => 'İlan işareti',
        'ignore' => 'Yok sayılacak sözcük',
    ];

    protected $fillable = ['kind', 'term', 'canonical', 'status', 'source', 'hits', 'sample', 'created_by'];

    protected $casts = ['hits' => 'integer'];
}
