<?php

namespace App\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Öğrenme çemberi: ücretsiz yapay zeka ilanları çözdükçe kurallarımız gelişir, ama hiçbir şey kontrol dışı öğrenilmez.
 *
 * 1. Yapay zekanın çözdüğü ama kuralın çözemediği (ya da farklı çözdüğü) her yazım sözlük ekranına "öneri" olarak düşer:
 *    hangi sözcük, hangi karşılık, kaç ayrı ilanda görüldü, örnek mesaj. Sözlüğe kendiliğinden girmez.
 * 2. Yönetici tek dokunuşla onaylar; ayarlı sayıda ayrı ilanda aynı öneri gelirse (`ai_suggest_auto_approve_hits`, 0 kapalı)
 *    kendiliğinden onaylanır. Onaylanan öneri sözlüğe girer; o andan sonra aynı yazım yapay zeka çağrılmadan kuralla çözülür.
 *    Yapay zeka aynı yazıma bir başka karşılık verirse sayaç sıfırlanır (çelişkili öneri kendiliğinden onaylanmaz).
 * 3. Kuralla çözülmüş ilanlardan günlük bir örneklem (`ai_audit_daily_count`) yapay zekaya "bu doğru mu?" diye gösterilir;
 *    uyuşmazlık ilanın kaydına yazılır ve yine öneri olur. Kural yanlışları böyle yakalanır.
 * 4. Haftalık sayaçlar (kuralla çözülen / yapay zeka gereken / denetlenen / bekleyen öneri) sağlık ekranında görünür.
 */
class RuleFeedbackService
{
    /** Yapay zekadan gelen önerinin kaynağı (sözlük ekranında "yapay zeka"). */
    public const SOURCE = 'ai';

    /** Yük sözcüğü olamayacak genel adlar: her ilanda geçer, kategori anlatmaz. */
    public const GENERIC_GOODS = ['yuk', 'yuku', 'mal', 'malzeme', 'urun', 'esya', 'parca', 'koli', 'palet', 'adet', 'ton', 'kg', 'cesitli', 'muhtelif', 'genel', 'karisik', 'diger'];

    /**
     * Yapay zeka sonucunu kural sonucuyla karşılaştırır; farkları öneri yapar. Yapılan öneri sayısını döndürür.
     *
     * @param  array<string, mixed>  $rule  kuralın (parseCheap ya da kayıtlı ilanın) alanları: pickup_location, delivery_location, goods_type
     * @param  array<string, mixed>|null  $ai  yapay zekanın seçilmiş ilanı (pickAd sonrası)
     */
    public function fromAi(array $rule, ?array $ai, string $text, ?int $loadId = null, string $origin = 'çözümleme'): int
    {
        if ($ai === null || ($ai['provider'] ?? null) === 'template' || ($ai['is_load'] ?? true) === false) {
            return 0;
        }
        $made = 0;
        try {
            // Mesajda birden çok ilan varsa hangi çiftin hangi ilana ait olduğu belirsizdir: konum önerisi yapılmaz.
            $multi = ! empty($ai['multiple_loads']) || (int) ($ai['ad_count'] ?? 1) > 1;
            $pair = $multi ? null : LearningService::routePairFor($text, $ai['pickup_location'] ?? null, $ai['delivery_location'] ?? null);
            foreach ($multi ? [] : ['pickup', 'delivery'] as $side) {
                $aiLabel = $ai[$side.'_location'] ?? null;
                $target = is_string($aiLabel) && $aiLabel !== '' ? TurkishLocations::resolve($aiLabel) : null;
                if ($target === null) {
                    continue;
                }
                $ruleText = $pair[$side] ?? ($rule[$side.'_location'] ?? null);
                if (! is_string($ruleText) || trim($ruleText) === '' || LearningService::isNoiseTerm(Lexicon::normalize($ruleText))) {
                    continue;
                }
                if (TurkishLocations::resolveCatalog($ruleText) !== null) {
                    continue; // katalogda bilinen il/ilçe adı: yapay zeka farklı okusa da öneri olmaz, katalog yeniden öğretilmez
                }
                $resolved = TurkishLocations::resolve($ruleText);
                if ($resolved !== null && (int) $resolved['province_code'] === (int) $target['province_code']) {
                    continue; // kural zaten aynı ili okuyor
                }
                $note = $resolved !== null ? 'kural '.$resolved['province'].' okuyor, yapay zeka '.$target['province'] : 'kural çözemedi';
                $made += $this->suggest('location', $ruleText, self::locationCanonical($target), $text, $loadId, $origin.' · '.$note) ? 1 : 0;
            }
            $goodsKey = $ai['goods_category'] ?? null;
            $goodsText = $ai['goods_text'] ?? null;
            if (is_string($goodsKey) && GoodsCatalog::label($goodsKey) !== null && is_string($goodsText) && empty($rule['goods_type'])) {
                $norm = Lexicon::normalize($goodsText);
                if ($norm !== '' && mb_strlen($norm) >= 4 && ! in_array($norm, self::GENERIC_GOODS, true) && GoodsCatalog::detect(' '.$norm.' ') === null && Lexicon::matchGoods($norm) === null) {
                    $made += $this->suggest('goods', $goodsText, $goodsKey, $text, $loadId, $origin.' · kural yükü bulamadı') ? 1 : 0;
                }
            }
        } catch (Throwable) {
        }

        return $made;
    }

