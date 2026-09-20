<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntakeEvent;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use App\Services\ScrapedLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Android bildirim iletici ucu. Telefondaki MacroDroid benzeri bir uygulama, WhatsApp
 * bildirimlerinin başlık ve metnini buraya gönderir; WhatsApp'a hiçbir cihaz bağlanmaz.
 */
class NotificationWebhookController extends Controller
{
    /**
     * Bağlantı sınaması: telefonun tarayıcısından açılan GET isteği. Ağ + anahtar doğruysa Canlı akışa
     * "Bağlantı sınaması" düşer; böylece sorun telefon tarafında (MacroDroid tetikleyicisi) mı, ağda mı anlaşılır.
     */
    public function ping(Request $request): JsonResponse
    {
        $expected = ScrapedLoadService::apiToken();
        $token = (string) ($request->header('X-Scraper-Token') ?: $request->query('token', ''));
        if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
            IntakeEvent::record('unauthorized', ['title' => 'Bağlantı sınaması', 'excerpt' => 'Tarayıcıdan açılan sınama bağlantısı', 'reason' => $token === '' ? 'token_missing' : 'token_mismatch']);

            return response()->json(['ok' => false, 'error' => 'Anahtar hatalı.'], 401);
        }
        IntakeEvent::record('ping', ['title' => 'Bağlantı sınaması', 'source_name' => 'Telefon', 'excerpt' => mb_substr((string) $request->userAgent(), 0, 200)]);

        return response()->json(['ok' => true, 'message' => 'Sunucuya ulaştınız; Canlı akışta "Bağlantı sınaması" satırı görünmeli.', 'server_time' => now()->toDateTimeString()]);
    }

    public function handle(Request $request, LoadIntakeService $intake): JsonResponse
    {
        $expectedToken = ScrapedLoadService::apiToken();
        if ($expectedToken === '') {
            Log::critical('Bildirim webhook güvenlik anahtarı yapılandırılmamış.');

            return response()->json(['error' => 'Servis yapılandırılmamış.'], 503);
        }

        // MacroDroid mesaj metnini JSON'a olduğu gibi gömer; metinde tırnak ya da satır sonu varsa JSON bozulur
        // ve tüm alanlar boş okunur. Bozuk JSON alanları düzenli ifadeyle kurtarılır.
        $repaired = false;
        $raw = (string) $request->getContent();
        if ($request->isJson() && $request->json()->all() === [] && trim($raw) !== '') {
            $request->merge(self::repairJson($raw));
            $repaired = true;
        }
        // Gövdeye "title = {not_title}" satırları olarak yapıştırılmış kurulum (form alanı yerine metin): yine okunur.
        if ($request->input('token', '') === '' && preg_match('/(^|\n)\s*token\s*=/u', $raw)) {
            $request->merge(self::parseKeyValueLines($raw));
            $repaired = true;
        }

        // Başlık ya da gövde alanı; bazı otomasyon uygulamaları özel başlık gönderemez.
        $providedToken = (string) ($request->header('X-Scraper-Token') ?: $request->input('token', ''));
        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            IntakeEvent::record('unauthorized', [
                'title' => (string) $request->input('title', ''),
                'excerpt' => (string) ($request->input('text') ?: 'Gelen gövde: '.mb_substr(trim($raw) !== '' ? $raw : http_build_query($request->all()), 0, 200)),
                'reason' => $providedToken === '' ? 'token_missing' : 'token_mismatch',
            ]);

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
            IntakeEvent::record('skipped', ['title' => $validated['title'] ?? null, 'excerpt' => $validated['text_big'] ?? $validated['text'] ?? null, 'reason' => $parsed['skipped'].($repaired ? ' (json_repaired)' : '')]);

            return response()->json(['success' => true, 'status' => 'skipped', 'reason' => $parsed['skipped'], 'processed' => 0]);
        }

        $results = [];
        foreach ($parsed['messages'] as $message) {
            $results[] = $result = $intake->intake([
                'group_name' => $parsed['group'],
                'source_jid' => NotificationIntakeParser::sourceIdentifier($parsed['group']),
                'source_type' => 'notification',
                'raw_message' => $message['text'],
                'sender_phone' => $message['phone'],
                // Aynı bildirimin tekrar teslimi için sabit kimlik; içerik aynıysa değişmez.
                'message_id' => substr(hash('sha256', $parsed['group'].'|'.($message['sender'] ?? '').'|'.$message['text']), 0, 40),
            ]);
            IntakeEvent::record($result['status'], ['source_name' => $parsed['group'], 'title' => $validated['title'] ?? null, 'excerpt' => $message['text'],
                'reason' => $result['reason'] ?? null, 'scraped_load_id' => $result['scraped_load_id'] ?? null]);
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

    /** "title = …" satırlarından alanları okur; text alanı bir sonraki "alan =" satırına kadar çok satırlı olabilir. */
    public static function parseKeyValueLines(string $raw): array
    {
        $keys = 'title|text|text_big|ticker|app|token|posted_at';
        $out = [];
        if (preg_match_all('/(?:^|\n)[ \t]*('.$keys.')[ \t]*=[ \t]*(.*?)(?=\n[ \t]*(?:'.$keys.')[ \t]*=|\z)/su', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out[$hit[1]] = trim($hit[2]);
            }
        }

        return $out;
    }

    /** Bozuk JSON gövdesinden bilinen alanları çıkarır (değer içinde tırnak/satır sonu olsa da). */
    public static function repairJson(string $raw): array
    {
        $keys = 'title|text|text_big|ticker|app|token|posted_at';
        $out = [];
        if (preg_match_all('/"('.$keys.')"\s*:\s*"(.*?)"\s*(?=,\s*"(?:'.$keys.')"\s*:|\s*}\s*$)/su', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $value = str_replace(['\\n', '\\"', '\\/'], ["\n", '"', '/'], $hit[2]);
                $out[$hit[1]] = $value;
            }
        }

        return $out;
    }
}
