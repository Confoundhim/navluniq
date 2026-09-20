<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\IntakeEvent;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Bildirim iletici (MacroDroid) kurulum sayfası: bağlantıyı bilen herkes telefonuna kurabilir.
 * Kod panelden yenilenirse eski bağlantı çalışmaz.
 */
class PhoneSetupController extends Controller
{
    private function guard(string $code): void
    {
        $expected = Settings::string('scraper_setup_code');
        abort_unless($expected !== '' && hash_equals($expected, $code), 404);
    }

    public function show(string $code): View
    {
        $this->guard($code);
        $last = IntakeEvent::query()->latest('id')->first();

        return view('frontend.phone-setup', [
            'code' => $code,
            'webhookUrl' => url('/api/v1/webhook/notification'),
            'body' => ScrapedLoadService::phoneRequestBody(),
            'params' => ScrapedLoadService::phoneRequestParams(),
            'pingUrl' => ScrapedLoadService::pingUrl(),
            'hasTemplate' => ScrapedLoadService::hasMacroTemplate(),
            'downloadUrl' => route('phone-setup.macro', ['code' => $code]),
            'lastEventAt' => $last?->created_at,
            'lastEventStatus' => $last?->statusLabel(),
            'lastEventSource' => $last?->source_name,
        ]);
    }

    public function macro(string $code): Response
    {
        $this->guard($code);
        $content = ScrapedLoadService::macroTemplate();
        abort_if($content === null, 404);

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="NavlunIQ.macro"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
