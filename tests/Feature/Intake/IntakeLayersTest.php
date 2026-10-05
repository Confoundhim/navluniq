<?php

namespace Tests\Feature\Intake;

use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\SenderPickup;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\ScrapedLoadService;
use App\Support\IntakeLayers;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Deneme Grubu', 'type' => 'notification', 'source_identifier' => 'notif:deneme-grubu', 'is_active' => true]);
    }

    private function intake(string $message, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Deneme Grubu', 'raw_message' => $message, 'message_id' => $id, 'source_jid' => 'notif:deneme-grubu']);
    }

    public function test_every_layer_has_a_default_setting_and_can_be_switched_off(): void
    {
        foreach (array_keys(IntakeLayers::LAYERS) as $layer) {
            $this->assertArrayHasKey(IntakeLayers::settingKey($layer), Settings::DEFAULTS, $layer);
            $this->assertTrue(IntakeLayers::enabled($layer), $layer);
        }
        Settings::set('intake_layer_not_load_pattern', '0');
        $this->assertFalse(IntakeLayers::enabled('not_load_pattern'));
        $this->assertSame('no_pickup_filter', IntakeLayers::layerForReason('pickup_missing'));
        $this->assertNull(IntakeLayers::layerForReason('phone_missing'));
    }

    public function test_switching_off_an_elimination_layer_lets_the_message_through(): void
    {
        $this->source();
        $msg = 'Boş araç arıyorum Ankara İzmir tır 0532 111 22 33'; // yük sahibi dili: boş araç ARIYOR → ilan
        $notLoad = 'Boştayım İstanbul Ankara 0532 111 22 33'; // nakliyeci dili: ilan değil
        $r = $this->intake($notLoad, 'm1');
        $this->assertSame(['filtered', 'not_load_pattern'], [$r['status'], $r['reason']]);
        Settings::set('intake_layer_not_load_pattern', '0');
        $r = $this->intake($notLoad, 'm2');
        $this->assertNotSame('not_load_pattern', $r['reason'] ?? null);
        Settings::set('intake_layer_not_load_pattern', '1');
        $this->assertSame('created', $this->intake($msg, 'm3')['status']);
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

        // Katman kapalıyken eskisi gibi: fiilsiz iki satır "kalkışsız liste" sayılır (eleme katmanı), yorum yapılmaz
        Settings::set('intake_layer_two_line_route', '0');
        $segs = LoadIntakeService::splitSegments("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33");
        $this->assertArrayNotHasKey('interpreted', $segs[0]);
        $this->assertTrue((bool) ($segs[0]['pickup_missing'] ?? false));
        // İki eleme/yorum katmanı da kapalıyken genel okumaya düşer (kesin okuma: İstanbul → Ankara)
        Settings::set('intake_layer_no_pickup_filter', '0');
        $segs = LoadIntakeService::splitSegments("İSTANBUL HADIMKÖY TENTELİ TIR\nANKARA 2 ARAÇ\n0532 111 22 33");
        $this->assertArrayNotHasKey('pickup_missing', $segs[0]);
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

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.settings-center', ['activeTab' => 'scraper'])
            ->assertSee('Okuma katmanları')->assertSee('İki satırlık ilan yorumu')->assertSee('2 aday')
            ->set('layers.two_line_route', '0')->call('saveScraper');
        $this->assertFalse(IntakeLayers::enabled('two_line_route'));
        $this->assertSame(0, IntakeEvent::query()->where('status', 'failed')->count());
    }
}
