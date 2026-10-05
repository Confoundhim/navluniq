<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\IntakeEvent;
use App\Models\IntakeLayerSample;
use App\Models\ScrapedLoad;
use App\Support\IntakeLayers;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Okuma katmanlarının aşama yöneticisi (saatlik `intake-layers:review`). Yönetilen katmanlar:
 *  - gölgede: örnek sayısı MIN_SHADOW_SAMPLES'a ulaşınca hakemle (yapay zeka) uyum oranı PROMOTE_AGREEMENT ve üstüyse → etkin;
 *    uyum PAUSE_DISAGREEMENT'ın altındaysa gölgede kalır (katman geliştirilmeli; rapor satırı bunu söyler).
 *  - etkin: son 7 günün sonuçları (şoför "Aradım" / yapay zeka doğruladı / yönetici düzeltti / yönetici reddetti); en az MIN_OUTCOMES
 *    sonuçta kötü oranı PAUSE_BAD_RATE ve üstüyse → gölgeye döner (paused). Yeniden etkinleşmesi için taze gölge kanıtı gerekir.
 * Her değişim: etkinlik günlüğü + Telegram (alert_telegram_chat_id) + yönetici bildirimi. Elle aşama değişimi yoktur.
 */
class IntakeLayerReview
{
    public const MIN_SHADOW_SAMPLES = 30;

    public const PROMOTE_AGREEMENT = 0.85;

    public const MIN_OUTCOMES = 20;

    public const PAUSE_BAD_RATE = 0.30;

    public const WINDOW_DAYS = 14;

    /** Gölge hakemi (yapay zeka) saatte en çok bu kadar örnek alır: kota korunur. */
    public const JUDGE_PER_HOUR = 30;

    public function __construct(private readonly NotificationService $notifications, private readonly TelegramPublisher $telegram) {}

    /** Tüm yönetilen katmanları değerlendirir; aşama değişimlerini döner. @return list<string> */
    public function run(): array
    {
        $changes = [];
        foreach (IntakeLayers::LAYERS as $key => $def) {
            if (($def['lifecycle'] ?? 'permanent') !== 'managed') {
                continue;
            }
            $stage = IntakeLayers::stage($key);
            $m = $this->metrics($key);
            if ($stage !== IntakeLayers::STAGE_ACTIVE) {
                $judged = $m['shadow']['agree'] + $m['shadow']['disagree'];
                if (($def['judge'] ?? 'outcome') === 'ai_route' && $judged >= self::MIN_SHADOW_SAMPLES && $m['shadow']['agreement'] >= self::PROMOTE_AGREEMENT) {
                    IntakeLayers::setStage($key, IntakeLayers::STAGE_ACTIVE);
                    $changes[] = $this->announce($key, 'etkinleşti', sprintf('Gölgede %d örnek, yapay zeka hakemiyle uyum %%%d (eşik %%%d). Katman artık ilanları etkiliyor.', $judged, round($m['shadow']['agreement'] * 100), self::PROMOTE_AGREEMENT * 100));
                } elseif (($def['judge'] ?? 'outcome') === 'outcome' && $stage === IntakeLayers::STAGE_PAUSED && $m['outcome']['total'] === 0 && ($changed = IntakeLayers::stageChangedAt($key)) !== null && $changed->lt(now()->subDays(7))) {
                    // Sonuçla yönetilen katman: 7 gün dinlendikten sonra yeniden denenir (yeni kanıt yalnız etkinken birikir).
                    IntakeLayers::setStage($key, IntakeLayers::STAGE_ACTIVE);
                    $changes[] = $this->announce($key, 'yeniden etkinleşti', '7 günlük duraklamadan sonra yeniden deneniyor; sonuçlar izlenmeye devam eder.');
                }

                continue;
            }
            if ($m['outcome']['total'] >= self::MIN_OUTCOMES && $m['outcome']['bad_rate'] >= self::PAUSE_BAD_RATE) {
                IntakeLayers::setStage($key, IntakeLayers::STAGE_PAUSED);
                $changes[] = $this->announce($key, 'duraklatıldı', sprintf('Son 7 günde %d sonuç; yönetici düzeltme/ret oranı %%%d (eşik %%%d). Katman gölgeye alındı, ilanları etkilemiyor.', $m['outcome']['total'], round($m['outcome']['bad_rate'] * 100), self::PAUSE_BAD_RATE * 100));
            }
        }

        return $changes;
    }

