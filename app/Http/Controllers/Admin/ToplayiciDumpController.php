<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tanı: Toplayıcı uygulamasının yolladığı son 3 ham Facebook ekran dökümü (48 saat önbellekte). Ayrıştırıcı yanlış grup ya da
 * satır çıkardığında gerçek biçimi görmek için indirilir. Yalnız dış kaynak yöneticisi; metin kişi adı içerebilir, paylaşılmaz.
 */
class ToplayiciDumpController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $dumps = (array) Cache::get('fb:last_dumps', []);
        $body = $dumps === [] ? "Henüz ekran dökümü gelmedi.\n" : implode("\n\n==================== ", array_map(
            fn (array $d) => ($d['at'] ?? '').' · '.($d['app'] ?? '')." ====================\n".($d['text'] ?? ''), $dumps));

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="facebook-son-dokum.txt"', 'Cache-Control' => 'no-store']);
    }
}
