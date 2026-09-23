<?php

namespace App\Livewire;

use Livewire\ComponentHook;

/**
 * Kullanıcı sayfayla uğraşırken (dokunma, yazma, kaydırma, odaklı alan) süreli yenileme
 * (wire:poll → $refresh) ekranı yeniden çizmez; açık listeler, açılır kutular ve yazılanlar bozulmaz.
 * Tarayıcı her istekte "X-User-Idle-Ms" başlığıyla son etkileşimden bu yana geçen süreyi gönderir
 * (resources/js/app.js). Kullanıcının kendi tıklamaları ($refresh dışındaki çağrılar) etkilenmez.
 */
class PausePollWhileInteracting extends ComponentHook
{
    /** Son etkileşimden bu kadar ms geçmemişse yenileme çizilmez. */
    public const IDLE_MS = 30000;

    public function call($method, $params, $returnEarly, $metadata = null, $componentContext = null): void
    {
        if ($method !== '$refresh') {
            return;
        }
        $idle = request()->header('X-User-Idle-Ms');
        if ($idle !== null && is_numeric($idle) && (int) $idle < self::IDLE_MS) {
            $this->component->skipRender();
        }
    }
}