    /**
     * Katman ölçümleri (hat karnesi ve aşama kararı).
     *
     * @return array{stage:string, opened_7d:int, filtered_7d:int, shadow:array{agree:int, disagree:int, unknown:int, agreement:float}, outcome:array{good:int, bad:int, total:int, bad_rate:float}}
     */
    public function metrics(string $layer): array
    {
        $since = IntakeLayers::stageChangedAt($layer) ?? now()->subDays(self::WINDOW_DAYS);
        $since = $since->gt(now()->subDays(self::WINDOW_DAYS)) ? $since : now()->subDays(self::WINDOW_DAYS);
        $shadow = ['agree' => 0, 'disagree' => 0, 'unknown' => 0];
        // Yalnız son aşama değişiminden SONRAKİ örnekler sayılır (duraklatılan katman eski kanıtla hemen geri dönmesin)
        foreach (IntakeLayerSample::query()->where('layer', $layer)->where('created_at', '>', $since)->selectRaw('verdict, count(*) as n')->groupBy('verdict')->pluck('n', 'verdict') as $verdict => $n) {
            $shadow[$verdict] = (int) $n;
        }
        $judged = $shadow['agree'] + $shadow['disagree'];
        $shadow['agreement'] = $judged > 0 ? round($shadow['agree'] / $judged, 3) : 0.0;

        $loads = ScrapedLoad::query()->where('created_at', '>=', now()->subDays(7))->where('parse_metadata->layer', $layer);
        $opened = (clone $loads)->count();
        $good = (clone $loads)->where(fn ($q) => $q->whereNotNull('parse_metadata->route_confirmed')->orWhereIn('completed_by', ['driver', 'ai']))->count();
        $bad = (clone $loads)->where(fn ($q) => $q->whereNotNull('parse_metadata->admin_edited')
            ->orWhere(fn ($r) => $r->where('status', 'rejected')->whereNull('parse_metadata->auto_rejected')->whereNull('parse_metadata->duplicate_of')->whereNull('parse_metadata->superseded_by')))->count();
        $total = $good + $bad;
        $filtered = 0;
        $reasons = IntakeLayers::LAYERS[$layer]['reasons'] ?? [];
        if ($reasons !== []) {
            $filtered = IntakeEvent::query()->where('created_at', '>=', now()->subDays(7))->where('status', 'filtered')->whereIn('reason', $reasons)->count();
        }

        return [
            'stage' => IntakeLayers::stage($layer),
            'opened_7d' => $opened,
            'filtered_7d' => $filtered,
            'shadow' => $shadow,
            'outcome' => ['good' => $good, 'bad' => $bad, 'total' => $total, 'bad_rate' => $total > 0 ? round($bad / $total, 3) : 0.0],
        ];
    }

