<?php

namespace Tests\Feature\Intake;

use App\Models\AiLexicon;
use App\Models\AiTemplate;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LearningService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\LocalClassifier;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-05 ilan hattı denetimi (karar, tekrar, öğrenme, arşiv): her test bir bulgunun kapandığını doğrular.
 * Uydurma numara ve grup adı; gerçek mesaj yok.
 */
class DecisionAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '5320000002';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '0');
    }

    private function source(bool $active = true): Scraper
    {
        return Scraper::create(['name' => 'Deneme Grubu', 'type' => 'notification', 'source_identifier' => 'notif:deneme-grubu', 'is_active' => $active]);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        $raw = $overrides['raw_message'] ?? 'Ankara - İzmir 24 ton tenteli 0532 000 00 02';

        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id, 'content_hash' => hash('sha256', uniqid('', true)),
            'normalized_hash' => hash('sha256', LoadIntakeService::normalizeText($raw)),
            'route_key' => LoadIntakeService::routeKey(self::PHONE, 'Ankara', 'İzmir'),
            'raw_message' => $raw, 'encrypted_sender_phone' => Crypt::encryptString(self::PHONE),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword',
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private', 'ai_status' => 'skipped',
            'duplicate_count' => 1, 'seen_sources' => ['Deneme Grubu'],
        ], $overrides));
    }

    private function intake(string $text, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Deneme Grubu', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:deneme-grubu']);
    }

    private function groqAi(array $message): void
    {
        Settings::set('ai_parse_mode', 'fill_gaps');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_groq_model', 'llama-test');
        Settings::set('ai_provider', 'groq');
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($message)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]])]);
    }

    private function loadAd(array $extra = []): array
    {
        return ['post_type' => 'load', 'confidence' => 0.95, 'ads' => [array_merge([
            'post_type' => 'load', 'confidence' => 0.95, 'phones' => [self::PHONE], 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'İzmir', 'district' => null],
            'vehicle_type' => 'tir', 'weight_kg' => 24000, 'goods' => null, 'goods_category' => null, 'price_try' => null,
        ], $extra)]];
    }

    public function test_classifier_training_does_not_stamp_settings_changed(): void
    {
        Cache::forget('scraper.settings_changed_at');
        app(LocalClassifier::class)->train('Satılık kamyonet 0532 000 00 02', false);
        $this->assertNull(Settings::scraperSettingsChangedAt(), 'sayaç anahtarı "ayar değişti" damgası basmaz');
        Settings::set('scraper_relocate_force_cursor', '5');
        $this->assertNull(Settings::scraperSettingsChangedAt());
        Settings::set('scraper_auto_approve_min_confidence', '70');
        $this->assertNotNull(Settings::scraperSettingsChangedAt(), 'gerçek eşik ayarı damga basar');
    }

    public function test_blocker_is_read_only_and_twin_is_retired_only_on_publish(): void
    {
        $source = $this->source();
        $old = $this->candidate($source, ['raw_message' => 'Ankara - İzmir 24 ton tenteli 45 bin 0532 000 00 02', 'visibility' => 'public', 'published_at' => now()->subDays(3), 'price' => 45000]);
        ScrapedLoad::whereKey($old->id)->update(['created_at' => now()->subDays(3)]);
        $new = $this->candidate($source, ['raw_message' => 'Ankara çıkışlı İzmir Torbalı 24 ton 50 bin 0532 000 00 02', 'price' => 50000,
            'parse_metadata' => ['ai_conflict' => ['delivery_location' => ['rule' => 'İzmir', 'ai' => 'Manisa']]]]);

        $service = app(ScrapedLoadService::class);
        $this->assertSame('kural ve yapay zeka farklı il buldu; elle kontrol', $service->autoApprovalBlocker($new->fresh()));
        $this->assertNull(ScrapedLoad::withTrashed()->find($old->id)->deleted_at, 'aday engelliyken yayındaki ilan arşivlenmez');
        $this->assertSame(1, ScrapedLoad::query()->where('visibility', 'public')->count());

        // Elle ve otomatik onay aynı kararı verir: ikiz 48 saatten yaşlı ve metin farklı → yeni aday yerine geçer.
        $admin = User::factory()->create();
        $fresh = $this->candidate($source, ['raw_message' => 'Ankara - İzmir tenteli 24 ton 55 bin bugün 0532 000 00 02', 'price' => 55000]);
        $this->assertSame('supersede', $service->resolveTwin($fresh, $old->fresh()));
        $service->approve($fresh, $admin->id);
        $this->assertSame('public', $fresh->fresh()->visibility);
        $this->assertNotNull(ScrapedLoad::withTrashed()->find($old->id)->deleted_at, 'yayına alınınca eski ikiz arşivlendi');
    }

    public function test_same_text_twin_is_marked_duplicate_in_scan_loop_not_in_blocker(): void
    {
        $source = $this->source();
        $hash = hash('sha256', 'ayni-metin');
        $published = $this->candidate($source, ['visibility' => 'public', 'normalized_hash' => $hash, 'published_at' => now()->subHours(3)]);
        ScrapedLoad::whereKey($published->id)->update(['created_at' => now()->subHours(3)]);
        $twin = $this->candidate($source, ['normalized_hash' => $hash]);
        $service = app(ScrapedLoadService::class);
        $this->assertSame("tekrar (#{$published->id} yayında)", $service->autoApprovalBlocker($twin));
        $this->assertSame('parsed_success', $twin->fresh()->status);
        $service->autoApproveDue();
        $this->assertSame(['rejected', $published->id], [$twin->fresh()->status, $twin->fresh()->meta('duplicate_of')]);
    }

    public function test_reparse_with_ai_refreshes_route_key(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['raw_message' => 'Yükkent - İzmir 24 ton tır 0532 000 00 02', 'pickup_location' => 'Yükkent', 'pickup_province_code' => null, 'status' => 'parsed_partial', 'ai_status' => 'pending',
            'route_key' => LoadIntakeService::routeKey(self::PHONE, 'Yükkent', 'İzmir')]);
        $this->groqAi($this->loadAd(['pickup' => ['province' => 'Kocaeli', 'district' => 'Gebze']]));
        $this->assertTrue(app(ScrapedLoadService::class)->reparseWithAi($load, null, true));
        $load->refresh();
        $this->assertSame(['Kocaeli Gebze', 'parsed_success', '5320000002|kocaeli|izmir'], [$load->pickup_location, $load->status, $load->route_key]);

        Settings::set('ai_parse_mode', 'off');
        $r = $this->intake('Kocaeli Gebze - İzmir 24 ton tır 0532 000 00 02', 'm2');
        $this->assertSame('duplicate', $r['status'], 'aynı gönderenin düzgün yazımlı paylaşımı tekrar sayılır');
    }

    public function test_reparse_with_ai_rejects_high_confidence_not_load_verdict(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['ai_status' => 'pending', 'vehicle_type' => null, 'vehicle_type_source' => null]);
        $this->groqAi(['post_type' => 'other', 'confidence' => 0.9, 'notes' => 'sohbet', 'ads' => []]);
        $this->assertTrue(app(ScrapedLoadService::class)->reparseWithAi($load, null, true));
        $load->refresh();
        $this->assertSame(['rejected', false], [$load->status, $load->meta('ai')['is_load']]);
        $this->assertSame('yapay zeka: yük ilanı değil', $load->meta('auto_rejected')['reason']);
        $this->assertSame(0.1, app(ScrapedLoadService::class)->decision($load)['ai'], '"%90 ilan değil" → yapay zeka kanıtı 0,1');
    }

    public function test_unpublished_candidates_expire_from_queue(): void
    {
        $source = $this->source(active: false);
        $stale = $this->candidate($source, ['retention_expires_at' => now()->subDays(10)]);
        $edited = $this->candidate($source, ['retention_expires_at' => now()->subDays(10), 'parse_metadata' => ['admin_edited' => true]]);
        $live = $this->candidate($source, ['retention_expires_at' => now()->addDays(10)]);
        $this->assertSame(1, app(ScrapedLoadService::class)->purgeExpired());
        $this->assertNotNull(ScrapedLoad::withTrashed()->find($stale->id)->deleted_at);
        $this->assertNull(ScrapedLoad::withTrashed()->find($edited->id)->deleted_at, 'yönetici düzenlediği aday arşivlenmez');
        $this->assertNull(ScrapedLoad::withTrashed()->find($live->id)->deleted_at);
    }

    public function test_text_dedupe_ignores_auto_rejected_candidates(): void
    {
        $source = $this->source();
        $text = 'Ankara - İzmir 24 ton tenteli 0532 000 00 02';
        $rejected = $this->candidate($source, ['raw_message' => $text]);
        app(ScrapedLoadService::class)->autoReject($rejected, 'kuyrukta 48 saatten uzun bekledi');
        ScrapedLoad::whereKey($rejected->id)->update(['created_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)]);
        $r = $this->intake($text, 'm-repost');
        $this->assertSame('created', $r['status'], 'kendiliğinden reddedilen kaydın metni yeniden paylaşımı yutmaz');

        // Yöneticinin "ilan değil" dediği metin ise 7 gün tutar.
        $admin = User::factory()->create();
        $notLoad = $this->candidate($source, ['raw_message' => 'Boş tır var Ankara İzmir arası yük arıyorum 0532 000 00 02']);
        app(ScrapedLoadService::class)->reject($notLoad, $admin->id, 'not_load');
        $r = $this->intake('Boş tır var Ankara İzmir arası yük arıyorum 0532 000 00 02', 'm-notload');
        $this->assertSame('duplicate', $r['status']);
    }

    public function test_admin_approval_learns_only_certain_vehicle_into_template(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['raw_message' => 'Ankara - İzmir paletli yük var acil 0532 000 00 02', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'goods']);
        app(ScrapedLoadService::class)->approve($load, User::factory()->create()->id);
        $this->assertSame('goods', $load->fresh()->vehicle_type_source);
        $this->assertNull(AiTemplate::first()?->vehicle_type, 'tahminî araç kalıba kesin diye yazılmaz');
    }

    public function test_goods_words_are_not_learned_as_location_aliases(): void
    {
        $learning = app(LearningService::class);
        $learning->learnLocations('Kömür - Ankara 24 ton damperli 0532 000 00 02', 'Zonguldak', 'Ankara');
        $learning->learnLocations('Mermer - Ankara 24 ton açık 0532 000 00 02', 'Zonguldak', 'Ankara');
        $this->assertSame(0, AiLexicon::query()->where('kind', 'location')->whereIn('term', ['komur', 'mermer'])->count());
        $this->assertTrue(LearningService::isNoiseTerm('komur'));
    }

    public function test_auto_approve_error_is_retried_after_an_hour(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['parse_metadata' => ['auto_approve_error' => ['message' => 'geçici hata', 'at' => now()->toDateTimeString(), 'attempts' => 1]]]);
        $service = app(ScrapedLoadService::class);
        $this->assertStringStartsWith('onay hatası', (string) $service->autoApprovalBlocker($load));
        $this->travel(61)->minutes();
        $this->assertNull($service->autoApprovalBlocker($load->fresh()), '1 saat sonra yeniden denenir');
        $stuck = $this->candidate($source, ['parse_metadata' => ['auto_approve_error' => ['message' => 'kalıcı', 'at' => now()->subDays(2)->toDateTimeString(), 'attempts' => 3]]]);
        $this->assertStringStartsWith('onay hatası', (string) $service->autoApprovalBlocker($stuck), '3 denemeden sonra yönetici bekler');
    }

    public function test_incomplete_published_ad_is_enriched_and_completed_by_ai(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['vehicle_type' => null, 'vehicle_type_source' => null, 'ai_status' => 'pending', 'visibility' => 'public', 'is_incomplete' => true, 'published_at' => now()]);
        $this->groqAi($this->loadAd());
        $this->assertSame(1, app(ScrapedLoadService::class)->aiEnrichPending());
        $load->refresh();
        $this->assertSame(['done', 'tir', 'ai', false, 'ai'], [$load->ai_status, $load->vehicle_type, $load->vehicle_type_source, $load->is_incomplete, $load->completed_by]);
    }

    public function test_fuller_repost_supersedes_incomplete_published_ad(): void
    {
        $source = $this->source();
        $old = $this->candidate($source, ['raw_message' => 'Ankara İzmir yük var 0532 000 00 02', 'vehicle_type' => null, 'vehicle_type_source' => null,
            'visibility' => 'public', 'is_incomplete' => true, 'published_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3)]);
        $r = $this->intake('Ankara - İzmir 24 ton tenteli tır lazım 0532 000 00 02', 'm-full');
        $this->assertSame('created', $r['status'], 'araç bilgisi getiren paylaşım yeni aday açar');
        $new = ScrapedLoad::find($r['scraped_load_id']);
        $this->assertSame($old->id, (int) $new->meta('supersedes'));
        app(ScrapedLoadService::class)->autoApproveDue();
        $this->assertSame(['public', false, 'tir'], [$new->fresh()->visibility, $new->fresh()->is_incomplete, $new->fresh()->vehicle_type]);
        $this->assertNotNull(ScrapedLoad::withTrashed()->find($old->id)->deleted_at, 'eksik ilan yerini dolu ilana bıraktı');
    }

    public function test_identical_repost_of_a_thirty_day_old_ad_opens_a_new_record(): void
    {
        $source = $this->source();
        $text = 'Ankara - İzmir 24 ton tenteli 0532 000 00 02';
        $old = $this->candidate($source, ['raw_message' => $text, 'visibility' => 'public', 'published_at' => now()->subDays(60)]);
        ScrapedLoad::whereKey($old->id)->update(['created_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(2), 'retention_expires_at' => now()->addDays(5)]);
        $r = $this->intake($text, 'm-60d');
        $this->assertSame('created', $r['status']);
        $new = ScrapedLoad::find($r['scraped_load_id']);
        $this->assertSame('supersede', app(ScrapedLoadService::class)->resolveTwin($new, $old->fresh()));
    }

    public function test_unsure_local_classifier_does_not_demote_a_rule_strong_candidate(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['vehicle_type' => 'tir', 'vehicle_type_source' => 'hint', 'weight' => 24000, 'goods_type' => 'Paletli yük', 'parse_metadata' => ['vehicle_confidence' => 'low', 'local_confidence' => 0.5]]);
        $service = app(ScrapedLoadService::class);
        $d = $service->decision($load);
        $this->assertSame(['kural + yerel', 0.88], [$d['basis'], $d['score']]);
        $this->assertNull($service->autoApprovalBlocker($load));
        $sure = $this->candidate($source, ['vehicle_type' => 'tir', 'vehicle_type_source' => 'hint', 'parse_metadata' => ['vehicle_confidence' => 'low', 'local_confidence' => 0.75]]);
        $this->assertSame(0.93, $service->decision($sure)['score']); // kural 0,73 + (0,75 − 0,5) × 0,8
    }

    public function test_supersede_keeps_admin_rejected_training_example(): void
    {
        $source = $this->source();
        $rejected = $this->candidate($source, ['status' => 'rejected', 'parse_metadata' => ['reject_reason' => 'not_load']]);
        $new = $this->candidate($source);
        app(ScrapedLoadService::class)->supersede($rejected, $new);
        $this->assertNull(ScrapedLoad::withTrashed()->find($rejected->id)->deleted_at);
        $this->assertNull($rejected->fresh()->meta('superseded_by'));
    }

    public function test_relocate_and_restandardize_refresh_route_key_unconditionally(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['route_key' => 'eski|anahtar']);
        app(LoadStandardizer::class)->restandardize($load);
        $this->assertSame('5320000002|ankara|izmir', $load->fresh()->route_key);
        $this->assertInstanceOf(Carbon::class, $this->candidate($source, ['retention_expires_at' => now()->addDay()])->fresh()->retention_expires_at);
    }
}