    /**
     * Öneri kaydeder ya da sayacını artırır. Aktif sözlük girdisi olan ya da "yok say" denmiş yazımlar bir daha önerilmez.
     * Eşik dolduysa kendiliğinden onaylanır.
     */
    public function suggest(string $kind, string $rawTerm, string $canonical, string $sample, ?int $loadId = null, ?string $note = null): bool
    {
        $term = Lexicon::normalize($rawTerm);
        $words = $term === '' ? 0 : count(explode(' ', $term));
        if (mb_strlen($term) < 3 || mb_strlen($term) > 60 || $words > 3 || preg_match('/^\d+$/', $term) === 1 || Lexicon::normalize($canonical) === $term
            || in_array($term, TurkishCities::STOP_WORDS, true) || in_array($term, AiParserService::PLACE_NOISE, true)) {
            return false;
        }
        if ($kind === 'location' && TurkishLocations::resolveCatalog($rawTerm) !== null) {
            return false; // bilinen il/ilçe adı hiçbir karşılığa bağlanamaz (katalog her zaman önce)
        }
        if (AiLexicon::query()->where('kind', $kind)->where('term', $term)->where('status', 'active')->exists()) {
            return false; // karar verilmiş: yönetici ya da öğrenilmiş girdi geçerli
        }
        $row = AiLexicon::query()->where('kind', $kind)->where('term', $term)->whereIn('status', ['suggested', 'ignored'])->first();
        if ($row !== null && $row->status === 'ignored') {
            return false;
        }
        $sample = mb_substr($sample, 0, 300);
        $note = $note !== null ? mb_substr($note, 0, 200) : null;
        if ($row === null) {
            $row = new AiLexicon(['kind' => $kind, 'term' => $term, 'canonical' => $canonical, 'status' => 'suggested', 'source' => self::SOURCE, 'hits' => 1, 'sample' => $sample, 'note' => $note, 'last_load_id' => $loadId]);
        } else {
            if ((string) $row->canonical !== $canonical) {
                // Aynı yazıma bu kez başka karşılık: sayaç başa döner, kendiliğinden onaylanmaz.
                $row->fill(['canonical' => $canonical, 'hits' => 1, 'note' => mb_substr(($note ?? '').' · önceki karşılık: '.$row->canonical, 0, 200)]);
            } elseif ($loadId === null || (int) $row->last_load_id !== (int) $loadId) {
                $row->hits = (int) $row->hits + 1;
                $row->note = $note;
            }
            $row->sample = $sample;
            $row->last_load_id = $loadId ?? $row->last_load_id;
        }
        // Kendiliğinden onay: konum katalog korumalıdır (bilinen ad asla başka yere bağlanmaz), yük sözcüğü genel/kısa olamaz (fromAi süzer).
        // Konum önerisi hiç kendiliğinden onaylanmaz: 2026-10-09 yayın dökümünde "yüklemeli → Torbalı", "açık → Tavas", "teker → Araç" gibi
        // kendiliğinden onaylanmış takma adlar 1.500 yayındaki ilana hayali il yazmıştı; konum yalnız yönetici onayıyla sözlüğe girer.
        $threshold = Settings::int('ai_suggest_auto_approve_hits');
        if ($threshold > 0 && $kind !== 'location' && (int) $row->hits >= $threshold) {
            $row->status = 'active';
            $row->note = mb_substr('kendiliğinden onaylandı ('.$row->hits.' ilan) · '.($note ?? ''), 0, 200);
        }
        $row->save();
        if ($row->status === 'active') {
            Lexicon::flush();
        }

        return true;
    }