    /** Hat karnesi için tüm katmanlar. @return array<string, array> */
    public function report(): array
    {
        $out = [];
        foreach (IntakeLayers::LAYERS as $key => $def) {
            try {
                $out[$key] = $this->metrics($key) + ['label' => $def['label'], 'kind' => $def['kind'], 'managed' => ($def['lifecycle'] ?? 'permanent') === 'managed', 'stage_label' => IntakeLayers::stageLabel($key)];
            } catch (Throwable $e) {
                Log::warning('Katman ölçümü alınamadı.', ['layer' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Gölge örneği: katmanın tahmini + (kota izin verirse) yapay zeka hakeminin rotası. Yan etkisiz; hata yutulur.
     * Hakem ulaşılamazsa verdict "unknown" (aşama kararında sayılmaz).
     */
    public static function recordShadow(string $layer, string $text, ?string $predictedPickup, ?string $predictedDelivery, ?int $loadId = null): void
    {
        try {
            $judgePickup = null;
            $judgeDelivery = null;
            $verdict = 'unknown';
            $hourKey = 'intake:shadow-judge:'.now()->format('YmdH');
            $parser = app(AiParserService::class);
            if ($parser->isEnabled() && $parser->isConfigured() && (int) Cache::get($hourKey, 0) < self::JUDGE_PER_HOUR) {
                Cache::add($hourKey, 0, now()->addHours(2));
                Cache::increment($hourKey);
                $ai = $parser->enrich($text, []);
                $data = $ai['data'] ?? null;
                if (is_array($data) && ($data['is_load'] ?? true) !== false) {
                    $judgePickup = is_string($data['pickup_location'] ?? null) ? $data['pickup_location'] : null;
                    $judgeDelivery = is_string($data['delivery_location'] ?? null) ? $data['delivery_location'] : null;
                    $pp = TurkishLocations::resolve((string) $predictedPickup)['province_code'] ?? null;
                    $pd = TurkishLocations::resolve((string) $predictedDelivery)['province_code'] ?? null;
                    $jp = TurkishLocations::resolve((string) $judgePickup)['province_code'] ?? null;
                    $jd = TurkishLocations::resolve((string) $judgeDelivery)['province_code'] ?? null;
                    if ($jp !== null && $jd !== null && $pp !== null && $pd !== null) {
                        $verdict = ($pp === $jp && $pd === $jd) ? 'agree' : 'disagree';
                    } elseif ($jp === null || $jd === null) {
                        $verdict = 'disagree'; // hakem de rotayı çıkaramadı: katmanın tahmini kanıtsız
                    }
                }
            }
            IntakeLayerSample::create([
                'layer' => $layer, 'stage' => IntakeLayers::stage($layer), 'scraped_load_id' => $loadId,
                'predicted_pickup' => $predictedPickup !== null ? mb_substr($predictedPickup, 0, 120) : null, 'predicted_delivery' => $predictedDelivery !== null ? mb_substr($predictedDelivery, 0, 120) : null,
                'judge_pickup' => $judgePickup !== null ? mb_substr($judgePickup, 0, 120) : null, 'judge_delivery' => $judgeDelivery !== null ? mb_substr($judgeDelivery, 0, 120) : null,
                'verdict' => $verdict, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Gölge örneği yazılamadı.', ['layer' => $layer, 'error' => $e->getMessage()]);
        }
    }

    private function announce(string $layer, string $what, string $detail): string
    {
        $label = IntakeLayers::LAYERS[$layer]['label'] ?? $layer;
        $line = "Okuma katmanı \"{$label}\" {$what}: {$detail}";
        ActivityLog::record('intake_layer.'.$what, $line, null, null, ['layer' => $layer]);
        $chatId = Settings::string('alert_telegram_chat_id');
        if ($chatId !== '' && TelegramPublisher::hasBotToken()) {
            try {
                $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $this->telegram->sendTo($chatId, '<b>'.$e("Okuma katmanı {$what}: {$label}").'</b>'."\n".$e($detail));
            } catch (Throwable $ex) {
                Log::warning('Katman Telegram mesajı gönderilemedi.', ['error' => $ex->getMessage()]);
            }
        }
        try {
            $url = null;
            try {
                $url = route('admin.scrapers');
            } catch (Throwable) {
                // rota yoksa bağlantısız
            }
            $this->notifications->notifyAdmins('manage scrapers', "Okuma katmanı {$what}: {$label}", [$detail], $url, $url ? 'Dış kaynak ilanları' : null, 'admin');
        } catch (Throwable $ex) {
            Log::warning('Katman panel bildirimi yazılamadı.', ['error' => $ex->getMessage()]);
        }

        return $line;
    }
}
