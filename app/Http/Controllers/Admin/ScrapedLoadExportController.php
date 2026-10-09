<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScrapedLoad;
use App\Services\ScrapedLoadService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analiz dökümü: dış kaynak adayları (bekleyen / yayında / reddedilen) satır başına bir JSON (JSONL, gzip) olarak iner.
 * Amaç, kuyrukta bekleyen ilanların neden karara bağlanmadığını toplu incelemek (Osman, 2026-10-09): her satırda kuralın
 * okuduğu alanlar, karar puanının parçaları, otomatik onayı engelleyen neden ve ret gerekçesi vardır.
 * Mesajlar üçüncü kişilere aittir: telefon numaraları metin içinde de maskelenir; gönderen adı zaten saklanmaz.
 */
class ScrapedLoadExportController extends Controller
{
    public const SCOPES = ['queue' => 'bekleyen', 'published' => 'yayinda', 'rejected' => 'reddedilen', 'all' => 'tumu'];

    public const MAX_ROWS = 20000;

    public function __invoke(Request $request, ScrapedLoadService $service): StreamedResponse
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $scope = (string) $request->query('kapsam', 'queue');
        $scope = array_key_exists($scope, self::SCOPES) ? $scope : 'queue';
        $days = max(1, min(60, (int) $request->query('gun', 7)));

        $query = ScrapedLoad::query()->with('scraper')->withCount('trips')->where('created_at', '>=', now()->subDays($days))->orderBy('id');
        match ($scope) {
            'published' => $query->where('visibility', 'public'),
            'rejected' => $query->where('status', 'rejected'),
            'all' => null,
            default => $query->where('visibility', 'private')->where('status', '!=', 'rejected'),
        };

        $filename = 'navluniq-analiz-'.self::SCOPES[$scope].'-'.$days.'gun-'.now()->format('Ymd-Hi').'.jsonl.gz';

