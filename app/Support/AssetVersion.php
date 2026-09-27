<?php

namespace App\Support;

/**
 * Simge, logo gibi sabit dosyaların adresine dosya değişim zamanından türeyen bir sürüm eki (?v=…) koyar.
 * Tarayıcı bu dosyaları uzun süre önbellekte tutar; dosya güncellenince adres de değiştiği için yeni sürüm
 * anında görünür. Vite ile derlenen CSS/JS zaten adında özet taşır; bu sınıf onların dışındaki dosyalar içindir.
 */
final class AssetVersion
{
    /** @var array<string, string> */
    private static array $stamps = [];

    public static function url(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $path.(str_contains($path, '?') ? '&' : '?').'v='.self::stamp($path);
    }

    public static function stamp(string $path): string
    {
        if (! isset(self::$stamps[$path])) {
            $file = public_path(ltrim($path, '/'));
            $mtime = is_file($file) ? (int) filemtime($file) : 0;
            self::$stamps[$path] = $mtime > 0 ? substr(md5($path.'|'.$mtime), 0, 8) : '0';
        }

        return self::$stamps[$path];
    }
}
