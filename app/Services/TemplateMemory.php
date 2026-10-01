<?php

namespace App\Services;

use App\Models\AiTemplate;
use App\Support\ForeignPlaces;
use App\Support\GoodsCatalog;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Throwable;

/**
 * Şablon hafızası. Yük gruplarındaki mesajların çoğu aynı komisyoncuların her gün aynı kalıpla attığı ilanlardır:
 * "📍 {yer} 📦 {yer} 💰 {n}+KDV 🚚 {n} araç ☎️ {tel}". Kalıp bir kez yapay zeka ya da yönetici tarafından doğrulanınca
 * aynı numaradan aynı kalıpla gelen sonraki ilanlar yapay zekasız çözülür: hangi yer adının kalkış, hangisinin varış
 * olduğu kalıptaki sırayla bilinir; sayılar (tonaj, fiyat) kuralla okunur.
 */
class TemplateMemory
{
    /**
     * Metnin kalıbı: yer adları "{yer}", sayılar "{n}", telefon "{tel}" olur; kalan sözcükler aynen kalır.
     *
     * @return array{hash:string, signature:string, locations:list<array{text:string, province_code:int, label:string}>}
     */
    public static function signature(string $text): array
    {
        $clean = preg_replace(AiParserService::PHONE_PATTERN, ' {tel} ', $text) ?? $text;
        $norm = LoadIntakeService::normalizeText($clean);
        $out = [];
        $locations = [];
        $words = array_values(array_filter(explode(' ', $norm), fn ($w) => $w !== ''));
        $skip = -1;
        foreach ($words as $i => $word) {
            if ($i <= $skip) {
                continue;
            }
            if ($word === 'tel') {
                $out[] = '{tel}';

                continue;
            }
            if (preg_match('/^\d+$/u', $word)) {
                $out[] = '{n}';

                continue;
            }
            if (mb_strlen($word) >= 3 && ! preg_match('/\d/u', $word) && ! in_array($word, AiParserService::PLACE_NOISE, true)) {
                // Yalnız birebir yazım: yakın eşleme "sinan" → Sincan, "saman" → Kaman gibi yer olmayan sözcükleri {yer} yapıyordu
                $resolved = TurkishCities::fromText($word, fuzzy: false) !== null ? TurkishLocations::resolve($word, false) : null;
                if ($resolved !== null && isset($words[$i + 1]) && TurkishCities::fromText($words[$i + 1], fuzzy: false) === null) {
                    // İl + ilçe tek yer ("ankara sincan"): iki ayrı {yer} olsaydı kalıp başka mesajda ilçeyi varış sanabilirdi
                    $pair = TurkishLocations::resolve($word.' '.$words[$i + 1], false);
                    if ($pair !== null && ($pair['district'] ?? null) !== null) {
                        $resolved = $pair;
                        $skip = $i + 1;
                    }
                }
                if ($resolved === null && mb_strlen($word) >= 4) {
                    $r = TurkishLocations::resolve($word, false);
                    $resolved = $r !== null && ($r['district'] ?? null) !== null ? $r : null; // tek başına ilçe adı
                    if ($resolved === null && ($f = ForeignPlaces::match($word)) !== null) {
                        $resolved = ['province' => $f['label'], 'district' => null, 'province_code' => 0];
                    }
                }
                if ($resolved !== null) {
                    $locations[] = ['text' => $word.($skip === $i + 1 ? ' '.$words[$i + 1] : ''), 'province_code' => (int) $resolved['province_code'], 'label' => (string) TurkishLocations::label($resolved)];
                    $out[] = '{yer}';

                    continue;
                }
            }
            $out[] = $word;
        }
        $signature = trim(implode(' ', $out));

        return ['hash' => hash('sha256', $signature), 'signature' => mb_substr($signature, 0, 500), 'locations' => $locations];
    }

    public static function phoneHash(string $phone): string
    {
        return hash('sha256', 'tpl|'.$phone);
    }

