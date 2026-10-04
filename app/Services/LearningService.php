<?php

namespace App\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Throwable;

/**
 * Yönetici kararlarından öğrenme: yayınla/reddet kararı sınıflandırıcıyı eğitir; il düzeltmeleri konum sözlüğüne
 * girer; araç/yük düzeltmeleri "öneri" olarak sözlük ekranına düşer (yönetici hangi sözcüğün öğretileceğini seçer).
 */
class LearningService
{
    public function __construct(private readonly LocalClassifier $classifier, private readonly TemplateMemory $templates) {}

    /**
     * @param  bool  $byAdmin  yöneticinin tek tek verdiği karar (otomatik yayın değil)
     * @param  bool  $bulk  toplu "Yayınla": yönetici her ilanı tek tek incelememiştir; konum/kalıp öğrenilmez
     */
    public function onApproved(ScrapedLoad $load, bool $byAdmin = true, bool $bulk = false): void
    {
        try {
            // Sınıflandırıcı yalnız güvenilir örnekten öğrenir: yönetici kararı ya da yapay zekanın çelişkisiz, yüksek güvenli çözümü.
            // Eksik bilgili ilan ve kural puanıyla yayınlanan aday "kesin ilan" örneği değildir.
            $aiSure = $load->ai_status === 'done' && (float) $load->parse_confidence >= 0.8 && empty($load->meta('ai_conflict'));
            if (! $load->is_incomplete && ($byAdmin || $aiSure)) {
                $this->classifier->train((string) $load->raw_message, true);
            }
            // Konum sözlüğü yalnız yönetici onayından öğrenir: otomatik yayından öğrenmek kendi kendini besleyen bir döngüydü
            // (yanlış çözülen ilan yayınlanır → yanlış takma ad öğrenilir → sonraki ilanlar da yanlış çözülür).
            if ($byAdmin && ! $bulk) {
                $this->learnLocations((string) $load->raw_message, $load->pickup_location, $load->delivery_location, $load->id);
            }
            // Yönetici onayı en güçlü doğrulamadır: gönderenin kalıbı öğrenilir (sonraki aynı kalıp yapay zekasız okunur).
            $phone = $load->plainPhone();
            if ($byAdmin && ! $bulk && $phone && $load->pickup_province_code && $load->delivery_province_code) {
                $goodsKey = array_search((string) $load->goods_type, GoodsCatalog::labels(), true);
                $this->templates->learn($phone, (string) $load->raw_message, $load->pickup_location, $load->delivery_location, $load->vehicle_type, $goodsKey !== false ? (string) $goodsKey : null, true, 1.0, $load->id);
            }
        } catch (Throwable) {
        }
    }

    public function onRejected(ScrapedLoad $load): void
    {
        try {
            // Yalnız "ilan değil" kararı olumsuz örnektir; tekrar / eski / yanlış rota gerekçeli ret ya da kendiliğinden ret öğretmez.
            if (LocalClassifier::isNegativeExample($load)) {
                $this->classifier->train((string) $load->raw_message, false);
            }
            $templateId = (int) (((array) $load->meta('ai', []))['template_id'] ?? 0);
            if ($templateId > 0) {
                $this->templates->forget($templateId); // kalıp yanlış çözmüş: bir daha uygulanmasın
            }
        } catch (Throwable) {
        }
    }

    /**
     * Yönetici satır içi düzenleme yaptı: eskisiyle yenisini karşılaştırıp öğrenir.
     *
     * @param  array{pickup_location:?string, delivery_location:?string, pickup_province_code:mixed, delivery_province_code:mixed, vehicle_type:?string, goods_type:?string}  $before
     */
    public function onEdited(ScrapedLoad $load, array $before, ?int $adminId = null): void
    {
        try {
            $raw = (string) $load->raw_message;
            foreach (['pickup', 'delivery'] as $side) {
                if ((int) ($before[$side.'_province_code'] ?? 0) !== (int) $load->{$side.'_province_code'} || empty($before[$side.'_province_code'])) {
                    $this->learnLocations($raw, $side === 'pickup' ? $load->pickup_location : null, $side === 'delivery' ? $load->delivery_location : null, $load->id, $adminId);
                }
            }
            if ($load->vehicle_type && $load->vehicle_type !== ($before['vehicle_type'] ?? null) && VehicleTypes::isValid($load->vehicle_type)) {
                $this->suggest('vehicle', $load->vehicle_type, $raw, $adminId);
            }
            if ($load->goods_type && $load->goods_type !== ($before['goods_type'] ?? null)) {
                $key = array_search($load->goods_type, GoodsCatalog::labels(), true);
                if ($key !== false) {
                    $this->suggest('goods', (string) $key, $raw, $adminId);
                }
            }
        } catch (Throwable) {
        }
    }

