<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function handle(Request $request, AiParserService $parser): JsonResponse
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

        try {
            $provider = (string) config('services.ai.active_provider', 'gemini');
            $parsedData = $parser->parseMessage($validated['raw_message'], $provider);
        } catch (\Throwable $e) {
            Log::error('Scraper mesajı ayrıştırılamadı.', ['exception' => $e::class]);

            return response()->json(['success' => false, 'message' => 'Mesaj işlenemedi.'], 503);
        }

        $isSuccess = ($parsedData['success'] ?? false) === true;
        $phone = $parsedData['sender_phone'] ?? $validated['sender_phone'] ?? null;
        $hasPhone = is_string($phone) && $phone !== '' && $phone !== 'Bilinmiyor';

        if (! $isSuccess || ! $hasPhone) {
            return response()->json(['success' => false, 'message' => 'İlan ölçütleri karşılanmadı.']);
        }

        $scraper = Scraper::firstOrCreate(
            ['source_identifier' => $validated['source_jid'] ?? $validated['group_name']],
            ['name' => $validated['group_name'], 'type' => 'whatsapp', 'is_active' => false]
        );

        if (! $scraper->is_active) {
            return response()->json(['success' => false, 'message' => 'Kaynak yönetici onayı bekliyor.'], 202);
        }

        $scraper->update(['last_scraped_at' => now()]);
        $scrapedLoad = ScrapedLoad::create([
            'scraper_id' => $scraper->id,
            'raw_message' => $validated['raw_message'],
            'sender_phone' => $phone,
            'pickup_location' => $parsedData['pickup_location'] ?? null,
            'delivery_location' => $parsedData['delivery_location'] ?? null,
            'goods_type' => $parsedData['goods_type'] ?? null,
            'weight' => $parsedData['weight'] ?? null,
            'price' => $parsedData['price'] ?? null,
            'status' => 'parsed_success',
            'parsed_by_llm' => $parsedData['parsed_by_llm'] ?? 'unknown',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'İlan adayı kaydedildi.',
            'scraped_load_id' => $scrapedLoad->id,
        ], 201);
    }
}
