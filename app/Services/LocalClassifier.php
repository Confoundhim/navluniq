<?php

namespace App\Services;

use App\Models\AiTokenStat;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Support\Settings;
use App\Support\TurkishCities;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Yerel "ilan mı, değil mi" sınıflandırıcısı (naive Bayes). Dış servise bağlı değildir; yönetici kararlarından
 * (yayınla / reddet) ve yapay zeka doğrulamalı otomatik onaylardan öğrenir. Sözcük sayaçları veritabanında tutulur.
 */
class LocalClassifier
{
    /** Her sınıfta en az bu kadar örnek yoksa karar vermez (null). */
    public const MIN_DOCS = 15;

    public const MAX_TOKENS = 80;

    /**
     * Sözcükler (3+ harf, sayı değil, telefon değil) + ardışık ikili birleşimler. İl adları tek bir "__il__"
     * belirtecine indirgenir: rota sözcükleri değil, yazım kalıbı öğrenilsin.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $norm = LoadIntakeService::normalizeText($text);
        $words = [];
        foreach (explode(' ', $norm) as $w) {
            if ($w === '' || mb_strlen($w) < 3 || preg_match('/^\d+$/u', $w)) {
                continue;
            }
            if (preg_match('/\d{4,}/u', $w)) {
                continue;
            }
            $ascii = TurkishCities::ascii($w);
            $words[] = TurkishCities::fromText($w, fuzzy: false) !== null ? '__il__' : mb_substr($ascii, 0, 24);
        }
        $out = $words;
        for ($i = 0; $i + 1 < count($words); $i++) {
            $out[] = $words[$i].'_'.$words[$i + 1];
        }

        return array_slice(array_values(array_unique($out)), 0, self::MAX_TOKENS);
    }

    public function train(string $text, bool $isLoad): void
    {
        $tokens = self::tokens($text);
        if ($tokens === []) {
            return;
        }
        try {
            DB::transaction(function () use ($tokens, $isLoad): void {
                $col = $isLoad ? 'load_count' : 'other_count';
                foreach ($tokens as $token) {
                    AiTokenStat::query()->updateOrInsert(['token' => $token], []);
                    AiTokenStat::query()->where('token', $token)->increment($col);
                }
                $key = $isLoad ? 'ai_local_docs_load' : 'ai_local_docs_other';
                Settings::set($key, Settings::int($key) + 1);
            });
        } catch (Throwable) {
            // Öğrenme arızası alım hattını durdurmasın.
        }
    }

    /** İlan olma olasılığı (0-1); yeterli örnek yoksa null. */
    public function score(string $text): ?float
    {
        $docsLoad = Settings::int('ai_local_docs_load');
        $docsOther = Settings::int('ai_local_docs_other');
        if ($docsLoad < self::MIN_DOCS || $docsOther < self::MIN_DOCS) {
            return null;
        }
        $tokens = self::tokens($text);
        if ($tokens === []) {
            return null;
        }
        try {
            $stats = AiTokenStat::query()->whereIn('token', $tokens)->get()->keyBy('token');
        } catch (Throwable) {
            return null;
        }
        $logLoad = log($docsLoad / ($docsLoad + $docsOther));
        $logOther = log($docsOther / ($docsLoad + $docsOther));
        foreach ($tokens as $token) {
            $row = $stats->get($token);
            $l = (int) ($row?->load_count ?? 0);
            $o = (int) ($row?->other_count ?? 0);
            if ($l === 0 && $o === 0) {
                continue; // bilinmeyen sözcük karar değiştirmez
            }
            $logLoad += log(($l + 1) / ($docsLoad + 2));
            $logOther += log(($o + 1) / ($docsOther + 2));
        }
        $diff = max(-40.0, min(40.0, $logLoad - $logOther));

        return round(1 / (1 + exp(-$diff)), 4);
    }

    /** @return array{docs_load:int, docs_other:int, tokens:int, ready:bool} */
    public function stats(): array
    {
        $l = Settings::int('ai_local_docs_load');
        $o = Settings::int('ai_local_docs_other');
        try {
            $tokens = AiTokenStat::query()->count();
        } catch (Throwable) {
            $tokens = 0;
        }

        return ['docs_load' => $l, 'docs_other' => $o, 'tokens' => $tokens, 'ready' => $l >= self::MIN_DOCS && $o >= self::MIN_DOCS];
    }

    /**
     * Sayaçları sıfırlayıp geçmişten yeniden öğrenir: yayınlanmış adaylar ilan, reddedilenler ve yapay zekanın
     * yüksek güvenle "ilan değil" dedikleri ilan-değil örneğidir.
     *
     * @return array{load:int, other:int}
     */
    public function rebuild(): array
    {
        AiTokenStat::query()->delete();
        Settings::set('ai_local_docs_load', 0);
        Settings::set('ai_local_docs_other', 0);
        $load = 0;
        $other = 0;
        ScrapedLoad::withTrashed()->where('visibility', 'public')->orWhereNotNull('auto_approved_at')->orderBy('id')->chunk(200, function ($rows) use (&$load): void {
            foreach ($rows as $row) {
                $this->train((string) $row->raw_message, true);
                $load++;
            }
        });
        ScrapedLoad::withTrashed()->where('status', 'rejected')->orderBy('id')->chunk(200, function ($rows) use (&$other): void {
            foreach ($rows as $row) {
                $this->train((string) $row->raw_message, false);
                $other++;
            }
        });
        IntakeEvent::query()->where('status', 'filtered')->whereIn('reason', ['ai_not_load', 'lexicon_not_load'])->whereNotNull('excerpt')->orderBy('id')->chunk(200, function ($rows) use (&$other): void {
            foreach ($rows as $row) {
                $this->train((string) $row->excerpt, false);
                $other++;
            }
        });

        return ['load' => $load, 'other' => $other];
    }
}
