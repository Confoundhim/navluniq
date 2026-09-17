<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Android bildirim iletici ucu. Telefondaki MacroDroid benzeri bir uygulama, WhatsApp
 * bildirimlerinin başlık ve metnini buraya gönderir; WhatsApp'a hiçbir cihaz bağlanmaz.
 */
class NotificationWebhookController extends Controller
{
    public function handle(Request $request, LoadIntakeService $intake): JsonResponse
    {
        $expectedToken = (string) config('services.scraper.token', '');
        if ($expectedToken === '') {
            Log::critical('Bildirim webhook güvenlik anahtarı yapılandırılmamış.');

            return response()->json(['error' => 'Servis yapılandırılmamış.'], 503);
        }

        // Başlık ya da gövde alanı; bazı otomasyon uygulamaları özel başlık gönderemez.
        $providedToken = (string) ($request->header('X-Scraper-Token') ?: $request->input('token', ''));
        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['error' => 'Yetkisiz erişim.'], 401);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string', 'max:10000'],
            'text_big' => ['nullable', 'string', 'max:10000'],
            'ticker' => ['nullable', 'string', 'max:2000'],
            'app' => ['nullable', 'string', 'max:120'],
            'posted_at' => ['nullable', 'string', 'max:64'],
        ]);

        $parsed = NotificationIntakeParser::parse($validated);
        if ($parsed['skipped'] !== null) {
            return response()->json(['success' => true, 'status' => 'skipped', 'reason' => $parsed['skipped'], 'processed' => 0]);
        }

        $results = [];
        foreach ($parsed['messages'] as $message) {
            $results[] = $intake->intake([
                'group_name' => $parsed['group'],
                'source_jid' => NotificationIntakeParser::sourceIdentifier($parsed['group']),
                'source_type' => 'notification',
                'raw_message' => $message['text'],
                'sender_phone' => $message['phone'],
                // Aynı bildirimin tekrar teslimi için sabit kimlik; içerik aynıysa değişmez.
                'message_id' => substr(hash('sha256', $parsed['group'].'|'.($message['sender'] ?? '').'|'.$message['text']), 0, 40),
            ]);
        }

        $summary = array_count_values(array_column($results, 'status'));

        return response()->json([
            'success' => true,
            'status' => count($results) === 1 ? $results[0]['status'] : 'batch',
            'processed' => count($results),
            'summary' => $summary,
            'results' => array_map(fn (array $r) => ['status' => $r['status'], 'message' => $r['message'], 'scraped_load_id' => $r['scraped_load_id'] ?? null], $results),
        ]);
    }
}