        return response()->streamDownload(function () use ($query, $service, $scope, $days): void {
            @set_time_limit(0);
            // gzopen('php://output') test ortamında "could not make seekable" verir; deflate akışı her ortamda çalışır
            $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            $emit = function (string $line) use ($ctx): void {
                echo deflate_add($ctx, $line, ZLIB_NO_FLUSH);
            };
            $header = ['_meta' => ['scope' => $scope, 'days' => $days, 'exported_at' => now()->toIso8601String(), 'app' => config('app.url'),
                'note' => 'Telefonlar maskeli; gönderen adı saklanmaz. Alanlar: rule = kuralın okuduğu, decision = karar puanı, blocker = otomatik onay engeli.']];
            $emit(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $n = 0;
            foreach ($query->lazyById(200) as $load) {
                if (++$n > self::MAX_ROWS) {
                    break;
                }
                $emit(json_encode(self::row($load, $service), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
                if ($n % 200 === 0) {
                    flush();
                }
            }
            echo deflate_add($ctx, '', ZLIB_FINISH);
        }, $filename, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    public static function row(ScrapedLoad $load, ScrapedLoadService $service): array
    {
        $meta = (array) ($load->parse_metadata ?? []);
        $ai = (array) ($meta['ai'] ?? []);
        $pending = $load->visibility !== 'public' && $load->status !== 'rejected';
        $blocker = null;
        $decision = null;
        try {
            $blocker = $pending ? $service->autoApprovalBlocker($load) : null;
            $decision = $service->decision($load);
        } catch (\Throwable $e) {
            $blocker = $blocker ?? 'hesaplanamadı: '.$e->getMessage();
        }
        $phone = $load->plainPhone();

        return [
            'id' => $load->id,
            'source' => ['name' => $load->scraper?->name, 'type' => $load->scraper?->type, 'active' => (bool) $load->scraper?->is_active],
            'created_at' => $load->created_at?->toIso8601String(),
            'last_seen_at' => $load->last_seen_at?->toIso8601String(),
            'published_at' => $load->published_at?->toIso8601String(),
            'status' => $load->status,
            'visibility' => $load->visibility,
            'is_incomplete' => (bool) $load->is_incomplete,
            'auto_approved' => $load->auto_approved_at !== null,
            'completed_by' => $load->completed_by,
            'rule' => [
                'pickup' => $load->pickup_location, 'pickup_province' => $load->pickup_province_code, 'pickup_district' => $load->pickup_district,
                'delivery' => $load->delivery_location, 'delivery_province' => $load->delivery_province_code, 'delivery_district' => $load->delivery_district,
                'vehicle' => $load->vehicle_type, 'vehicle_source' => $load->vehicle_type_source, 'vehicle_any' => (bool) $load->vehicle_any,
                'vehicle_count' => $load->vehicle_count, 'body_types' => $load->body_types, 'body_source' => $load->body_type_source,
                'load_kind' => $load->load_kind, 'goods' => $load->goods_type, 'weight' => $load->weight,
                'price' => $load->price !== null ? (float) $load->price : null, 'price_unit' => $load->price_unit,
                'phone' => $phone !== null ? ScrapedLoad::maskPhone($phone) : null, 'phone_kind' => $phone !== null ? Phone::kindLabel($phone) : null,
                'extra_phones' => count($load->extraPhones()),
                'delivery_stops' => count($load->deliveryStops()),
            ],
            'parse' => [
                'by' => $load->parsed_by_llm, 'confidence' => $load->parse_confidence !== null ? (float) $load->parse_confidence : null,
                'ai_status' => $load->ai_status, 'ai_is_load' => $ai['is_load'] ?? null, 'ai_notes' => $ai['notes'] ?? null, 'ai_provider' => $ai['provider'] ?? null,
                'ai_conflict' => $meta['ai_conflict'] ?? null, 'local_confidence' => $meta['local_confidence'] ?? null,
                'warnings' => $meta['warnings'] ?? [], 'layer' => $meta['layer'] ?? null, 'route_inferred' => $meta['route_inferred'] ?? null,
                'route_confirmed' => $meta['route_confirmed'] ?? null, 'needs_pickup' => $meta['needs_pickup'] ?? null, 'series' => $meta['series'] ?? null,
                'urgent' => $meta['urgent'] ?? null, 'template_id' => $meta['template_id'] ?? null, 'relocated_from' => $meta['relocated_from'] ?? null,
                'international' => $meta['international'] ?? null, 'supersedes' => $meta['supersedes'] ?? null,
            ],
            'decision' => $decision,
            'blocker' => $blocker,
            'incomplete_eligible' => $pending && $blocker ? $service->incompleteEligible($load, $blocker) : null,
            'reject_reason' => $meta['reject_reason'] ?? null,
            'auto_rejected' => $meta['auto_rejected'] ?? null,
            'duplicate_of' => $meta['duplicate_of'] ?? null,
            'duplicate_count' => (int) $load->duplicate_count,
            'sighting_count' => (int) $load->sighting_count,
            'seen_sources' => $load->seen_sources,
            'similar_to' => $meta['similar_to'] ?? null,
            'trips' => (int) ($load->trips_count ?? 0),
            'raw_message' => self::maskText((string) $load->raw_message),
        ];
    }

    /** Metin içindeki telefon numaralarını maskeler (alan kodu ve son iki hane kalır); fiyat/tonaj gibi kısa sayılar dokunulmaz. */
    public static function maskText(string $text): string
    {
        // Cep, sabit hat ve 0850/0800: (+90|0)? ABC DEF GH IJ — ayraçlar boşluk, nokta, tire ya da parantez olabilir
        $text = preg_replace_callback('/(?<!\d)\(?(?:\+?90[\s.\-]?)?0?[\s.\-]?\(?([2-8]\d{2})\)?[\s.\-]?(\d{3})[\s.\-]?(\d{2})[\s.\-]?(\d{2})(?!\d)/u',
            fn (array $m) => '0'.$m[1].' *** ** '.$m[4], $text) ?? $text;

        // 444'lü kısa numara
        return preg_replace('/(?<!\d)444(?:[\s.\-]?\d){4}(?!\d)/u', '444 * ** **', $text) ?? $text;
    }
}
