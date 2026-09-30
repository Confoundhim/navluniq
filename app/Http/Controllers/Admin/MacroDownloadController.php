<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ScrapedLoadService;
use App\Support\MacroDroidMacro;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hazır MacroDroid makro dosyası (Facebook akışı toplama); anahtar ve adres içine yazılı. Yalnız dış kaynak yöneticisi indirir. */
class MacroDownloadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        abort_if(ScrapedLoadService::apiToken() === '', 404, 'Önce anahtar üretilmeli.');
        $screens = max(3, min(40, (int) $request->query('ekran', 15))); // kaç ekran kaydırılacağı (?ekran=10)
        $json = MacroDroidMacro::json(url('/api/v1/webhook/notification'), ScrapedLoadService::apiToken(), $screens);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.MacroDroidMacro::FILENAME.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