    /**
     * Ham mesajdaki rota parçalarından katalogda çözülemeyeni, kesinleşen il/ilçe etiketine bağlar
     * ("Ostim" → "Ankara Ostim", "Gebze OSB" → "Kocaeli Gebze"). Sonraki mesajlarda kural doğrudan çözer.
     */
    public function learnLocations(string $raw, ?string $pickupLabel, ?string $deliveryLabel, ?int $loadId = null, ?int $adminId = null): void
    {
        $pair = self::routePairFor($raw, $pickupLabel, $deliveryLabel);
        if ($pair === null) {
            return;
        }
        foreach ([['text' => $pair['pickup'], 'label' => $pickupLabel], ['text' => $pair['delivery'], 'label' => $deliveryLabel]] as $side) {
            $text = $side['text'];
            $label = $side['label'];
            if (! is_string($text) || $text === '' || ! is_string($label) || $label === '') {
                continue;
            }
            $term = Lexicon::normalize($text);
            if ($term === '' || mb_strlen($term) < 3 || str_word_count($term) > 3 || self::isNoiseTerm($term)) {
                continue;
            }
            $target = TurkishLocations::resolve($label);
            if ($target === null) {
                continue; // öğretilecek etiketin kendisi katalogda yoksa sözlüğe girmez
            }
            if (TurkishLocations::resolveCatalog($text) !== null) {
                continue; // katalogda bilinen bir il/ilçe adı: hangi etikete bağlanırsa bağlansın yeniden öğretilmez ("ankara" asla başka yer olamaz)
            }
            $resolved = TurkishLocations::resolve($text);
            if ($resolved !== null && $resolved['province_code'] === $target['province_code']) {
                continue; // zaten doğru çözülüyor
            }
            if (Lexicon::normalize($label) === $term) {
                continue;
            }
            $canonical = TurkishLocations::label($target);
            $row = AiLexicon::query()->firstOrNew(['kind' => 'location', 'term' => $term]);
            if ($row->exists && $row->source === 'admin') {
                continue; // yöneticinin girdiği tanım korunur
            }
            $row->fill(['canonical' => $canonical, 'status' => 'active', 'source' => $row->exists ? $row->source : 'learned', 'hits' => (int) $row->hits + 1,
                'sample' => mb_substr($raw, 0, 300), 'created_by' => $row->created_by ?? $adminId])->save();
        }
        Lexicon::flush();
    }

    /**
     * Mesajdaki bağlaç çiftlerinden kesinleşen rotaya ait olanı seçer: bir ucu doğru ile çözülen, diğer ucu öğrenilecek
     * (çözülmeyen ya da farklı ile giden) çift. "ÇOK ACİL - KONYA - MERSİN" gibi başlık çiftleri ("çok" → "acil") elenir;
     * iki ucu da doğru çözülen çiftten öğrenecek bir şey yoktur, iki ucu da tutmayan çift rota değildir.
     *
     * @return array{pickup:?string, delivery:?string}|null
     */
    public static function routePairFor(string $raw, ?string $pickupLabel, ?string $deliveryLabel): ?array
    {
        $want = [
            'pickup' => is_string($pickupLabel) ? (TurkishLocations::resolve($pickupLabel)['province_code'] ?? null) : null,
            'delivery' => is_string($deliveryLabel) ? (TurkishLocations::resolve($deliveryLabel)['province_code'] ?? null) : null,
        ];
        foreach (AiParserService::connectorMatches($raw) as $m) {
            $ok = 0;
            foreach (['pickup', 'delivery'] as $side) {
                $code = is_string($m[$side]) ? (TurkishLocations::resolve($m[$side])['province_code'] ?? null) : null;
                if ($want[$side] !== null && $code === $want[$side]) {
                    $ok++;
                }
            }
            if ($ok >= 1) {
                return $m; // en az bir ucu kesin rotayla örtüşüyor: bu çift rotanın kendisidir
            }
        }
        // Yalnız bir uç biliniyor (yönetici tek tarafı düzeltti) ve hiçbir çift onunla örtüşmüyor: o ucu çözülemeyen,
        // diğer ucu bir yere çözülen ilk çift öğrenme adayıdır ("Büsan sanayi - İzmir": "Büsan" → Konya).
        foreach (AiParserService::connectorMatches($raw) as $m) {
            foreach (['pickup' => 'delivery', 'delivery' => 'pickup'] as $side => $other) {
                if ($want[$side] !== null && $want[$other] === null && is_string($m[$side]) && TurkishLocations::resolve($m[$side]) === null
                    && is_string($m[$other]) && TurkishLocations::resolve($m[$other]) !== null) {
                    return $m;
                }
            }
        }

        return null;
    }

    /** Takma ad olamayacak sözcük: gündelik/rol sözcüğü, firma eki, kişi hitabı ("çok", "acil", "lojistik", "bey"). */
    public static function isNoiseTerm(string $normalizedTerm): bool
    {
        $words = preg_split('/\s+/', $normalizedTerm) ?: [];
        $noise = array_merge(TurkishCities::STOP_WORDS, AiParserService::PLACE_NOISE, AiParserService::COMPANY_WORDS, AiParserService::PERSON_TITLES,
            ['cok', 'acil', 'acill', 'ivedi', 'var', 'yok', 'yuk', 'yukler', 'bos', 'dolu', 'araba', 'arac', 'tir', 'kamyon', 'kamyonet', 'ton', 'adet', 'fiyat', 'ucret', 'navlun', 'kdv']);
        foreach ($words as $w) {
            if (in_array(TurkishCities::ascii($w), array_map([TurkishCities::class, 'ascii'], $noise), true)) {
                return true;
            }
        }

        return false;
    }

    /** Sözlük ekranına öneri düşürür: yönetici sözcüğü yazıp onaylar. Aynı örnek/karşılık tekrarlanmaz. */
    public function suggest(string $kind, string $canonical, string $raw, ?int $adminId = null): void
    {
        $sample = mb_substr($raw, 0, 300);
        $exists = AiLexicon::query()->where('kind', $kind)->where('canonical', $canonical)->where('sample', $sample)->exists();
        if ($exists) {
            return;
        }
        AiLexicon::create(['kind' => $kind, 'term' => null, 'canonical' => $canonical, 'status' => 'suggested', 'source' => 'learned', 'sample' => $sample, 'created_by' => $adminId]);
    }
}
