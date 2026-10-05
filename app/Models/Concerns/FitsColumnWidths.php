<?php

namespace App\Models\Concerns;

/**
 * Metin kolonlarına kolon genişliğinden uzun değer yazılmasını önler (MySQL strict: "1406 Data too long").
 *
 * Telefondan gelen grup adı, yapay zekanın ürettiği etiket ya da hata mesajı kolona sığmazsa kayıt tümden düşüyor ve
 * kuyruk işi "başarısız" oluyordu (2026-10-05 canlı: 19 ProcessNotificationMessage işi). Değer kesilir, kayıt yazılır;
 * sınır modeldeki COLUMN_LIMITS tablosundan okunur (kolon adı → en çok karakter). Yeni metin kolonu eklerken tabloya satır eklenir.
 */
trait FitsColumnWidths
{
    public static function bootFitsColumnWidths(): void
    {
        static::saving(function ($model): void {
            foreach (static::COLUMN_LIMITS as $column => $limit) {
                $value = $model->attributes[$column] ?? null;
                if (is_string($value) && mb_strlen($value) > $limit) {
                    $model->attributes[$column] = mb_substr($value, 0, $limit);
                }
            }
        });
    }

    /** Kolona sığacak biçimde keser; modele yazmadan önce başka yerde (sorgu, karşılaştırma) de kullanılır. */
    public static function fit(string $column, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $limit = static::COLUMN_LIMITS[$column] ?? null;

        return $limit !== null && mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
