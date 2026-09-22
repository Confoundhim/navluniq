<?php

namespace Tests\Feature\Services;

use App\Http\Middleware\FirewallMiddleware;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Support\Settings;
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
        Settings::set('ai_parse_mode', 'fill_gaps'); // bu sınıfın eski testleri "kural yeterse yapay zeka yok" varsayımıyla yazıldı
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

    public function test_emoji_formatted_ad_with_promo_footer_is_parsed_by_rules(): void
    {
        $ad = "📍 Ankara\n\n📦 İstanbul\n💰 1200+KDV\n🚚 1 araç beklemesiz\n\n☎️ HEMEN ARA\n+90 505 178 17 61\n\n🔗 Bu ilan ücretli VIP grubundan paylaşılmıştır.\n💬 Ücretli gruba katılmak için VİP GRUBA KATIL butonunu kullanın";
        $p = app(AiParserService::class)->parseCheap($ad);
        $this->assertTrue($p['success']);
        $this->assertSame(['Ankara', 'İstanbul', '5051781761', 1200.0], [$p['pickup_location'], $p['delivery_location'], $p['sender_phone'], (float) $p['price']]);
        $this->assertNull($p['weight'], '"1 araç" tonaj değildir');
    }

    public function test_ai_first_mode_sends_every_message_with_a_phone_to_ai(): void
    {
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_gemini_model', 'gemini-test');
        Settings::set('ai_provider', 'gemini');
        $this->activeSource();
        $intake = app(LoadIntakeService::class);

        // Sohbet: telefonu yok → yapay zekaya gitmeden elenir.
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Selam arkadaşlar hayırlı işler', 'message_id' => 'x1', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame(['filtered', 'phone_missing'], [$r['status'], $r['reason'] ?? null]);
        Http::assertNothingSent();

        // Kuralın "ilan değil" diyeceği ama telefonu olan mesaj: karar yapay zekada (sahte yanıt: Ankara → İzmir yükü).
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Şu paketi Ostimden Aliağaya götürecek biri var mı 0532 123 45 67', 'message_id' => 'x2', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('created', $r['status']);
        Http::assertSentCount(1);
        $this->assertSame('İzmir Aliağa', ScrapedLoad::first()->delivery_location);

        // Yapay zeka "sohbet/satış ilanı" derse (post_type other, yüksek güven) mesaj elenir (ayrı sağlayıcı: Groq).
        Settings::set('ai_provider', 'groq');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_groq_model', 'llama-test');
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['post_type' => 'other', 'confidence' => 0.95, 'sender_phone' => '5330000001', 'pickup' => null, 'delivery' => null, 'goods' => null, 'goods_category' => null, 'vehicle_type' => null, 'vehicle_flexible' => false, 'weight_kg' => null, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => 'satılık araç ilanı'])]]]])]);
        // "Satılık" gibi sabit kalıplar zaten kuralla elenir (kota yok); kalıba uymayan sohbette karar yapay zekanın.
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Satılık 2018 model kamyonet temiz 0533 000 00 01', 'message_id' => 'x3', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame(['filtered', 'not_load_pattern'], [$r['status'], $r['reason'] ?? null]);
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Arkadaşlar Ostimde çay içen var mı gelin 0533 000 00 01', 'message_id' => 'x4', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame(['filtered', 'ai_not_load'], [$r['status'], $r['reason'] ?? null]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.groq.com'));
    }

    public function test_deleted_source_messages_are_ignored_but_counted(): void
    {
        $source = $this->activeSource();
        $source->delete(); // panelden "Sil"
        $intake = app(LoadIntakeService::class);

        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => self::AD, 'message_id' => 'd1', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('source_deleted', $r['status']);
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => self::AD.' tekrar', 'message_id' => 'd2', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('source_deleted', $r['status']);

        $trashed = Scraper::onlyTrashed()->where('source_identifier', '1203630000001@g.us')->first();
        $this->assertNotNull($trashed, 'Kaynak silinmiş kalmalı; ikinci kayıt açılmamalı');
        $this->assertSame(2, $trashed->messages_since_deleted);
        $this->assertNotNull($trashed->last_message_at);
        $this->assertSame(0, ScrapedLoad::count());
        $this->assertSame(1, Scraper::withTrashed()->where('source_identifier', '1203630000001@g.us')->count());

        // Geri alınınca onay bekler; aktif edilince aynı metin yeniden işlenebilir.
        $trashed->restore();
        $trashed->forceFill(['is_active' => true])->save();
        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => self::AD, 'message_id' => 'd3', 'source_jid' => '1203630000001@g.us']);
        $this->assertSame('created', $r['status']);
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

        $first = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Yükümüz hazır, fabrikadan limana palet, tenteli arayanlar 0532 123 45 67', 'message_id' => 'a1', 'source_jid' => '1203630000001@g.us']);
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

    public function test_multi_ad_message_is_split_into_separate_candidates_by_rules(): void
    {
        $this->activeSource();
        $intake = app(LoadIntakeService::class);
        $message = "Ankara - İzmir 24 ton tenteli tır 0532 111 11 11\n\nBursa - Konya 10 ton kamyon 0533 222 22 22\n\nGruba katılmak için: https://ornek.test/grup";

        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => $message, 'message_id' => 'multi1', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertCount(2, $r['created_ids']);
        $this->assertCount(2, $r['segments']);
        Http::assertNothingSent(); // iki ilan da kuralla çözüldü
        $loads = ScrapedLoad::orderBy('id')->get();
        $this->assertSame([['Ankara', 'İzmir', '5321111111'], ['Bursa', 'Konya', '5332222222']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location, $l->plainPhone()])->all());
        $this->assertSame([0, 2], [$loads[0]->meta('message_part')['index'], $loads[0]->meta('message_part')['count']]);
        $this->assertStringNotContainsString('Bursa', $loads[0]->raw_message);

        // Aynı mesaj başka gruptan gelirse iki ilan da tekrar sayılır; yeni kayıt açılmaz.
        $this->activeSource('1203630000002@g.us');
        $again = $intake->intake(['group_name' => 'Diğer Grup', 'raw_message' => $message, 'message_id' => 'multi2', 'source_jid' => '1203630000002@g.us']);
        $this->assertSame('duplicate', $again['status']);
        $this->assertSame(2, ScrapedLoad::count());
        $this->assertSame(2, ScrapedLoad::find($loads[1]->id)->duplicate_count);
    }

    public function test_one_ad_with_two_contacts_keeps_all_phone_numbers(): void
    {
        $this->activeSource();
        $intake = app(LoadIntakeService::class);

        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => "Ankara - İzmir 24 ton tenteli tır lazım\nAhmet 0532 111 11 11\nMehmet 0533 222 22 22", 'message_id' => 'p1', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertSame(1, ScrapedLoad::count());
        $load = ScrapedLoad::first();
        $this->assertSame('5321111111', $load->plainPhone());
        $this->assertSame(['5332222222'], $load->extraPhones());
        $this->assertSame(['5321111111', '5332222222'], $load->allPhones());
        $this->assertSame(2, $load->meta('phone_count'));
        $this->assertStringNotContainsString('5332222222', json_encode($load->parse_metadata)); // yedek numara da şifreli
    }

    public function test_shared_contact_line_is_inherited_by_every_ad(): void
    {
        $this->activeSource();
        $intake = app(LoadIntakeService::class);

        $r = $intake->intake(['group_name' => 'Test Grubu', 'raw_message' => "Yüklerimiz:\nAnkara - İzmir 24 ton tenteli\nBursa - Konya 10 ton kamyon\nİrtibat 0532 111 11 11", 'message_id' => 's1', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertCount(2, $r['created_ids']);
        $this->assertSame(['5321111111', '5321111111'], ScrapedLoad::orderBy('id')->get()->map(fn ($l) => $l->plainPhone())->all());
        $this->assertSame(['Ankara|İzmir', 'Bursa|Konya'], ScrapedLoad::orderBy('id')->get()->map(fn ($l) => $l->pickup_location.'|'.$l->delivery_location)->all());
    }

    public function test_ai_first_mode_lets_the_ai_split_ads_and_list_every_phone(): void
    {
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_groq_key', 'gsk-test'); // setUp'taki Gemini sahtesi (eski düz şema) öne geçmesin diye ayrı sağlayıcı
        Settings::set('ai_groq_model', 'llama-test');
        Settings::set('ai_provider', 'groq');
        $this->activeSource();
        $message = "Arkadaşlar iki yükümüz var\nAnkara Ostimden İzmire 24 ton palet, tenteli\nDiyarbakırdan İstanbula 10 ton gıda, frigo\nAhmet 0532 111 11 11 Mehmet 0533 222 22 22";
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'post_type' => 'load', 'confidence' => 0.9, 'notes' => null,
            'ads' => [
                ['post_type' => 'load', 'confidence' => 0.92, 'phones' => ['0532 111 11 11', '0533 222 22 22'], 'excerpt' => 'Ankara Ostimden İzmire 24 ton palet, tenteli', 'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'notes' => null],
                ['post_type' => 'load', 'confidence' => 0.88, 'phones' => ['5321111111', '5332222222'], 'excerpt' => 'Diyarbakırdan İstanbula 10 ton gıda, frigo', 'pickup' => ['province' => 'Diyarbakır', 'district' => null], 'delivery' => ['province' => 'İstanbul', 'district' => null], 'goods' => 'gıda', 'goods_category' => null, 'vehicle_type' => 'frigo', 'vehicle_flexible' => false, 'weight_kg' => 10000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'notes' => null],
            ],
        ])]]]])]);

        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Test Grubu', 'raw_message' => $message, 'message_id' => 'ai-multi', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertCount(2, $r['created_ids']);
        Http::assertSentCount(1); // mesajın tamamı için tek çağrı
        $loads = ScrapedLoad::orderBy('id')->get();
        $this->assertSame(['Ankara Yenimahalle|İzmir', 'Diyarbakır|İstanbul'], $loads->map(fn ($l) => $l->pickup_location.'|'.$l->delivery_location)->all()); // Ostim → Yenimahalle
        $this->assertSame(['5321111111', '5332222222'], $loads[0]->allPhones());
        $this->assertSame(['5321111111', '5332222222'], $loads[1]->allPhones());
        $this->assertSame(['done', 'done'], $loads->pluck('ai_status')->all());
        $this->assertSame([0, 1], $loads->map(fn ($l) => $l->meta('ai')['ad_index'])->all());
        $this->assertStringContainsString('Diyarbakırdan İstanbula', $loads[1]->raw_message);
        $this->assertStringContainsString('0532 111 11 11', $loads[1]->raw_message); // alıntıda numara yoktu; eklendi
    }

    public function test_split_segments_handles_common_group_formats(): void
    {
        $emoji = "📍 Ankara\n📦 İstanbul\n💰 1200+KDV\n☎️ +90 505 178 17 61\n🔗 Bu ilan VIP grubundan paylaşılmıştır";
        $this->assertCount(1, LoadIntakeService::splitSegments($emoji));

        $twoEmoji = "📍 Ankara\n📦 İstanbul\n☎️ 0505 178 17 61\n📍 Bursa\n📦 Konya\n☎️ 0506 000 00 00";
        $parts = LoadIntakeService::splitSegments($twoEmoji);
        $this->assertSame([['5051781761'], ['5060000000']], array_column($parts, 'phones'));

        $header = "Ahmet Nakliyat 0532 111 11 11\n\nAnkara İzmir 24 ton\n\nBursa Konya 10 ton";
        $parts = LoadIntakeService::splitSegments($header);
        $this->assertCount(2, $parts);
        $this->assertSame(['5321111111'], $parts[1]['phones']);
        $this->assertStringContainsString('0532 111 11 11', $parts[1]['text']);

        // Aynı rotayı iki satırda anlatan tek ilan bölünmez.
        $this->assertCount(1, LoadIntakeService::splitSegments("Ankara → İstanbul 24 ton tenteli\nAnkara Ostim yükleme İstanbul Kartal teslim\n0532 111 11 11"));
        $this->assertSame(['5321111111', '5332222222'], AiParserService::phonesIn('Ahmet 0532 111 11 11, Mehmet +90 (533) 222-22-22, sabit 0312 444 44 44'));
    }

    public function test_uppercase_headers_and_plus_chains_follow_sector_language(): void
    {
        // Büyük İ'li başlık ("YÜKLEMELİ") tanınır; her satır ayrı ilan; "+" aynı araçla sıralı teslim; "2 YER" = 2 araç
        $msg = "22.09.2026 SALI YÜKLEMELİ İŞLER!\n\n‼️ ÇORLU YÜKLEMELİ İŞLERİMİZ‼️\n\n🚛SAMSUN 2 YER– TIR – 26 TON\n🚛ANKARA – TIR (KAPALI)– 5 TON\n🚛GÖNEN+MERKEZ – TIR – 26 TON\n🚛KÜTAHYA+UŞAK– TIR – 26 TON\n\n‼️ LÜLEBURGAZ YÜKLEMELİ İŞLERİMİZ‼️\n\n🚛ÜMRANİYE – KAMYON – 11 TON\n0532 111 11 11";
        $parts = LoadIntakeService::splitSegments($msg);
        $this->assertCount(5, $parts);
        $std = app(LoadStandardizer::class);
        $parser = app(AiParserService::class);
        $rows = array_map(fn ($p) => $std->standardize($p['text'], $parser->parseCheap($p['text'])), $parts);
        $this->assertSame(['Samsun', 'Ankara', 'Balıkesir Gönen', 'Kütahya', 'İstanbul Ümraniye'], array_column($rows, 'delivery_location'));
        $this->assertSame(['Tekirdağ Çorlu', 'Tekirdağ Çorlu', 'Tekirdağ Çorlu', 'Tekirdağ Çorlu', 'Kırklareli Lüleburgaz'], array_column($rows, 'pickup_location'));
        $this->assertSame(2, $rows[0]['vehicle_count']);
        $this->assertSame('komple', $rows[0]['load_kind']);
        $this->assertSame(['kapali'], $rows[1]['body_types']);
        $this->assertSame(5000, $rows[1]['weight']);
        $this->assertSame(['Balıkesir Gönen', 'Balıkesir Merkez'], $rows[2]['delivery_stops']);
        $this->assertSame(['Kütahya', 'Uşak'], $rows[3]['delivery_stops']);
        $this->assertSame('8_teker_kamyon', $rows[4]['vehicle_type']);

        // Kalkış satırı + alt alta iller: her il ayrı araçlık yük
        $list = LoadIntakeService::splitSegments("İstanbul-Kartal 13.60 açık\nSivas\nAydın\nBursa\nAnkara\nSamsun\n0532 111 11 11");
        $this->assertCount(5, $list);
        $r = $std->standardize($list[2]['text'], $parser->parseCheap($list[2]['text']));
        $this->assertSame(['İstanbul Kartal', 'Bursa'], [$r['pickup_location'], $r['delivery_location']]);
        $this->assertSame(['acik', 'uzun_dorse'], $r['body_types']);
        $this->assertSame(['5321111111'], $list[4]['phones']);

        // İki yer satırı bir rotadır, liste değil
        $this->assertCount(1, LoadIntakeService::splitSegments("Ankara\nİstanbul\n24 ton tenteli\n0532 111 11 11"));

        // "+"lı satırda açık bağlaç varsa yine rotadır
        $r = $std->standardize("Samsun'dan 13.60 tenteneli yükümüz var\nÇorum+Ankara+Denizli\n0532 111 11 11", $parser->parseCheap("Samsun'dan 13.60 tenteneli yükümüz var\nÇorum+Ankara+Denizli\n0532 111 11 11"));
        $this->assertSame(['Samsun', 'Çorum'], [$r['pickup_location'], $r['delivery_location']]);
        $this->assertSame(['Çorum', 'Ankara', 'Denizli'], $r['delivery_stops']);
        $this->assertSame(['tenteli', 'uzun_dorse'], $r['body_types']);
    }

    public function test_route_list_with_district_names_and_one_shared_phone_is_split_per_route(): void
    {
        $this->activeSource();
        $message = "ACİL\nBeykoz-Şanlıurfa 13.60 Açık Tır\nBursa - Gaziantep 13.60 Tır açık kapalı frigolu hepsi olur\nBursa M.Kemalpaşa- İstanbul+Lüleburgaz 13.60 tır\nBugün yükleme 0544 111 11 14";

        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Test Grubu', 'raw_message' => $message, 'message_id' => 'list1', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertCount(3, $r['created_ids']);
        $loads = ScrapedLoad::orderBy('id')->get();
        $this->assertSame([[34, 63], [16, 27], [16, 34]], $loads->map(fn ($l) => [(int) $l->pickup_province_code, (int) $l->delivery_province_code])->all());
        $this->assertSame(['5441111114', '5441111114', '5441111114'], $loads->map(fn ($l) => $l->plainPhone())->all());
    }

    public function test_truncated_or_chatty_model_json_is_recovered(): void
    {
        $truncated = '```json {"post_type":"load","confidence":0.9,"notes":null,"ads":[{"phones":["5321111111"],"excerpt":"abc","pickup":{"province":"Ankara","district":null}},{"phones":["53';
        $this->assertSame('Ankara', AiParserService::decodeLenient($truncated)['ads'][0]['pickup']['province']);
        $this->assertSame(['a' => 1, 'b' => [1, 2]], AiParserService::decodeLenient('İşte JSON: {"a":1,"b":[1,2,],} teşekkürler'));
        $this->assertNull(AiParserService::decodeLenient('JSON yok'));

        // Groq "Failed to validate JSON" (400) gövdesindeki failed_generation kurtarılır; ilan yine kaydedilir.
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_groq_model', 'llama-test');
        Settings::set('ai_provider', 'groq');
        $this->activeSource();
        $ad = ['post_type' => 'load', 'confidence' => 0.9, 'phones' => ['5321234567'], 'excerpt' => null, 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => null, 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => null, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'notes' => null];
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Failed to validate JSON. Please adjust your prompt.', 'type' => 'invalid_request_error', 'code' => 'json_validate_failed', 'failed_generation' => "```json\n".json_encode(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [$ad]])."\n```"]], 400)]);

        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Test Grubu', 'raw_message' => 'Ostimden Aliağaya tır lazım 0532 123 45 67', 'message_id' => 'g400', 'source_jid' => '1203630000001@g.us']);

        $this->assertSame('created', $r['status']);
        $this->assertSame(['done', 'Ankara Yenimahalle', 'İzmir Aliağa'], [ScrapedLoad::first()->ai_status, ScrapedLoad::first()->pickup_location, ScrapedLoad::first()->delivery_location]); // kural ilçe/semti de çözer
        Http::assertSentCount(1);
    }
}
