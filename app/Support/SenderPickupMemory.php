<?php

namespace App\Support;

use App\Models\ScrapedLoad;
use App\Models\SenderPickup;
use App\Services\TemplateMemory;

/**
 * Gönderen hafızası (okuma katmanı "sender_pickup_memory"). Kalkışı yazmayan varış listelerinde ("SAMSUN KAPALI TIR ⏎ İZMİR
 * KAPALI TIR ⏎ …") kalkış, gönderen numaranın bilinen kalkışıdır:
 *  1. Yöneticinin "Kalkış öğret" ile yazdığı (sender_pickups, source=admin) — en güvenilir;
 *  2. Son 30 günde aynı numaradan yayınlanan ilanların kalkışı hep aynı ilse (en az 3 ilan, %80) o il (+ ilçe tekse ilçe).
 * Numaralar düz saklanmaz; şablon hafızasıyla aynı özet (TemplateMemory::phoneHash) kullanılır. route_key "numara|…" ile
 * başladığından geçmiş ilanlar o ön ekle bulunur.
 */
class SenderPickupMemory
{
    public const HISTORY_DAYS = 30;

    public const HISTORY_MIN_ADS = 3;

    public const HISTORY_SHARE = 0.8;

    /**
     * @param  list<string>  $phones
     * @return array{label:string, province_code:?int, district:?string, source:string}|null
     */
    public function pickupFor(array $phones): ?array
    {
        foreach ($phones as $phone) {
            $taught = SenderPickup::query()->where('phone_hash', TemplateMemory::phoneHash($phone))->first();
            if ($taught !== null) {
                $taught->forceFill(['hits' => $taught->hits + 1, 'last_used_at' => now()])->saveQuietly();

                return ['label' => $taught->pickup_label, 'province_code' => $taught->province_code, 'district' => $taught->district, 'source' => $taught->source];
            }
        }
        foreach ($phones as $phone) {
            if (($history = $this->fromHistory($phone)) !== null) {
                return $history;
            }
        }

        return null;
    }

    /** Yönetici öğretti: kalkış metni kataloğa göre çözülür; çözülmezse null (öğretilmez). */
    public function teach(string $phone, string $pickupText, ?int $userId = null): ?SenderPickup
    {
        $resolved = TurkishLocations::resolve(trim($pickupText));
        if ($resolved === null) {
            return null;
        }
        $label = TurkishLocations::label($resolved) ?? trim($pickupText);

        return SenderPickup::query()->updateOrCreate(['phone_hash' => TemplateMemory::phoneHash($phone)], [
            'pickup_label' => $label, 'province_code' => $resolved['province_code'] ?? null, 'district' => $resolved['district'] ?? null,
            'source' => 'admin', 'taught_by' => $userId,
        ]);
    }

    /** @return array{label:string, province_code:?int, district:?string, source:string}|null */
    private function fromHistory(string $phone): ?array
    {
        $rows = ScrapedLoad::query()->where('route_key', 'like', $phone.'|%')
            ->where('status', '!=', 'rejected')->where('created_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->whereNotNull('pickup_province_code')->whereNull('parse_metadata->route_inferred')
            ->orderByDesc('id')->limit(60)->get(['pickup_province_code', 'pickup_district', 'pickup_location']);
        if ($rows->count() < self::HISTORY_MIN_ADS) {
            return null;
        }
        $byProvince = $rows->groupBy('pickup_province_code');
        $top = $byProvince->sortByDesc(fn ($g) => $g->count())->first();
        if ($top === null || $top->count() / $rows->count() < self::HISTORY_SHARE) {
            return null;
        }
        $districts = $top->pluck('pickup_district')->filter()->unique();
        $district = $districts->count() === 1 && $top->filter(fn ($l) => $l->pickup_district !== null)->count() === $top->count() ? $districts->first() : null;
        $province = TurkishLocations::province((int) $top->first()->pickup_province_code)['name'] ?? null;
        if ($province === null) {
            return null;
        }

        return ['label' => trim($province.' '.($district ?? '')), 'province_code' => (int) $top->first()->pickup_province_code, 'district' => $district, 'source' => 'history'];
    }
}
