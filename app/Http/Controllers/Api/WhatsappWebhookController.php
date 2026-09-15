<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function handle(Request $request, LoadIntakeService $intake): JsonResponse
    {
        $expectedToken = (string) config('services.scraper.token', '');
        $providedToken = (string) $request->header('X-Scraper-Token', '');

        if ($expectedToken === '') {
            Log::critical('Scraper webhook güvenlik anahtarı yapılandırılmamış.');

            return response()->json(['error' => 'Servis yapılandırılmamış.'], 503);
        }

        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['error' => 'Yetkisiz erişim.'], 401);
        }

        $validated = $request->validate([
            'group_name' => ['required', 'string', 'max:255'],
            'raw_message' => ['required', 'string', 'max:5000'],
            'sender_phone' => ['nullable', 'string', 'max:32'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'source_jid' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $result = $intake->intake($validated);
        $code = $result['code'];
        unset($result['code']);

        return response()->json($result, $code);
    }
}
