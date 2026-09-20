<?php

namespace Tests\Feature\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LocalClassifier;
use App\Services\ScrapedLoadService;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LocalLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Lexicon::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id, 'content_hash' => hash('sha256', uniqid('', true)),
            'raw_message' => 'Ankara İzmir 24 ton tenteli 0532 123 45 67', 'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private', 'duplicate_count' => 1, 'seen_sources' => ['Grup A'],
        ], $overrides));
    }

    public function test_lexicon_entries_drive_location_vehicle_goods_and_filtering(): void
    {
        AiLexicon::create(['kind' => 'location', 'term' => 'ostim', 'canonical' => 'Ankara']);
        AiLexicon::create(['kind' => 'location', 'term' => 'gebze osb', 'canonical' => 'Kocaeli Gebze']);
        AiLexicon::create(['kind' => 'vehicle', 'term' => 'mega tenteli', 'canonical' => 'tir']);
        AiLexicon::create(['kind' => 'goods', 'term' => 'salca', 'canonical' => 'gida']);
        AiLexicon::create(['kind' => 'not_load', 'term' => 'satilik', 'canonical' => null]);
        Lexicon::flush();

        $this->assertSame(6, TurkishLocations::resolve('Ostim')['province_code']);
        $this->assertSame(['Kocaeli', 'Gebze'], [TurkishLocations::resolve('Gebze OSB')['province'], TurkishLocations::resolve('Gebze OSB')['district']]);
        $this->assertSame('tir', VehicleTypes::detect('Ostimden Gebzeye mega tenteli lazım')['type']);
        $this->assertSame('Gıda', GoodsCatalog::detect(' 20 ton salca yuku ')['label']);
        $this->assertFalse(LoadIntakeService::looksLikeLoad('Satılık 2019 kamyonet 0532 123 45 67'));

        // Kural artık "Ostimden Gebze OSB'ye" rotasını sözlükle çözer; yapay zeka gerekmez.
        $parsed = app(AiParserService::class)->parseCheap("Ostimden Gebze OSB'ye 24 ton mega tenteli 0532 123 45 67");
        $this->assertTrue($parsed['success']);
        $this->assertSame([6, 41], [TurkishLocations::resolve($parsed['pickup_location'])['province_code'], TurkishLocations::resolve($parsed['delivery_location'])['province_code']]);

        // "ilan değil" ifadesi alımda kota harcamadan eler.
        $this->source();
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => 'Satılık 2019 kamyonet temiz 0532 123 45 67', 'message_id' => 's1', 'source_jid' => 'notif:grup-a']);
        $this->assertSame(['filtered', 'lexicon_not_load'], [$r['status'], $r['reason']]);
    }

    public function test_classifier_learns_from_decisions_and_scores_new_messages(): void
    {
        $c = app(LocalClassifier::class);
        $this->assertNull($c->score('Ankara İzmir 24 ton tenteli 0532 123 45 67'), 'Örnek yokken karar vermez');

        $ads = ['Ankara İzmir 24 ton tenteli tır lazım 0532', 'Bursa Konya 10 ton parsiyel kamyon 0533', 'Adana Mersin palet yük kırkayak 0534', 'Denizli Afyon 3 araç kapalı tır 0545', 'İzmir Ankara 20 ton frigo 0536'];
        $chat = ['Hayırlı işler arkadaşlar bol kazançlar', 'Satılık 2018 model kamyonet temiz 0533', 'Şoför arıyorum SRC belgeli 0532', 'Günaydın herkese iyi çalışmalar', 'Gruba hoş geldiniz reklam yasaktır'];
        for ($i = 0; $i < LocalClassifier::MIN_DOCS; $i++) {
            $c->train($ads[$i % 5].' '.$i, true);
            $c->train($chat[$i % 5].' '.$i, false);
        }
        $stats = $c->stats();
        $this->assertTrue($stats['ready']);
        $this->assertSame([LocalClassifier::MIN_DOCS, LocalClassifier::MIN_DOCS], [$stats['docs_load'], $stats['docs_other']]);

        $this->assertGreaterThan(0.9, $c->score('Samsun Trabzon 15 ton tenteli tır lazım 0537 111 11 11'));
        $this->assertLessThan(0.1, $c->score('Satılık temiz kamyonet arkadaşlar hayırlı işler'));
    }

    public function test_approve_reject_and_edit_teach_the_system(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        $service->approve($this->candidate($source), $admin->id);
        $service->reject($this->candidate($source, ['raw_message' => 'Satılık kamyonet 0532 123 45 67']), $admin->id);
        $service->reject($this->candidate($source, ['raw_message' => 'Otomatik red örneği 0532 123 45 67']), null); // otomatik/sistem: öğrenmez
        $this->assertSame([1, 1], [Settings::int('ai_local_docs_load'), Settings::int('ai_local_docs_other')]);

        // Yönetici düzenlemesi: "Çumra" (Konya sanayi bölgesi; katalogda yok) → Konya öğrenilir; araç tipi önerisi düşer.
        $load = $this->candidate($source, ['raw_message' => 'Büsan sanayi - İzmir 12 ton açık kasa 0532 123 45 67', 'pickup_location' => 'Büsan sanayi', 'pickup_province_code' => null, 'vehicle_type' => null]);
        $this->actingAs($admin->fresh());
        Volt::test('admin.scrapers-center')
            ->call('startEdit', $load->id)
            ->set('edit.pickup_province_code', 42)->set('edit.pickup_district', '')
            ->set('edit.delivery_province_code', 35)->set('edit.delivery_district', '')
            ->set('edit.vehicle_type', '6_teker_kamyon')
            ->call('saveEdit')->assertHasNoErrors();

        $learned = AiLexicon::query()->where('kind', 'location')->where('term', 'busan sanayi')->first();
        $this->assertNotNull($learned);
        $this->assertSame(['Konya', 'learned', 'active'], [$learned->canonical, $learned->source, $learned->status]);
        $this->assertSame(42, TurkishLocations::resolve('Büsan sanayi')['province_code']);
        $this->assertSame(1, AiLexicon::query()->where('kind', 'vehicle')->where('status', 'suggested')->where('canonical', '6_teker_kamyon')->count());

        // Öneriyi sözcükle öğret → sonraki mesajda kural uygular.
        $sg = AiLexicon::query()->where('status', 'suggested')->first();
        Volt::test('admin.scrapers-center')->set('activeTab', 'lexicon')->set('suggestTerm.'.$sg->id, 'açık kasa')->call('acceptSuggestion', $sg->id)->assertHasNoErrors();
        $this->assertSame('6_teker_kamyon', VehicleTypes::detect('Konya İzmir 12 ton açık kasa 0532')['type']);
    }

    public function test_local_confidence_keeps_auto_approval_running_when_external_ai_is_down(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '1');
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('scraper_local_min_confidence', '90');
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        $pending = $this->candidate($source, ['ai_status' => 'pending']);
        $this->assertSame('yapay zeka doğrulaması bekleniyor', $service->autoApprovalBlocker($pending));

        // Yerel sınıflandırıcı yüksek güven verdiyse yapay zeka beklenmeden onaylanır.
        $confident = $this->candidate($source, ['ai_status' => 'pending', 'parse_metadata' => ['local_confidence' => 0.97]]);
        $this->assertNull($service->autoApprovalBlocker($confident));
        $low = $this->candidate($source, ['ai_status' => 'pending', 'parse_metadata' => ['local_confidence' => 0.6]]);
        $this->assertSame('yapay zeka doğrulaması bekleniyor', $service->autoApprovalBlocker($low));

        Settings::set('scraper_local_enabled', '0');
        $this->assertSame('yapay zeka doğrulaması bekleniyor', $service->autoApprovalBlocker($confident));

        // Uzun süredir bekleyen aday "ulaşılamadı; elle kontrol" der.
        ScrapedLoad::whereKey($pending->id)->update(['created_at' => now()->subHours(5)]);
        $this->assertSame('yapay zeka ulaşılamadı; elle kontrol', $service->autoApprovalBlocker($pending->fresh()));
    }

    public function test_local_ollama_model_is_first_in_chain_when_enabled(): void
    {
        $parser = app(AiParserService::class);
        $this->assertNotContains('ollama', $parser->chain());

        Settings::set('ai_ollama_enabled', '1');
        Settings::set('ai_ollama_model', 'qwen3:4b');
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test'); // dış sağlayıcı da var; yerel önce
        $this->assertSame('ollama', $parser->chain()[0]);
        $this->assertSame('http://127.0.0.1:11434/v1', $parser->baseUrl('ollama'));

        $ad = ['post_type' => 'load', 'confidence' => 0.9, 'phones' => ['5321234567'], 'excerpt' => null, 'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'notes' => null];
        Http::fake(['127.0.0.1:11434/*' => Http::response(['choices' => [['message' => ['content' => "<think>kısa düşünce</think>\n".json_encode(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [$ad]])]]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120]])]);

        $this->source();
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => 'Ostimden Aliağaya palet yükümüz var tır lazım 0532 123 45 67', 'message_id' => 'o1', 'source_jid' => 'notif:grup-a']);

        $this->assertSame('created', $r['status']);
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'http://127.0.0.1:11434/v1/chat/completions') && $req['model'] === 'qwen3:4b' && str_ends_with($req['messages'][1]['content'], '/no_think'));
        Http::assertSentCount(1);
        $load = ScrapedLoad::first();
        $this->assertSame(['ollama', 'done', 'Ankara'], [$load->parse_metadata['ai']['provider'], $load->ai_status, $load->pickup_location]);
        $this->assertSame('qwen3:4b', AiParserService::pickModel('ollama', ['llama3.2:3b', 'qwen3:4b', 'nomic-embed-text']));
    }
}