    public function find(string $phone, string $signatureHash): ?AiTemplate
    {
        try {
            return AiTemplate::query()->where('phone_hash', self::phoneHash($phone))->where('signature_hash', $signatureHash)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Doğrulanmış çözümden kalıp öğrenir. Kalkış/varış, kalıptaki yer adlarının kaçıncısı olduğuyla saklanır;
     * ikisi de bulunamazsa (yer adları katalogda değilse) kalıp yalnız "ilan / ilan değil" bilgisini taşır.
     */
    public function learn(string $phone, string $text, ?string $pickupLabel, ?string $deliveryLabel, ?string $vehicleType, ?string $goodsCategory, bool $isLoad, float $confidence, ?int $loadId = null): ?AiTemplate
    {
        $sig = self::signature($text);
        if (count(explode(' ', $sig['signature'])) < 3) {
            return null; // çok kısa kalıp: ayırt edici değil
        }
        if (! $isLoad && str_contains($sig['signature'], '{yer}')) {
            return null; // yer adı geçen bir kalıp "ilan değil" diye öğrenilmez: aynı kalıp başka gün gerçek ilan olabilir
        }
        $indexOf = function (?string $label) use ($sig): ?int {
            $target = is_string($label) && $label !== '' ? TurkishLocations::resolve($label) : null;
            if ($target === null && is_string($label) && ($f = ForeignPlaces::match($label)) !== null) {
                foreach ($sig['locations'] as $i => $loc) {
                    if ($loc['province_code'] === 0 && $loc['label'] === $f['label']) {
                        return $i;
                    }
                }

                return null;
            }
            if ($target === null) {
                return null;
            }
            $targetLabel = TurkishLocations::label($target);
            foreach ($sig['locations'] as $i => $loc) {
                if ($loc['province_code'] !== 0 && $loc['label'] === $targetLabel) {
                    return $i; // birebir aynı yer ("Kayseri Develi") önce; aynı ilin başka yeri sonra
                }
            }
            foreach ($sig['locations'] as $i => $loc) {
                if ($loc['province_code'] === (int) $target['province_code'] && $loc['province_code'] !== 0) {
                    return $i;
                }
            }

            return null;
        };
        $pickupIdx = $isLoad ? $indexOf($pickupLabel) : null;
        $deliveryIdx = $isLoad ? $indexOf($deliveryLabel) : null;
        if ($isLoad && ($pickupIdx === null || $deliveryIdx === null || $pickupIdx === $deliveryIdx)) {
            return null; // rota kalıptan çıkarılamıyorsa kalıp güvenilmez
        }
        try {
            $existing = $this->find($phone, $sig['hash']);
            if ($existing !== null && (float) $existing->confidence >= 1.0 && $confidence < 1.0) {
                return $existing; // yönetici onayıyla öğrenilmiş kalıp, yapay zekanın daha düşük güvenli çözümüyle ezilmez
            }

            return AiTemplate::updateOrCreate(
                ['phone_hash' => self::phoneHash($phone), 'signature_hash' => $sig['hash']],
                ['signature' => $sig['signature'], 'is_load' => $isLoad, 'pickup_index' => $pickupIdx, 'delivery_index' => $deliveryIdx,
                    'vehicle_type' => VehicleTypes::isValid($vehicleType) ? $vehicleType : null,
                    'goods_category' => $goodsCategory !== null && GoodsCatalog::label($goodsCategory) !== null ? $goodsCategory : null,
                    'confidence' => max(0.5, min(1.0, $confidence)), 'source_load_id' => $loadId]
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Kalıbı yeni metne uygular: kural sonucunun kalkış/varışını kalıptaki sıraya göre doldurur/düzeltir.
     *
     * @return array<string,mixed>|null uygulanamazsa null
     */
    public function apply(AiTemplate $template, array $signature, array $parsed): ?array
    {
        if (! $template->is_load) {
            return null;
        }
        $locations = $signature['locations'];
        if (! isset($locations[$template->pickup_index], $locations[$template->delivery_index])) {
            return null;
        }
        $tplPickup = $locations[$template->pickup_index];
        $tplDelivery = $locations[$template->delivery_index];
        if ($tplPickup['province_code'] === $tplDelivery['province_code'] && $tplPickup['province_code'] !== 0 && $tplPickup['label'] === $tplDelivery['label']) {
            return null; // kalıp iki ucu aynı yere düşürüyor: bu mesaja uymuyor
        }
        // Kural zaten iki ucu da çözdüyse kalıp onu ezmez; kalıp yalnız kuralın çözemediği ucu doldurur. Kural iki ucu aynı ile
        // düşürmüşse ("Kayseri → Kayseri Develi") ve kalıp iki farklı il veriyorsa kalıp kazanır.
        $ruleP = TurkishLocations::resolve($parsed['pickup_location'] ?? null);
        $ruleD = TurkishLocations::resolve($parsed['delivery_location'] ?? null);
        $ruleSame = $ruleP !== null && $ruleD !== null && $ruleP['province_code'] === $ruleD['province_code'];
        $tplDifferent = $tplPickup['province_code'] !== $tplDelivery['province_code'];
        if ($ruleP === null || $ruleD === null || ($ruleSame && $tplDifferent)) {
            $parsed['pickup_location'] = $ruleP !== null && ! ($ruleSame && $tplDifferent) ? $parsed['pickup_location'] : $tplPickup['label'];
            $parsed['delivery_location'] = $ruleD !== null && ! ($ruleSame && $tplDifferent) ? $parsed['delivery_location'] : $tplDelivery['label'];
        }
        if (empty($parsed['vehicle_type']) && $template->vehicle_type) {
            $parsed['vehicle_type'] = $template->vehicle_type;
            $parsed['vehicle_type_source'] = 'template';
        }
        if (empty($parsed['goods_type']) && $template->goods_category) {
            $parsed['goods_type'] = GoodsCatalog::label($template->goods_category);
        }
        $parsed['success'] = ! empty($parsed['sender_phone']);
        unset($parsed['reason']);
        $parsed['parsed_by_llm'] = 'template';
        $parsed['template_id'] = $template->id;
        try {
            $template->forceFill(['uses' => $template->uses + 1, 'last_used_at' => now()])->save();
        } catch (Throwable) {
        }

        return $parsed;
    }

    /** Yönetici bu kalıptan gelen bir adayı reddettiyse kalıp silinir (aynı hata tekrarlanmasın). */
    public function forget(int $templateId): void
    {
        try {
            AiTemplate::whereKey($templateId)->delete();
        } catch (Throwable) {
        }
    }
}
