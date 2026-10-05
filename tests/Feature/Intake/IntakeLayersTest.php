<?php

namespace Tests\Feature\Intake;

use App\Models\IntakeEvent;
use App\Models\IntakeLayerSample;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\SenderPickup;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\IntakeLayerReview;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\ScrapedLoadService;
use App\Support\IntakeLayers;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Okuma katmanları (2026-10-05, Osman): her katman panelden açılıp kapanır; yorum katmanı (iki satırlık ilan) ve gönderen hafızası
 * yalnız bugüne kadar çöpe giden mesajlara bakar. Uydurma numara ve grup adı.
 */
class IntakeLayersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '0');
        // Yorum katmanı canlıda gölgeyle başlar; davranış testleri etkin hâlini sınar (gölge ve aşama testleri ayrı).
        IntakeLayers::setStage('two_line_route', IntakeLayers::STAGE_ACTIVE);
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Deneme Grubu', 'type' => 'notification', 'source_identifier' => 'notif:deneme-grubu', 'is_active' => true]);
    }

    private function intake(string $message, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Deneme Grubu', 'raw_message' => $message, 'message_id' => $id, 'source_jid' => 'notif:deneme-grubu']);
    }

    public function test_layers_have_stages_managed_by_the_system_not_by_a_switch(): void
    {
        foreach (IntakeLayers::LAYERS as $layer => $def) {
            if (($def['lifecycle'] ?? 'permanent') === 'managed') {
                $this->assertArrayHasKey(IntakeLayers::stageKey($layer), Settings::DEFAULTS, $layer);
            } else {
                $this->assertSame(IntakeLayers::STAGE_ACTIVE, IntakeLayers::stage($layer), $layer);
                $this->assertTrue(IntakeLayers::enabled($layer), $layer);
            }
        }
        // Yönetilen katmanın varsayılanı: iki satır yorumu gölgede başlar, gönderen hafızası etkin başlar.
        $this->assertSame(IntakeLayers::STAGE_SHADOW, Settings::DEFAULTS[IntakeLayers::stageKey('two_line_route')]);
        $this->assertSame(IntakeLayers::STAGE_ACTIVE, Settings::DEFAULTS[IntakeLayers::stageKey('sender_pickup_memory')]);
        // Kalıcı katmanın aşaması ayarla değişmez.
        Settings::set(IntakeLayers::stageKey('not_load_pattern'), 'shadow');
        $this->assertTrue(IntakeLayers::enabled('not_load_pattern'));
        $this->assertSame('no_pickup_filter', IntakeLayers::layerForReason('pickup_missing'));
        // Ayarlar ekranında aç/kapa yok; yalnız açıklama.
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.settings-center', ['activeTab' => 'scraper'])->assertSee('elle açılıp kapanmaz')->assertDontSee('layers.two_line_route');
    }

    public function test_shadow_two_line_layer_records_a_judged_sample_without_affecting_the_ad(): void
    {
        IntakeLayers::setStage('two_line_route', IntakeLayers::STAGE_SHADOW);
        Settings::set('ai_parse_mode', 'always');
        config()->set('services.ai.active_provider', 'gemini');
        config()->set('services.ai.gemini_key', 'test-key');
        config()->set('services.ai.gemini_model', 'gemini-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '5321112233',
            'pickup' => ['province' => 'İstanbul', 'district' => 'Hadımköy'], 'delivery' => ['province' => 'Ankara', 'district' => null],
            'goods' => null, 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false,
            'weight_kg' => null, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null,
        ])]]]]]])]);
        $this->source();
        $r = $this->intake("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33", 'm1');
        // İlan etkilenmez: gönderen hafızası yok → kalkış bekleyen aday (eskisi gibi), iki satır yorumu uygulanmaz
        $this->assertSame(['created', 'pickup_pending'], [$r['status'], $r['reason'] ?? null]);
        $this->assertNull(ScrapedLoad::first()->meta('route_inferred'));
        // Örnek yazıldı ve hakem (yapay zeka) rotayı doğruladı
        $sample = IntakeLayerSample::query()->where('layer', 'two_line_route')->first();
        $this->assertNotNull($sample);
        $this->assertSame(['shadow', 'agree'], [$sample->stage, $sample->verdict]);
        $this->assertSame('Ankara', $sample->predicted_delivery);
        $this->assertStringContainsString('İstanbul', (string) $sample->judge_pickup);
    }

    public function test_review_promotes_a_shadow_layer_on_evidence_and_pauses_an_active_layer_on_bad_outcomes(): void
    {
        $review = app(IntakeLayerReview::class);
        IntakeLayers::setStage('two_line_route', IntakeLayers::STAGE_SHADOW);
        // 29 uyumlu örnek: henüz yetmez
        for ($i = 0; $i < 29; $i++) {
            IntakeLayerSample::create(['layer' => 'two_line_route', 'stage' => 'shadow', 'verdict' => 'agree', 'created_at' => now()->addSecond()]);
        }
        $this->assertSame([], $review->run());
        $this->assertSame(IntakeLayers::STAGE_SHADOW, IntakeLayers::stage('two_line_route'));
        // 30. örnek + uyum %85 üstü → kendiliğinden etkin; etkinlik günlüğüne yazılır (sistem kullanıcısı yokken de)
        IntakeLayerSample::create(['layer' => 'two_line_route', 'stage' => 'shadow', 'verdict' => 'agree', 'created_at' => now()->addSecond()]);
        IntakeLayerSample::create(['layer' => 'two_line_route', 'stage' => 'shadow', 'verdict' => 'unknown', 'created_at' => now()->addSecond()]); // sayılmaz
        $changes = $review->run();
        $this->assertCount(1, $changes);
        $this->assertStringContainsString('etkinleşti', $changes[0]);
        $this->assertSame(IntakeLayers::STAGE_ACTIVE, IntakeLayers::stage('two_line_route'));

        // Etkin katmanda kötü sonuç: 20 sonucun 7'si yönetici düzeltmesi/reddi (%35 ≥ %30) → duraklatılır (5 dk sonra, zaman ilerletilir)
        $this->travelTo(now()->addMinutes(5));
        $src = $this->source();
        for ($i = 0; $i < 20; $i++) {
            $bad = $i < 7;
            ScrapedLoad::create([
                'scraper_id' => $src->id, 'content_hash' => hash('sha256', 'x'.$i), 'raw_message' => 'İstanbul Ankara 0532 111 22 33',
                'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'pickup_location' => 'İstanbul', 'pickup_province_code' => 34,
                'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'status' => $bad ? 'rejected' : 'parsed_success', 'visibility' => $bad ? 'private' : 'public',
                'parsed_by_llm' => 'regex', 'ai_status' => 'skipped', 'duplicate_count' => 1, 'seen_sources' => ['Deneme Grubu'], 'last_seen_at' => now(), 'sighting_count' => 1,
                'completed_by' => $bad ? null : 'driver', 'retention_expires_at' => now()->addDays(7),
                'parse_metadata' => ['layer' => 'two_line_route', 'route_inferred' => 'two_line'] + ($bad ? ['reject_reason' => 'wrong_route'] : []),
            ]);
        }
        $m = $review->metrics('two_line_route');
        $this->assertSame([13, 7], [$m['outcome']['good'], $m['outcome']['bad']]);
        $changes = $review->run();
        $this->assertCount(1, $changes);
        $this->assertStringContainsString('duraklatıldı', $changes[0]);
        $this->assertSame(IntakeLayers::STAGE_PAUSED, IntakeLayers::stage('two_line_route'));
        $this->assertTrue(IntakeLayers::isShadow('two_line_route'));
        // Duraklatılınca eski gölge örnekleri sayılmaz (aşama değişiminden sonrası gerekir): hemen yeniden etkinleşmez
        $this->assertSame([], $review->run());
        $this->assertSame(IntakeLayers::STAGE_PAUSED, IntakeLayers::stage('two_line_route'));
        // Yeterli uyumsuz gölge örneğiyle de etkinleşmez (%63 < %85)
        IntakeLayers::setStage('two_line_route', IntakeLayers::STAGE_SHADOW);
        for ($i = 0; $i < 32; $i++) {
            IntakeLayerSample::create(['layer' => 'two_line_route', 'stage' => 'shadow', 'verdict' => $i < 20 ? 'agree' : 'disagree', 'created_at' => now()->addSecond()]);
        }
        $this->assertSame([], $review->run());
        $this->assertSame(IntakeLayers::STAGE_SHADOW, IntakeLayers::stage('two_line_route'));
    }

    public function test_two_place_vehicle_lines_are_read_first_pickup_second_delivery_as_incomplete(): void
    {
        $this->source();
        $r = $this->intake("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33", 'm1');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $load = ScrapedLoad::first();
        $this->assertSame(['İstanbul Arnavutköy', 'Ankara'], [$load->pickup_location, $load->delivery_location]);
        $this->assertSame(['two_line', 'two_line_route'], [$load->meta('route_inferred'), $load->meta('layer')]);
        $svc = app(ScrapedLoadService::class);
        $this->assertSame(ScrapedLoadService::INFERRED_ROUTE_BLOCKER, $svc->autoApprovalBlocker($load));
        $this->assertTrue($svc->incompleteEligible($load));
        $svc->autoApproveDue();
        $load->refresh();
        $this->assertSame(['public', true], [$load->visibility, (bool) $load->is_incomplete]);
        // Kart rozeti ve şoför doğrulaması eskisi gibi: "Aradım, araç:" ile tamamlanınca normal ilan
        $html = (string) $this->blade('<x-external-load-card :item="$item" />', ['item' => $load]);
        $this->assertStringContainsString('Bilgi eksik', $html);

        // Gölgede / duraklatılmışken eskisi gibi: fiilsiz iki satır "kalkışsız liste" sayılır, yorum uygulanmaz (yalnız örnek yazılır)
        IntakeLayers::setStage('two_line_route', IntakeLayers::STAGE_PAUSED);
        $segs = LoadIntakeService::splitSegments("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33");
        $this->assertArrayNotHasKey('interpreted', $segs[0]);
        $this->assertTrue((bool) ($segs[0]['pickup_missing'] ?? false));
        $this->assertTrue((bool) ($segs[0]['shadow_two_line'] ?? false));
    }

    public function test_ai_confirmation_turns_an_interpreted_route_into_a_full_ad(): void
    {
        $this->source();
        Settings::set('ai_parse_mode', 'always');
        config()->set('services.ai.active_provider', 'gemini');
        config()->set('services.ai.gemini_key', 'test-key');
        config()->set('services.ai.gemini_model', 'gemini-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '5321112233',
            'pickup' => ['province' => 'İstanbul', 'district' => 'Hadımköy'], 'delivery' => ['province' => 'Ankara', 'district' => null],
            'goods' => null, 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false,
            'weight_kg' => null, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null,
        ])]]]]]])]);
        $r = $this->intake("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33", 'm1');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $load = ScrapedLoad::first();
        $this->assertTrue((bool) $load->meta('route_confirmed'));
        $this->assertNull(app(ScrapedLoadService::class)->autoApprovalBlocker($load), 'yapay zeka rotayı doğruladı: normal yayın');
    }

    public function test_pickup_less_list_waits_for_teaching_and_then_splits_into_incomplete_ads(): void
    {
        $this->source();
        $msg = "SAMSUN KAPALI TIR\nİZMİR KAPALI TIR\nÇANAKKALE TENTELİ KAMYON\n\n☎️ AD 0538 111 22 33";
        $r = $this->intake($msg, 'm1');
        $this->assertSame(['created', 'pickup_pending'], [$r['status'], $r['reason'] ?? null]);
        $holder = ScrapedLoad::first();
        $this->assertTrue((bool) $holder->meta('needs_pickup'));
        $this->assertCount(3, (array) $holder->meta('dest_lines'));
        $this->assertSame('rota eksik', app(ScrapedLoadService::class)->autoApprovalBlocker($holder)); // yayınlanmaz, yaş retine düşer
        // Aynı liste yeniden gelirse ikinci bekleyen açılmaz.
        $this->assertSame('duplicate', $this->intake($msg, 'm1')['status']);

        // Yönetici kuyrukta görür ve "Kalkış öğret" der: gönderen hafızasına girer, mesaj yeniden okunur, üç ayrı "bilgi eksik" ilan açılır.
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.scrapers-center')->assertSee('Kalkış yazmıyor')->assertSee('Kalkış öğret')
            ->set('teachPickup.'.$holder->id, 'Kocaeli Gebze')->call('teachPickup', $holder->id);
        $n = ScrapedLoad::query()->count();
        $this->assertSame(3, $n);
        $this->assertSame(1, SenderPickup::query()->count());
        $this->assertSame('Kocaeli Gebze', SenderPickup::first()->pickup_label);
        $loads = ScrapedLoad::query()->orderBy('id')->get();
        $this->assertCount(3, $loads);
        $this->assertSame([['Kocaeli Gebze', 'Samsun'], ['Kocaeli Gebze', 'İzmir'], ['Kocaeli Gebze', 'Çanakkale']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame('sender_memory', $loads[0]->meta('route_inferred'));
        $this->assertSame('tir', $loads[0]->vehicle_type);
        app(ScrapedLoadService::class)->autoApproveDue();
        $this->assertSame(3, ScrapedLoad::query()->where('visibility', 'public')->where('is_incomplete', true)->count());

        // Aynı gönderenin sonraki listesi kendiliğinden çözülür (bekleyen aday açılmaz).
        $r = $this->intake("BURSA KAPALI TIR\nKONYA TENTELİ TIR\nADANA KAPALI TIR\n0538 111 22 33", 'm2');
        $this->assertSame('created', $r['status']);
        $this->assertCount(3, $r['created_ids']);
        $this->assertSame(0, ScrapedLoad::query()->whereNotNull('parse_metadata->needs_pickup')->count());
    }

    public function test_sender_history_supplies_the_pickup_without_teaching(): void
    {
        $this->source();
        foreach (['m1' => 'Kocaeli Gebze - Ankara 24 ton tenteli tır 0538 111 22 33', 'm2' => 'Gebze çıkışlı Konya teslim kapalı tır 0538 111 22 33', 'm3' => 'Gebze yükler Antalya iner tenteli 0538 111 22 33'] as $id => $msg) {
            $this->assertSame('created', $this->intake($msg, $id)['status'], $msg);
        }
        $r = $this->intake("SAMSUN KAPALI TIR\nİZMİR KAPALI TIR\nTRABZON TENTELİ TIR\n0538 111 22 33", 'm4');
        $this->assertSame('created', $r['status']);
        $this->assertCount(3, $r['created_ids']);
        $new = ScrapedLoad::query()->orderByDesc('id')->limit(3)->get();
        $this->assertSame(['Kocaeli Gebze'], $new->pluck('pickup_location')->unique()->values()->all());
        $this->assertSame('sender_pickup_memory', $new[0]->meta('layer'));
        $this->assertStringContainsString('gönderen hafızası', $new[0]->raw_message);
    }

    public function test_plus_kdv_written_as_arti_is_a_price_and_goods_only_block_is_shared(): void
    {
        $std = app(LoadStandardizer::class);
        $p = app(AiParserService::class);
        $this->assertSame(1500.0, (float) $p->parseCheap("Mersin - Burdur\n1500 artı\n0532 111 22 33")['price']);
        $this->assertSame(1050.0, (float) $p->parseCheap('Mersin - Isparta 1050 artı kdv 0532 111 22 33')['price']);
        $this->assertSame(1500.0, (float) $p->parseCheap('Mersin - Burdur 1500+kdv 0532 111 22 33')['price']);
        $this->assertSame(1050.0, (float) $std->priceFromText(' mersin isparta 1050 arti kdv '));
        $this->assertSame(1500.0, (float) $std->priceFromText(' mersin burdur 1500 arti '));
        $this->assertNull($std->priceFromText(' 24 ton arti '), 'tonaj fiyat değildir');

        // Engin Abi'nin örneği (uydurma numara): iki ilan, ikisi de fiyatlı ve yüklü; ilki artık eksik bilgili değil, normal yayın
        $this->source();
        $r = $this->intake("Mersin/ Burdur depo artı\nbüğdüz köyü\n1500 artı\n\nMersin/ şarkikaraağaç depo\n1050 artı kdv\n\nÇuvallı yem\n\n0532 111 22 33", 'm1');
        $this->assertSame('created', $r['status']);
        $loads = ScrapedLoad::query()->orderBy('id')->get();
        $this->assertCount(2, $loads);
        $this->assertSame([['Mersin', 'Burdur', 1500.0, 'Tarım ürünü'], ['Mersin', 'Isparta Şarkikaraağaç', 1050.0, 'Tarım ürünü']],
            $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location, (float) $l->price, $l->goods_type])->all());
        $svc = app(ScrapedLoadService::class);
        foreach ($loads as $l) {
            $this->assertGreaterThanOrEqual(0.75, $svc->decision($l)['score'], (string) $l->id);
        }
        $svc->autoApproveDue();
        $this->assertSame(2, ScrapedLoad::query()->where('visibility', 'public')->where('is_incomplete', false)->count());
    }

    /** Derin inceleme (2026-10-05): mantıksız yere elenen ya da yanlış kabul edilen biçimler. */
    public function test_deep_review_recovers_wrongly_filtered_ads_and_drops_carrier_messages(): void
    {
        $this->source();
        $ok = [
            'Satılık değil, yük var: Bursa - Ankara 10 ton 0532 222 00 04',
            'Ankara - İzmir 24 ton tenteli, e-fatura kesilir 0532 222 00 05',
            'İzmir - Ankara 2 tır lazım kiralık değil navlun 0532 222 00 07',
            'Konya=Ankara 10 ton 0532 222 00 19',
            'Konya => Ankara 10 ton 0532 222 00 20',
            'Boş araç arıyorum Ankara İzmir 24 ton 0532 222 00 01',
            'Ankara dan İzmir e yük var boşta tır varsa arasın 0532 222 00 02',
        ];
        foreach ($ok as $i => $msg) {
            $r = $this->intake($msg, 'ok'.$i);
            $this->assertSame('created', $r['status'], $msg.' → '.json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(['Konya', 'Ankara'], [ScrapedLoad::query()->where('raw_message', 'like', 'Konya=%')->first()->pickup_location, ScrapedLoad::query()->where('raw_message', 'like', 'Konya=%')->first()->delivery_location]);
        $notLoad = [
            "Yük arıyorum Ankara'dan İstanbul'a boş tırım var 0532 222 00 03",
            'Boş tırım var Ankara İzmir arası yük arıyoruz 0532 222 00 08',
            'Satılık 2018 çekici 0532 222 00 09',
            'E-fatura, e-arşiv, gider fişi yapılır 0532 222 00 10',
        ];
        foreach ($notLoad as $i => $msg) {
            $r = $this->intake($msg, 'nl'.$i);
            $this->assertSame('filtered', $r['status'], $msg.' → '.json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_created_candidates_carry_their_layer_and_panel_counts_them(): void
    {
        $this->source();
        $this->intake('Ankara - İzmir 24 ton tenteli tır 0532 111 22 33', 'm1');
        $this->intake("ANKARA YÜKLER\nİZMİR BOŞALTIR\nKONYA BOŞALTIR\nDAMPERLİ ARAÇLAR\n0533 111 22 33", 'm2');
        $layers = ScrapedLoad::query()->get()->map(fn ($l) => $l->meta('layer'))->countBy()->all();
        $this->assertSame(1, $layers['blocks'] ?? 0);
        $this->assertSame(2, $layers['series'] ?? 0);

        $report = app(IntakeLayerReview::class)->report();
        $this->assertSame(2, $report['series']['opened_7d']);
        $this->assertSame('kalıcı', $report['series']['stage_label']);
        $this->assertSame('etkin', $report['two_line_route']['stage_label']);
        // Hat karnesi: katman satırları yönetici ekranında
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.scrapers-center')->assertSee('Okuma katmanları (7 gün)')->assertSee('Seri ilan (başlık + boşaltma listesi)');
        $this->assertSame(0, IntakeEvent::query()->where('status', 'failed')->count());
    }
}
