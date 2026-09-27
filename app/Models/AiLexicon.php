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
        'body' => 'Kasa sözcüğü (yük/ifade → kasa tipi)',
        'not_load' => '"İlan değil" ifadesi',
        'load_signal' => 'İlan işareti',
        'ignore' => 'Yok sayılacak sözcük',
    ];

    protected $fillable = ['kind', 'term', 'canonical', 'status', 'source', 'hits', 'sample', 'note', 'last_load_id', 'created_by'];

    protected $casts = ['hits' => 'integer', 'last_load_id' => 'integer'];

    /** Kaynak etiketi (sözlük ekranı). */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'learned' => 'öğrenildi',
            'ai' => 'yapay zeka',
            default => 'yönetici',
        };
    }
}
