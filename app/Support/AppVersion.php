<?php

namespace App\Support;

/** Sunucuda çalışan kodun sürümü: .git içindeki HEAD'den kısa commit kimliği (git komutu çalıştırmadan). */
final class AppVersion
{
    public static function commit(): ?string
    {
        $git = base_path('.git');
        $head = @file_get_contents($git.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (preg_match('/^ref:\s*(\S+)$/', $head, $m) === 1) {
            $ref = @file_get_contents($git.'/'.$m[1]);
            if ($ref === false && is_file($git.'/packed-refs')) {
                foreach (file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, ' '.$m[1])) {
                        $ref = strtok($line, ' ');
                        break;
                    }
                }
            }
            $head = is_string($ref) ? trim($ref) : '';
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? substr($head, 0, 7) : null;
    }
}