    /** Öneriyi onaylar: sözlüğe girer; aynı sözcüğün eski girdisi silinir. Sözcük verilmezse önerideki kullanılır. */
    public function approve(AiLexicon $row, ?string $term = null, ?int $adminId = null): bool
    {
        $term = Lexicon::normalize((string) ($term ?? $row->term ?? ''));
        if ($row->status !== 'suggested' || mb_strlen($term) < 2) {
            return false;
        }
        if ($row->kind === 'location' && TurkishLocations::resolveCatalog($term) !== null) {
            $row->update(['status' => 'ignored', 'note' => mb_substr('katalogda bilinen ad; takma ad olamaz · '.($row->note ?? ''), 0, 200)]);

            return false; // tek dokunuşla bile "ankara → başka yer" öğretilemez
        }
        AiLexicon::query()->where('kind', $row->kind)->where('term', $term)->where('id', '!=', $row->id)->delete();
        $row->update(['term' => $term, 'status' => 'active', 'created_by' => $adminId ?? $row->created_by, 'note' => mb_substr('yönetici onayladı · '.($row->note ?? ''), 0, 200)]);
        Lexicon::flush();

        return true;
    }

    /** "Yok say": öneri silinmez, bir daha önerilmez. */
    public function ignore(AiLexicon $row): void
    {
        if ($row->status === 'suggested') {
            $row->update(['status' => 'ignored']);
        }
    }

    /**
     * Denetim: kuralla çözülmüş (yapay zeka bakmamış) ilanlardan rastgele bir örneklemi yapay zekaya sorar; uyuşmazlıkları
     * ilanın kaydına yazar ve öneri yapar. İlanın alanları değişmez. Kota/ağ hatasında durur.
     *
     * @return array{checked:int, mismatched:int, skipped:bool}
     */
    public function audit(int $limit, ?AiParserService $parser = null): array
    {
        $parser ??= app(AiParserService::class);
        if ($limit <= 0 || ! $parser->isEnabled() || ! $parser->isConfigured()) {
            return ['checked' => 0, 'mismatched' => 0, 'skipped' => true];
        }
        $loads = ScrapedLoad::query()
            ->where('status', 'parsed_success')
            ->where(fn ($q) => $q->whereNull('ai_status')->orWhereIn('ai_status', ['skipped', 'failed']))
            ->whereNull('parse_metadata->audit')
            ->whereNull('parse_metadata->admin_edited')
            ->where('created_at', '>=', now()->subDays(3))
            ->inRandomOrder()->limit($limit)->get();
        $checked = 0;
        $mismatched = 0;
        foreach ($loads as $load) {
            $raw = (string) $load->raw_message;
            $ai = $parser->enrich($raw, [], false);
            if ($ai['data'] === null) {
                break; // kota ya da ağ: bugünlük yeter, yarın devam
            }
            $ad = AiParserService::pickAd($ai['data'], $load->plainPhone(), $load->pickup_location, $load->delivery_location);
            $diff = $this->compare($load, $ad);
            $checked++;
            if ($diff !== []) {
                $mismatched++;
                $this->fromAi(['pickup_location' => $load->pickup_location, 'delivery_location' => $load->delivery_location, 'goods_type' => $load->goods_type], $ad, $raw, $load->id, 'denetim');
            }
            $meta = (array) ($load->parse_metadata ?? []);
            $meta['audit'] = ['at' => now()->toDateTimeString(), 'agree' => $diff === [], 'diff' => $diff, 'provider' => $ad['provider'] ?? null];
            $load->timestamps = false;
            $load->forceFill(['parse_metadata' => $meta])->save();
            $load->timestamps = true;
        }
        $this->bump('checked', $checked);
        $this->bump('mismatched', $mismatched);

        return ['checked' => $checked, 'mismatched' => $mismatched, 'skipped' => false];
    }

