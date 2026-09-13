<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Yönetim panelinden girilen zengin metni izin listesiyle temizler (sözleşmeler, sayfalar).
 */
final class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('framework/cache'));
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', 'div[class],span[class],p[class],br,strong,b,em,i,u,h1[class],h2[class],h3[class],h4[class],ul[class],ol[class],li[class],a[href|class|rel|target],table[class],thead,tbody,tr,th[class],td[class],blockquote[class],hr');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('AutoFormat.RemoveEmpty', false);

        return self::$purifier = new HTMLPurifier($config);
    }
}
