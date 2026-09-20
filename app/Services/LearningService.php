<?php

namespace App\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Throwable;

/**
 * Yönetici kararlarından öğrenme: yayınla/reddet kararı sınıflandırıcıyı eğitir; il düzeltmeleri konum sözlüğüne
 * girer; araç/yük düzeltmeleri "öneri" olarak sözlük ekranına düşer (yönetici hangi sözcüğün öğretileceğini seçer).
 */
class LearningService
{
    public function __construct(private readonly LocalClassifier $classifier) {}

    public function onApproved(ScrapedLoad $load): void
    {
        try {
            $this->classifier->train((string) $load->raw_message, true);
            $this->learnLocations((string) $load->raw_message, $load->pickup_location, $load->delivery_location, $load->id);
        } catch (Throwable) {
        }
    }

    public function onRejected(ScrapedLoad $load): void
    {
        try {
            $this->classifier->train((string) $load->raw_message, false);
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
        $matches = AiParserService::connectorMatches($raw);
        if ($matches === []) {
            return;
        }
        $first = $matches[0];
        foreach ([['text' => $first['pickup'], 'label' => $pickupLabel], ['text' => $first['delivery'], 'label' => $deliveryLabel]] as $pair) {
            $text = $pair['text'];
            $label = $pair['label'];
            if (! is_string($text) || $text === '' || ! is_string($label) || $label === '') {
                continue;
            }
            $term = Lexicon::normalize($text);
            if ($term === '' || mb_strlen($term) < 3 || str_word_count($term) > 3) {
                continue;
            }
            $target = TurkishLocations::resolve($label);
            if ($target === null) {
                continue; // öğretilecek etiketin kendisi katalogda yoksa sözlüğe girmez
            }
            $resolved = TurkishLocations::resolve($text);
            if ($resolved !== null && $resolved['province_code'] === $target['province_code']) {
                continue; // zaten doğru çözülüyor
            }
            if (Lexicon::normalize($label) === $term) {
                continue;
            }
            $canonical = $target['province'].(($target['district'] ?? null) && $target['district'] !== 'Merkez' ? ' '.$target['district'] : '');
            $row = AiLexicon::query()->firstOrNew(['kind' => 'location', 'term' => $term]);
            if ($row->exists && $row->source === 'admin') {
                continue; // yöneticinin girdiği tanım korunur
            }
            $row->fill(['canonical' => $canonical, 'status' => 'active', 'source' => $row->exists ? $row->source : 'learned', 'hits' => (int) $row->hits + 1,
                'sample' => mb_substr($raw, 0, 300), 'created_by' => $row->created_by ?? $adminId])->save();
        }
        Lexicon::flush();
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
