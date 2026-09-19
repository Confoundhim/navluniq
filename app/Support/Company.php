<?php

namespace App\Support;

use App\Models\CmsContent;

/**
 * Şirket künyesi (unvan, adres, vergi bilgileri, iletişim).
 * Öncelik: yönetim paneli (Sistem Ayarları → Ödeme altyapısı) → sunucu .env (COMPANY_*) → koddaki varsayılanlar.
 * Böylece sunucuda dosya düzenlemeden panelden yönetilir; hiçbir şey girilmemişse bile künye boş kalmaz.
 */
final class Company
{
    public const LABELS = [
        'name' => 'Şirket unvanı',
        'address' => 'Adres',
        'phone' => 'Telefon',
        'email' => 'E-posta',
        'tax_office' => 'Vergi dairesi',
        'tax_no' => 'Vergi numarası',
        'mersis_no' => 'MERSİS numarası',
        'trade_registry_no' => 'Ticaret sicil numarası',
    ];

    public const DEFAULTS = [
        'name' => 'NAVLUNIQ TEKNOLOJİ LİMİTED ŞİRKETİ',
        'address' => 'Cevizlidere Mah. Mevlana Blv. No: 221 İç Kapı No: 109 Çankaya / Ankara',
        'phone' => '+90 850 304 04 00',
        'email' => 'info@navluniq.com',
        'tax_office' => 'Başkent Vergi Dairesi',
        'tax_no' => '6301483181',
        'mersis_no' => '0630148318100001',
        'trade_registry_no' => '547805',
    ];

    /** Sözleşme metinlerinde kullanılan yer tutucular → künye anahtarı. */
    public const TOKENS = [
        '{{COMPANY_NAME}}' => 'name',
        '{{COMPANY_TAX_OFFICE}}' => 'tax_office',
        '{{COMPANY_TAX_NO}}' => 'tax_no',
        '{{COMPANY_ADDRESS}}' => 'address',
        '{{COMPANY_EMAIL}}' => 'email',
        '{{COMPANY_PHONE}}' => 'phone',
        '{{COMPANY_MERSIS_NO}}' => 'mersis_no',
        '{{COMPANY_TRADE_REGISTRY_NO}}' => 'trade_registry_no',
    ];

    public static function get(string $key): string
    {
        $panel = trim((string) CmsContent::getVal('company_'.$key, ''));
        if ($panel !== '') {
            return $panel;
        }

        $env = trim((string) config('company.'.$key, ''));
        if ($env !== '') {
            return $env;
        }

        return self::DEFAULTS[$key] ?? '';
    }

    /** Panel boşken geçerli olacak değer (.env ya da koddaki varsayılan). */
    public static function fallback(string $key): string
    {
        $env = trim((string) config('company.'.$key, ''));

        return $env !== '' ? $env : (self::DEFAULTS[$key] ?? '');
    }

    /** Panelde saklanan (ham) değer; varsayılan ya da .env değerini içermez. */
    public static function stored(string $key): string
    {
        return trim((string) CmsContent::getVal('company_'.$key, ''));
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    /** Sözleşme metnindeki {{COMPANY_*}} yer tutucularını güncel künye ile doldurur (HTML kaçışlı). */
    public static function fillTokens(?string $html): string
    {
        $html = (string) $html;
        if (! str_contains($html, '{{COMPANY_')) {
            return $html;
        }

        $map = [];
        foreach (self::TOKENS as $token => $key) {
            $value = self::get($key);
            $map[$token] = e($value !== '' ? $value : '—');
        }

        return strtr($html, $map);
    }
}
