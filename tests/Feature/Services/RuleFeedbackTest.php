<?php

namespace Tests\Feature\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadIntakeService;
use App\Services\RuleFeedbackService;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Öğrenme çemberi: yapay zekanın çözdüğü ama kuralın bilmediği yazımlar öneri olur; onay (elle ya da eşikle) sözlüğe girer
 * ve sonraki mesaj yapay zekasız çözülür; kuralla çözülen ilanlar günlük denetimde yapay zekaya sorulur, uyuşmazlık öneri olur.
 */
class RuleFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Lexicon::flush();
        Settings::set('ai_parse_mode', 'fill_gaps');
        Settings::set('ai_suggest_auto_approve_hits', 0);
        config()->set('services.ai.active_provider', 'gemini');
        config()->set('services.ai.gemini_key', 'test-key');
        config()->set('services.ai.gemini_model', 'gemini-test');
        // Sahte yapay zeka: mesaja göre cevap verir (Büsan → Konya, Kale → Malatya, Ostim → Ankara).
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) {
            $body = $request->body();
            $ad = match (true) {
                str_contains($body, 'Kale') => ['pickup' => ['province' => 'Malatya', 'district' => 'Kale'], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir'],
                str_contains($body, 'Ostim') => ['pickup' => ['province' => 'Ankara', 'district' => 'Yenimahalle'], 'delivery' => ['province' => 'Bursa', 'district' => null], 'goods' => 'salça', 'goods_category' => 'gida', 'vehicle_type' => null],
                default => ['pickup' => ['province' => 'Konya', 'district' => null], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'pekmez', 'goods_category' => 'gida', 'vehicle_type' => 'tir'],
            };

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($ad + [
                'post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => null, 'vehicle_flexible' => false, 'weight_kg' => null, 'price_try' => null,
                'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null,
            ])]]]]]]);
        }]);
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function intake(string $message, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $message, 'message_id' => $id, 'source_jid' => 'notif:grup-a']);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id, 'content_hash' => hash('sha256', uniqid('', true)),
            'raw_message' => 'Ankara İzmir 24 ton tenteli 0532 123 45 67', 'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private', 'duplicate_count' => 1, 'seen_sources' => ['Grup A'],
            'ai_status' => 'skipped',
        ], $overrides));
    }

    public function test_ai_resolved_spellings_become_suggestions_and_teach_the_rule_when_the_threshold_is_reached(): void
    {
        $this->source();
        $this->assertNull(TurkishLocations::resolve('Büsan'), 'katalogda olmayan sanayi bölgesi');

        // 1) Kural "Büsan"ı çözemez → yapay zeka Konya der → ilan Konya ile açılır, "busan → Konya" ve "salca → Gıda" öneri olur.
        $r = $this->intake('Büsan sanayiden İzmire 12 ton pekmez açık kasa 0532 123 45 67', 'm1');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(42, (int) ScrapedLoad::first()->pickup_province_code);
        $busan = AiLexicon::query()->where('kind', 'location')->where('term', 'busan')->first();
        $this->assertNotNull($busan);
        $this->assertSame(['Konya', 'suggested', 'ai', 1], [$busan->canonical, $busan->status, $busan->source, $busan->hits]);
        $this->assertStringContainsString('kural çözemedi', (string) $busan->note);
        $salca = AiLexicon::query()->where('kind', 'goods')->where('term', 'pekmez')->first();
        $this->assertNotNull($salca, 'katalogda olmayan yük sözcüğü öneri olur');
        $this->assertSame(['gida', 'suggested', 1], [$salca->canonical, $salca->status, $salca->hits]);
        $this->assertNull(TurkishLocations::resolve('Büsan'), 'öneri onaylanmadan sözlüğe girmez');
        $this->assertSame(2, AiLexicon::query()->where('status', 'suggested')->count(), 'iki öneri, ikisi de bekliyor');

        // 2) Eşik 2: aynı öneri ikinci bir ilanda görülünce kendiliğinden onaylanır; kural artık kendisi çözer.
        Settings::set('ai_suggest_auto_approve_hits', 2);
        $r = $this->intake('Büsan sanayi - İzmir 12 ton pekmez 0533 111 22 33', 'm2');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $busan->refresh();
        $this->assertSame(['active', 2], [$busan->status, $busan->hits]);
        $this->assertStringStartsWith('kendiliğinden onaylandı', (string) $busan->note);
        $this->assertSame(42, TurkishLocations::resolve('Büsan sanayi')['province_code']);
        $this->assertSame('active', $salca->fresh()->status);
        Http::assertSentCount(2);

        // 3) Üçüncü mesaj: kural sözlükle çözer, yapay zeka çağrılmaz.
        $r = $this->intake('Büsan sanayiden İzmire 10 ton pekmez 0534 222 33 44', 'm3');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        Http::assertSentCount(2);
        $third = ScrapedLoad::orderByDesc('id')->first();
        $this->assertSame([42, 'Gıda'], [(int) $third->pickup_province_code, $third->goods_type]);
        $this->assertNotSame('done', $third->ai_status);
    }

    public function test_panel_approves_with_one_tap_and_ignored_suggestions_are_not_repeated(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $feedback = app(RuleFeedbackService::class);

        $this->assertTrue($feedback->suggest('location', 'Büsan', 'Konya', 'Büsan sanayiden İzmire 12 ton 0532 123 45 67', 11, 'çözümleme · kural çözemedi'));
        $this->assertTrue($feedback->suggest('goods', 'pekmez', 'gida', 'Büsan sanayiden İzmire 12 ton pekmez 0532 123 45 67', 11));
        $feedback->suggest('location', 'Büsan', 'Konya', 'aynı ilan', 11);
        $this->assertSame(1, AiLexicon::query()->where('term', 'busan')->value('hits'), 'aynı ilan sayacı artırmaz');
        $this->assertFalse($feedback->suggest('location', 'kamyon', 'Ankara', 'gündelik sözcük', 12), 'gündelik sözcükler önerilmez');

        $busan = AiLexicon::query()->where('term', 'busan')->first();
        $salca = AiLexicon::query()->where('term', 'pekmez')->first();
        $this->actingAs($admin->fresh());
        Volt::test('admin.scrapers-center')->set('activeTab', 'lexicon')
            ->assertSee('busan')->assertSee('1 ilanda görüldü')->assertSee('Onayla')
            ->call('acceptSuggestion', $busan->id)->assertHasNoErrors()
            ->call('ignoreSuggestion', $salca->id)->assertHasNoErrors();

        $this->assertSame(['active', 'ai'], [$busan->fresh()->status, $busan->fresh()->source]);
        $this->assertSame(42, TurkishLocations::resolve('Büsan sanayi')['province_code']);
        $this->assertSame('ignored', $salca->fresh()->status);
        $this->assertFalse($feedback->suggest('goods', 'pekmez', 'gida', 'yeni ilan', 13), 'yok sayılan yazım bir daha önerilmez');
        $this->assertSame(1, AiLexicon::query()->where('term', 'pekmez')->count());

        // Yapay zeka aynı yazıma başka karşılık verirse sayaç sıfırlanır (çelişkili öneri kendiliğinden onaylanmaz).
        Settings::set('ai_suggest_auto_approve_hits', 2);
        $feedback->suggest('location', 'Zomzom', 'Kocaeli Körfez', 'ilan 1', 21); // katalogda olmayan jargon (bilinen ad öneri olamaz)
        $feedback->suggest('location', 'Zomzom', 'İzmit', 'ilan 2', 22);
        $row = AiLexicon::query()->where('term', 'zomzom')->first();
        $this->assertSame(['suggested', 1, 'İzmit'], [$row->status, $row->hits, $row->canonical]);
        $this->assertStringContainsString('önceki karşılık: Kocaeli Körfez', (string) $row->note);
    }

    public function test_daily_audit_asks_ai_about_rule_parsed_loads_and_turns_mismatches_into_suggestions(): void
    {
        $source = $this->source();
        // Kural "Kale"yi Denizli okudu; yapay zeka Malatya der → uyuşmazlık + öneri. Ostim ilanı yapay zekayla uyuşur.
        $kale = $this->candidate($source, ['raw_message' => 'Kale - İzmir 10 ton palet yük tenteli 0532 123 45 67', 'pickup_location' => 'Denizli Kale', 'pickup_province_code' => 20, 'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword']);
        $ostim = $this->candidate($source, ['raw_message' => 'Ostimden Bursaya 10 ton salça 0533 111 22 33', 'encrypted_sender_phone' => Crypt::encryptString('5331112233'), 'pickup_location' => 'Ankara Yenimahalle', 'delivery_location' => 'Bursa', 'delivery_province_code' => 16, 'goods_type' => 'Gıda', 'ai_status' => null]);
        $this->candidate($source, ['raw_message' => 'Yapay zeka bakmış ilan 0534 000 00 00', 'ai_status' => 'done']); // denetlenmez

        Settings::set('ai_audit_daily_count', 0);
        $this->artisan('scraped-loads:ai-audit')->expectsOutputToContain('Denetim kapalı')->assertSuccessful();
        Http::assertNothingSent();

        Settings::set('ai_audit_daily_count', 5);
        $this->artisan('scraped-loads:ai-audit')->expectsOutputToContain('Denetlenen: 2, uyuşmazlık: 1')->assertSuccessful();
        Http::assertSentCount(2);

        $kale->refresh();
        $this->assertSame(20, (int) $kale->pickup_province_code, 'denetim ilanı değiştirmez');
        $this->assertFalse($kale->meta('audit')['agree']);
        $this->assertStringContainsString('Malatya', $kale->meta('audit')['diff']['pickup']);
        $this->assertTrue($ostim->fresh()->meta('audit')['agree']);
        // "Kale" katalogda bilinen bir ilçe adı: uyuşmazlık kayda geçer ama takma ad önerisi olmaz (katalog yeniden öğretilmez;
        // 2026-10-01'de "ankara → İzmir Torbalı" gibi öneriler kataloğu ezmişti). Eş adlı ilçe sorunu bağlamla çözülür, sözlükle değil.
        $this->assertNull(AiLexicon::query()->where('kind', 'location')->where('term', 'kale')->first(), 'katalog adı öneri olmaz');

        // Aynı ilan bir daha denetlenmez; sayaçlar sağlık ekranında.
        $this->artisan('scraped-loads:ai-audit')->expectsOutputToContain('Denetlenen: 0');
        $stats = app(RuleFeedbackService::class)->weeklyStats();
        $this->assertSame([2, 1, 0], [$stats['audited'], $stats['mismatched'], $stats['pending']]);
        $this->assertSame([2, 1], [$stats['rule'], $stats['ai']]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.health-center')->assertSee('Öğrenme çemberi')->assertSee('denetlenen 2 (uyuşmazlık 1)')->assertSee('bekleyen öneri 0');
    }
}