    /**
     * Kayıtlı ilan ile yapay zekanın okuduğu arasındaki farklar (alan → açıklama).
     *
     * @return array<string, string>
     */
    public function compare(ScrapedLoad $load, array $ad): array
    {
        $diff = [];
        if (($ad['is_load'] ?? true) === false && (float) ($ad['confidence'] ?? 0) >= 0.8) {
            $diff['is_load'] = 'yapay zeka: yük ilanı değil';
        }
        foreach (['pickup' => 'Kalkış', 'delivery' => 'Varış'] as $side => $label) {
            $t = is_string($ad[$side.'_location'] ?? null) ? TurkishLocations::resolve($ad[$side.'_location']) : null;
            $code = (int) $load->{$side.'_province_code'};
            if ($t !== null && $code > 0 && (int) $t['province_code'] !== $code) {
                $diff[$side] = $label.': kural '.$load->{$side.'_location'}.' · yapay zeka '.self::locationCanonical($t);
            }
        }
        $aiGoods = is_string($ad['goods_category'] ?? null) ? GoodsCatalog::label($ad['goods_category']) : null;
        if ($aiGoods !== null && (string) $load->goods_type !== '' && $load->goods_type !== $aiGoods) {
            $diff['goods'] = 'Yük: kural '.$load->goods_type.' · yapay zeka '.$aiGoods;
        } elseif ($aiGoods !== null && (string) $load->goods_type === '') {
            $diff['goods'] = 'Yük: kural bulamadı · yapay zeka '.$aiGoods;
        }
        $aiVehicle = $ad['vehicle_type'] ?? null;
        if (is_string($aiVehicle) && $load->vehicle_type && $load->vehicle_type_source === 'keyword' && $aiVehicle !== $load->vehicle_type) {
            $diff['vehicle'] = 'Araç: kural '.VehicleTypes::label($load->vehicle_type).' · yapay zeka '.VehicleTypes::label($aiVehicle);
        }

        return $diff;
    }

    /**
     * Bu haftanın sayaçları (sağlık ekranı): kuralla çözülen, yapay zeka gereken, denetlenen / uyuşmayan, bekleyen öneri,
     * kendiliğinden onaylanan.
     *
     * @return array{rule:int, ai:int, audited:int, mismatched:int, pending:int, auto_approved:int}
     */
    public function weeklyStats(): array
    {
        $since = now()->startOfWeek();
        $base = fn () => ScrapedLoad::withTrashed()->where('created_at', '>=', $since)->where('status', '!=', 'rejected');

        return [
            'rule' => $base()->where(fn ($q) => $q->whereNull('ai_status')->orWhereIn('ai_status', ['skipped', 'failed']))->count(),
            'ai' => $base()->whereIn('ai_status', ['done', 'pending'])->count(),
            'audited' => (int) Cache::get($this->counterKey('checked'), 0),
            'mismatched' => (int) Cache::get($this->counterKey('mismatched'), 0),
            'pending' => AiLexicon::query()->where('status', 'suggested')->count(),
            'auto_approved' => AiLexicon::query()->where('status', 'active')->where('source', self::SOURCE)->where('note', 'like', 'kendiliğinden%')->where('updated_at', '>=', $since)->count(),
        ];
    }

    /** @param  array{province:string, district:?string}  $place */
    public static function locationCanonical(array $place): string
    {
        return (string) TurkishLocations::label($place);
    }

    private function counterKey(string $name): string
    {
        return 'ai:audit:'.now()->format('o-W').':'.$name;
    }

    private function bump(string $name, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $key = $this->counterKey($name);
        Cache::add($key, 0, now()->addDays(21));
        Cache::increment($key, $by);
    }
}
