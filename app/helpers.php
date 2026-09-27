<?php

use App\Support\AssetVersion;

if (! function_exists('asset_v')) {
    /** Sabit dosya adresine sürüm eki ekler: asset_v('/images/logo.png') → /images/logo.png?v=1a2b3c4d */
    function asset_v(string $path): string
    {
        return AssetVersion::url($path);
    }
}
