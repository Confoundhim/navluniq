<?php

namespace Tests\Feature\Services;

use App\Http\Middleware\FirewallMiddleware;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Support\TurkishCities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoadIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const AD = "Ankara Ostim'den İzmir Aliağa'ya 24 ton palet yük, tenteli tır lazım, yarın yükleme. 0532 123 45 67";

    private const CHATTER = 'Hayırlı işler arkadaşlar, herkese bol kazançlar 🙏';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.ai.active_provider', 'gemini');
        config()->set('services.ai.gemini_key', 'test-key');
        config()->set('services.ai.gemini_model', 'gemini-test');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '5321234567',
                    'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => 'Aliağa'],
                    'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tenteli', 'vehicle_flexible' => false,
                    'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null,
                ])]]]]],
            ]),
        ]);
    }

    private function activeSource(string $jid = '1203630000001@g.us'): Scraper
    {
        return Scraper::create(['name' => 'Test Grubu', 'type' => 'whatsapp', 'source_identifier' => $jid, 'is_active' => true]);
    }

    public function test_same_ad_from_ten_groups_is_stored_once_and_parsed_free(): void
    {
        $intake = app(LoadIntakeService::class);
        $statuses = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->activeSource("12036300000{$i}@g.us");
            $r = $intake->intake(['group_name' => "Grup {$i}", 'raw_message' => self::AD.' 🚛', 'message_id' => "m{$i}", 'source_jid' => "12036300000{$i}@g.us"]);
            $statuses[] = $r['status'];
        }

        $this->assertSame(['created'], array_values(array_unique(array_slice($statuses, 0, 1))));
        $this->assertSame(9, count(array_filter($statuses, fn ($s) => $s === 'duplicate')));
        $this->assertSame(1, ScrapedLoad::count());
        $this->assertSame('regex_verified', ScrapedLoad::first()->parsed_by_llm);
        $this->assertSame('5321234567|ankara|izmir', ScrapedLoad::first()->route_key);
        $this->assertSame('İzmir Aliağa', ScrapedLoad::first()->delivery_location);
        Http::assertNothingSent();
    }

    public function test_bare_city_pairs_are_parsed_without_a_connector(): void
    {
        $parser = app(AiParserService::class);
        $p = $parser->parseCheap("Denizli Bursa Kamyonete Parça Şimdi Yüklenir.\n05425297755");
        $this->assertTrue($p['success']);
        $this->assertSame(['Denizli', 'Bursa', '5425297755', 'kamyonet'], [$p['pickup_location'], $p['delivery_location'], $p['sender_phone'], $p['vehicle_type']]);

        $p = $parser->parseCheap('Samsun Trabzon 1.5 ton hafif ticari 0533 000 11 22');
        $this->assertSame(['Samsun', 'Trabzon'], [$p['pickup_location'], $p['delivery_location']]);

        // Bağlaç varsa eski davranış korunur; ilçe adı ilin önüne geçmez.
        $p = $parser->parseCheap("Ankara Ostim'den İzmir Aliağa'ya 24 ton 0532 123 45 67");
        $this->assertSame(['Ankara Ostim', 'İzmir Aliağa'], [$p['pickup_location'], $p['delivery_location']]);
        $this->assertSame(['Denizli', 'Bursa'], AiParserService::firstTwoProvinces('Denizli Bursa Denizli parça'));
        $this->assertFalse($parser->parseCheap('Sadece Ankara 5 ton 0532 123 45 67')['success'], 'Tek il rota sayılmaz');
    }

    public function test_turkish_city_helper_handles_suffixes_and_aliases(): void
    {
        $this->assertSame('İzmir', TurkishCities::fromText("İzmir'e"));
        $this->assertSame('Ankara', TurkishCities::fromText('Ankaradan Ostim'));
        $this->assertSame('İstanbul', TurkishCities::fromText('istanbula'));
        $this->assertSame('Kahramanmaraş', TurkishCities::fromText('Maraş'));
        $this->assertNull(TurkishCities::fromText('Aliağa'));
        $this->assertSame('İzmir Aliağa', TurkishCities::normalizeLocation('İzmire Aliağa'));
    }

    public function test_chatter_and_inactive_sources_never_reach_the_ai(): void
    {
        $intake = app(LoadIntakeService::class);
        $this->activeSource();

        $chatter = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => self::CHATTER, 'message_id' => 'c1', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('filtered', $chatter['status']);

        $pending = $intake->intake(['group_name' => 'Yeni Grup', 'raw_message' => 'İstanbul çıkışlı Bursa varış 5 ton yük var 0533 000 00 00', 'message_id' => 'p1', 'source_jid' => '1203630009999@g.us']);
        $this->assertSame('source_pending', $pending['status']);
        $this->assertFalse(Scraper::where('source_identifier', '1203630009999@g.us')->first()->is_active);

        Http::assertNothingSent();
        $this->assertSame(0, ScrapedLoad::count());
    }

    public function test_ai_is_called_once_for_a_free_text_ad_and_reworded_repeat_is_deduped(): void
    {
        $intake = app(LoadIntakeService::class);
        $this->activeSource();

        $first = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Yükümüz hazır, Ostim çıkış Aliağa varış, palet, tenteli arayanlar 0532 123 45 67', 'message_id' => 'a1', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('created', $first['status']);
        $this->assertSame('gemini', ScrapedLoad::first()->parsed_by_llm);
        Http::assertSentCount(1);

        // Aynı numara, aynı rota, farklı sözcükler: kalıp eşleme yakalar, yapay zekaya gidilmez, tekrar sayılır.
        $second = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Ankara Ostim - İzmir Aliağa palet 0532 123 45 67 acil', 'message_id' => 'a2', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('duplicate', $second['status']);
        Http::assertSentCount(1);
        $this->assertSame(1, ScrapedLoad::count());
    }

    public function test_webhook_uses_intake_pipeline(): void
    {
        config()->set('services.scraper.token', 'secret-token');
        $this->activeSource();

        $this->withoutMiddleware(FirewallMiddleware::class)
            ->postJson('/api/v1/webhook/whatsapp-scraper', ['group_name' => 'Test Grubu', 'raw_message' => self::AD, 'message_id' => 'w1', 'source_jid' => '1203630000001@g.us'], ['X-Scraper-Token' => 'secret-token'])
            ->assertStatus(201)->assertJsonPath('status', 'created');

        $this->withoutMiddleware(FirewallMiddleware::class)
            ->postJson('/api/v1/webhook/whatsapp-scraper', ['group_name' => 'Başka Grup', 'raw_message' => self::AD, 'message_id' => 'w2', 'source_jid' => '1203630000002@g.us'], ['X-Scraper-Token' => 'secret-token'])
            ->assertStatus(200)->assertJsonPath('status', 'duplicate');
    }
}
