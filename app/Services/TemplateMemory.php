<?php

namespace App\Services;

use App\Models\AiTemplate;
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
        foreach (explode(' ', $norm) as $word) {
            if ($word === '') {
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
            if (mb_strlen($word) >= 3 && ! preg_match('/\d/u', $word)) {
                $resolved = TurkishCities::fromText($word, fuzzy: false) !== null ? TurkishLocations::resolve($word) : null;
                if ($resolved === null && mb_strlen($word) >= 4) {
                    $r = TurkishLocations::resolve($word);
                    $resolved = $r !== null && ($r['district'] ?? null) !== null ? $r : null; // tek başına ilçe adı
                }
                if ($resolved !== null) {
                    $label = $resolved['province'].(($resolved['district'] ?? null) && $resolved['district'] !== 'Merkez' ? ' '.$resolved['district'] : '');
                    $locations[] = ['text' => $word, 'province_code' => (int) $resolved['province_code'], 'label' => $label];
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
        $indexOf = function (?string $label) use ($sig): ?int {
            $target = is_string($label) && $label !== '' ? TurkishLocations::resolve($label) : null;
            if ($target === null) {
                return null;
            }
            foreach ($sig['locations'] as $i => $loc) {
                if ($loc['province_code'] === (int) $target['province_code']) {
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
        $parsed['pickup_location'] = $locations[$template->pickup_index]['label'];
        $parsed['delivery_location'] = $locations[$template->delivery_index]['label'];
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
