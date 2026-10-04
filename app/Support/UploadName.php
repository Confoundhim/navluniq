<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/** Yüklenen dosyanın saklanacak uzantısı içerikten (MIME) türetilir; istemcinin yazdığı ad ("resim.php") dikkate alınmaz. */
final class UploadName
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'];

    public static function extension(UploadedFile $file): string
    {
        $guessed = strtolower((string) $file->guessExtension());
        if ($guessed === 'jpeg') {
            $guessed = 'jpg';
        }
        if (in_array($guessed, self::ALLOWED, true)) {
            return $guessed;
        }
        $client = strtolower((string) $file->getClientOriginalExtension());
        if ($client === 'jpeg') {
            $client = 'jpg';
        }

        return in_array($client, self::ALLOWED, true) ? $client : 'bin';
    }
}
