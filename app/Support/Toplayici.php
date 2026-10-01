<?php

namespace App\Support;

/**
 * NavlunIQ Toplayıcı: telefona kurulan kendi küçük Android uygulamamız (android/toplayici). WhatsApp grup bildirimlerini ve
 * Facebook'ta ekranda görünen gönderileri sunucuya iletir; MacroDroid'in yerini alır. APK depoda public/toplayici/ altında
 * durur (android/build.sh üretir); anahtar içinde değildir, kullanıcı uygulamaya yapıştırır. Bu sınıf sürüm bilgisini okur.
 */
final class Toplayici
{
    public const DIR = 'toplayici';

    public const FILE = 'navluniq-toplayici.apk';

    /** @return array{versionCode:int, versionName:string, url:string, bytes:int, builtAt:?string, available:bool} */
    public static function version(): array
    {
        $meta = [];
        $path = public_path(self::DIR.'/version.json');
        if (is_file($path)) {
            $meta = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $apk = public_path(self::DIR.'/'.self::FILE);

        return [
            'versionCode' => (int) ($meta['versionCode'] ?? 0),
            'versionName' => (string) ($meta['versionName'] ?? '0'),
            'url' => url('/'.self::DIR.'/'.self::FILE),
            'bytes' => (int) ($meta['bytes'] ?? (is_file($apk) ? filesize($apk) : 0)),
            'builtAt' => $meta['builtAt'] ?? null,
            'available' => is_file($apk),
        ];
    }
}
