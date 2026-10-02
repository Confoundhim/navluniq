<?php

namespace Tests\Feature\Services;

use App\Models\AiLexicon;
use App\Models\AiTemplate;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LearningService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\LocalClassifier;
use App\Services\TemplateMemory;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026-10-01 konum denetimi: açık yazılmış bir yerin yanlış yere gitmesine yol açan 17 durum (eş adlı ilçeler, il-ilçe
 * sırası, bitişik yazım, rol sözcükleri, hal ekleri, gündelik sözcük/firma adı olan ilçe adları, şablon ve sözlük
 * öğrenmesinin doğru kuralı ezmesi, yeniden konumlamanın yapay zeka sonucunu bozması).
 */
class LocationAuditTest extends TestCase
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

    /** @return iterable<string, array{string, string, string}> mesaj, beklenen kalkış, beklenen varış */
    public static function routes(): iterable
    {
        // Eş adlı ilçe: nakliyede kastedilen il (Ankara Gölbaşı, İzmir Kemalpaşa, Konya Ereğli, Kayseri Pınarbaşı)
        yield 'kemalpasa-bursa' => ['KEMALPAŞA - BURSA 0532 111 22 33', 'İzmir Kemalpaşa', 'Bursa'];
        yield 'kemalpasadan' => ['Kemalpaşadan Bursaya 0532 111 22 33', 'İzmir Kemalpaşa', 'Bursa'];
        yield 'golbasi-istanbul' => ['Gölbaşı - İstanbul 0532 111 22 33', 'Ankara Gölbaşı', 'İstanbul'];
        yield 'pinarbasi-gercek-ilce' => ['Pınarbaşı - Ankara 0532 111 22 33', 'Kayseri Pınarbaşı', 'Ankara'];
        // İlçe önce, il sonra
        yield 'golbasi-ankara' => ['Gölbaşı Ankara - İstanbul 0532 111 22 33', 'Ankara Gölbaşı', 'İstanbul'];
        yield 'kemalpasa-izmir-fiil' => ['Kemalpaşa İzmir yükleme Ankara boşaltma 0532 111 22 33', 'İzmir Kemalpaşa', 'Ankara'];
        yield 'eregli-zonguldak' => ['Ereğli Zonguldak - Ankara 0532 111 22 33', 'Zonguldak Ereğli', 'Ankara'];
        yield 'kemalpasa-slash-izmir' => ['Kemalpaşa/İzmir - Ankara 0532 111 22 33', 'İzmir Kemalpaşa', 'Ankara'];
        // Bitişik il-ilçe tek yerdir
        yield 'ankara-sincan-bitisik' => ['ANKARA-SİNCAN - İZMİR 0532 111 22 33', 'Ankara Sincan', 'İzmir'];
        yield 'mersin-tarsus-virgul' => ['Mersin-Tarsus, Kayseri 0532 111 22 33', 'Mersin Tarsus', 'Kayseri'];
        yield 'izmir-kemalpasa-ok' => ['İzmir-Kemalpaşa > Bursa 0532 111 22 33', 'İzmir Kemalpaşa', 'Bursa'];
        // Gündelik sözcük olan ilçe adları
        yield 'persembe' => ['Perşembe yükleme Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'aralik' => ['15 Aralık yükleme Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'olur' => ['Tır olur kamyon olur Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'orta-boy' => ["Konya\nOrta boy kamyon olur\nBursa 0532 111 22 33", 'Konya', 'Bursa'];
        yield 'torbali-cimento' => ['Konya torbalı çimento Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'kiraz-yuku' => ['Kiraz yükü Isparta İstanbul 0532 111 22 33', 'Isparta', 'İstanbul'];
        yield 'kavak-tomruk' => ['Kavak tomruk Düzce Kayseri 0532 111 22 33', 'Düzce', 'Kayseri'];
        yield 'maden-yuku' => ['Maden yükü Sivas Mersin 0532 111 22 33', 'Sivas', 'Mersin'];
        yield 'bor-madeni' => ['Bor madeni Eskişehir Mersin 0532 111 22 33', 'Eskişehir', 'Mersin'];
        yield 'hal-pazari' => ['Hal pazarı Antalya İstanbul 0532 111 22 33', 'Antalya', 'İstanbul'];
        yield 'misir-yuku' => ['Mısır yükü Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'dokme-misir' => ["Konya\nDökme mısır\n24 ton\nBursa 0532 111 22 33", 'Konya', 'Bursa'];
        // Kişi ve firma adları
        yield 'can-bey' => ['Can Bey Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'kartal-nakliyat' => ["Kartal Nakliyat\nKonya Ankara 0532 111 22 33", 'Konya', 'Ankara'];
        yield 'demirci-lojistik' => ['Demirci Lojistik Konya Ankara 0532 111 22 33', 'Konya', 'Ankara'];
        yield 'karahan' => ["KARAHAN LOJİSTİK\nAnkara İzmir 0532 111 22 33", 'Ankara', 'İzmir'];
        yield 'anadolu-nakliyat' => ['ANADOLU NAKLİYAT Konya Mersin 0532 111 22 33', 'Konya', 'Mersin'];
        yield 'tupras' => ['Tüpraş Kırıkkale - Ankara 0532 111 22 33', 'Kırıkkale', 'Ankara'];
        yield 'oyak' => ['OYAK ÇİMENTO Mardin - Batman 0532 111 22 33', 'Mardin', 'Batman'];
        yield 'limak' => ['Limak çimento Ankara Konya 0532 111 22 33', 'Ankara', 'Konya'];
        // Yazım hatası toleransı yalnız son çare
        yield 'hatasi' => ['Hatası yok Konya Bursa 0532 111 22 33', 'Konya', 'Bursa'];
        yield 'samsung' => ['SAMSUNG beyaz eşya Manisa İstanbul 0532 111 22 33', 'Manisa', 'İstanbul'];
        yield 'bilesik' => ['Bileşik gübre Mersin Konya 0532 111 22 33', 'Mersin', 'Konya'];
        yield 'haftaya' => ['Haftaya Ankara - İzmir 0532 111 22 33', 'Ankara', 'İzmir'];
        yield 'yemlik-arpa' => ['Ankara - Bursa yemlik arpa 0532 111 22 33', 'Ankara', 'Bursa'];
        yield 'saman' => ['Konya - Kırşehir saman 0532 111 22 33', 'Konya', 'Kırşehir'];
        yield 'sebze' => ['Ankara - Kocaeli sebze 0532 111 22 33', 'Ankara', 'Kocaeli'];
        yield 'kesme-cicek' => ['Antalya - İzmir kesme çiçek 0532 111 22 33', 'Antalya', 'İzmir'];
        // Rol sözcükleri ve hal ekleri yönü belirler
        yield 'teslim-virgul' => ['İzmir teslim, Bursadan yüklenir 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'teslim-tire' => ['İzmir teslim - Bursa yükleme 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'teslim-basta' => ['Teslim İzmir Yükleme Bursa 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'teslim-iki-nokta' => ['Teslim: İzmir Yükleme: Bursa 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'varis-cikis' => ['Varış İzmir çıkış Bursa 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'dative-ablative' => ['İzmire gidecek Bursadan 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'icin-ablative' => ['İzmir için Bursadan 0532 111 22 33', 'Bursa', 'İzmir'];
        yield 'locative-not-direction' => ['Bursada yükleme var İzmire 0532 111 22 33', 'Bursa', 'İzmir'];
        // Etiketler ve gün adı ilçeler
        yield 'ordu-persembe' => ['Bursa - Ordu Perşembe 0532 111 22 33', 'Bursa', 'Ordu Perşembe'];
        yield 'kapikule-label' => ['Kapıkule - Ankara 0532 111 22 33', 'Edirne', 'Ankara'];
        yield 'derekoy-label' => ['Bursa - Dereköy 0532 111 22 33', 'Bursa', 'Kırklareli'];
        // Eskiden doğru çözülenler bozulmadı
        yield 'avcilar-cubuk' => ['Avcılar Liman yükleme Ankara Çubuk teslim 0532 111 22 33', 'İstanbul Avcılar', 'Ankara Çubuk'];
        yield 'ostim-aliaga' => ['Ostimden Aliağaya palet yükümüz var 0532 111 22 33', 'Ankara Yenimahalle', 'İzmir Aliağa'];
        yield 'gebze-ankara' => ['Gebze - Ankara 24 ton 0532 111 22 33', 'Kocaeli Gebze', 'Ankara'];
        yield 'bolu-oyak' => ['Elmadağ (Ank) - Bolu (Oyak) 12 ton 0532 111 22 33', 'Ankara Elmadağ', 'Bolu'];
        yield 'rize-pazar' => ['Rize Pazar yükleme Ankara 0532 111 22 33', 'Rize Pazar', 'Ankara'];
    }

    #[DataProvider('routes')]
    public function test_clearly_written_places_resolve_to_the_right_route(string $message, string $pickup, string $delivery): void
    {
        $r = app(AiParserService::class)->parseCheap($message);
        $label = fn ($v) => TurkishLocations::label(TurkishLocations::resolve($v)) ?? $v;
        $this->assertSame([$pickup, $delivery], [$label($r['pickup_location'] ?? null), $label($r['delivery_location'] ?? null)], $message);
    }

    public function test_ordinary_words_and_regions_are_not_places(): void
    {
        foreach (['Avrupa', 'Anadolu', 'Ada', 'Mısır', 'saman', 'sebze', 'hafif', 'değil'] as $w) {
            $this->assertNull(TurkishLocations::resolve($w), $w);
        }
        // Gerçek ilçe adı olan gündelik sözcükler katalogda kalır ("Ordu Perşembe" il ile yazılınca çözülür) ama metin taramasında tek başına yer sayılmaz
        foreach (['Perşembe', 'Olur', 'Orta', 'Akdeniz', 'Marmara', 'Kiraz', 'Torbalı', 'Maden'] as $w) {
            $this->assertSame([], AiParserService::placesIn($w.' yükleme var'), $w);
        }
        $this->assertSame('Ordu Perşembe', AiParserService::placesIn('Ordu Perşembe yükleme')[0]['label']);
        $this->assertNull(app(AiParserService::class)->parseCheap('Bursa - Avrupa 0532 111 22 33')['delivery_location'] ?? null, 'Avrupa İstanbul değildir');
        $this->assertSame('Ankara', TurkishLocations::label(TurkishLocations::resolve('Sinan Bey: Ankara')), 'kişi adı yer sayılmaz, il kazanır');
        $this->assertSame(['Kayseri Pınarbaşı', 'Ankara Gölbaşı', 'İzmir Kemalpaşa', 'Denizli Kale', 'Rize Pazar'],
            array_map(fn ($w) => TurkishLocations::label(TurkishLocations::resolve($w)), ['Pınarbaşı', 'Gölbaşı', 'Kemalpaşa', 'Kale', 'Pazar']));
        $this->assertTrue(TurkishLocations::isAmbiguousDistrict('Kemalpaşa'));
        $this->assertFalse(TurkishLocations::isAmbiguousDistrict('Gebze'));
    }

    public function test_two_pickup_header_series_and_destination_lists_without_pickup(): void
    {
        $this->source();
        // "GEBZE+TUZLA YÜKLER": iki kalkış seçeneği × iki varış satırı ("ANKARA 2 YER KAPALI TIR") = dört ilan, her biri 2 araç
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => "GEBZE+TUZLA YÜKLER\n\nANKARA 2 YER KAPALI TIR\nANTALYA 2 YER KAPALI TIR\n\nAD SOYAD\n05341112233\nFİRMA LOJİSTİK", 'message_id' => 's1', 'source_jid' => 'notif:grup-a']);
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $loads = ScrapedLoad::query()->orderBy('id')->get();
        $this->assertSame([['Kocaeli Gebze', 'Ankara'], ['İstanbul Tuzla', 'Ankara'], ['Kocaeli Gebze', 'Antalya'], ['İstanbul Tuzla', 'Antalya']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame(['tir', ['kapali'], 2], [$loads[0]->vehicle_type, $loads[0]->body_types, $loads[0]->vehicle_count]);
        $this->assertStringContainsString('Kalkış: İstanbul Tuzla', $loads[1]->raw_message);

        // Kalkışı yazılmayan "varış + araç" listesi: satırlar birbirine rota diye bağlanmaz ("Samsun → İzmir" uydurulmaz), elenir
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => "SAMSUN KAPALI TIR\nİZMİR KAPALI TIR\nÇANAKKALE TENTELİ KAMYON\nİZMİR ÖDEMİŞ KAPALI KAMYON\n\n☎️ AD 0538 111 22 33", 'message_id' => 's2', 'source_jid' => 'notif:grup-a']);
        $this->assertSame(['filtered', 'pickup_missing'], [$r['status'], $r['reason']]);
        $this->assertSame(4, ScrapedLoad::query()->count());

        // Kalkış fiiliyle yazılmış tek satırlık ilan eskisi gibi: "Gebze yükler- Muğla Menteşe" ikinci yer varıştır
        $p = app(AiParserService::class)->parseCheap('Gebze yükler- Muğla Menteşe 0532 111 22 33');
        $this->assertSame(['Gebze', 'Muğla Menteşe'], [$p['pickup_location'], $p['delivery_location']]);
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    public function test_plus_chains_keep_the_main_destination_and_ignore_non_places(): void
    {
        $std = app(LoadStandardizer::class);
        $this->assertSame(['Bursa', 'Ankara Gölbaşı', 'İzmir Kemalpaşa'], $std->deliveryStops('Ankara - Bursa+Gölbaşı+Kemalpaşa 0532 111 22 33', 'Bursa', 'Ankara'));
        $this->assertSame([], $std->deliveryStops('tenteli+kapalı araç olur Ankara İzmir 0532 111 22 33', 'İzmir', 'Ankara'));
    }

    public function test_admin_approval_does_not_learn_header_words_as_aliases(): void
    {
        $learning = app(LearningService::class);
        $learning->learnLocations('ÇOK ACİL - KONYA - MERSİN 24 ton tenteli 0532 111 22 33', 'Konya', 'Mersin');
        $learning->learnLocations('VİA & CEYFA LOJİSTİK - Konya - Mersin 24 ton 0532 111 22 33', 'Konya', 'Mersin');
        $this->assertSame(0, AiLexicon::query()->where('kind', 'location')->count(), 'başlık çifti ("çok" → "acil") rota değildir, öğrenilmez');

        // Gerçek jargon yine öğrenilir: bir ucu bilinen rotayla örtüşen çift
        $learning->learnLocations('Yükkent sanayi - İzmir 12 ton 0532 111 22 33', 'Konya', 'İzmir');
        $this->assertSame('Konya', AiLexicon::query()->where('kind', 'location')->where('term', 'yukkent')->value('canonical'));
        $this->assertSame(42, TurkishLocations::resolve('Yükkent sanayi')['province_code']);
    }

    public function test_template_memory_does_not_override_a_correct_rule_result(): void
    {
        $tm = app(TemplateMemory::class);
        $parser = app(AiParserService::class);
        $phone = '5321112233';
        $learned = 'Kayseri - Ankara Sincan 20 ton 0532 111 22 33';
        $this->assertNotNull($tm->learn($phone, $learned, 'Kayseri', 'Ankara Sincan', 'tir', null, true, 1.0));

        // Aynı kalıp, başka rota: kural doğru okuyor (Kayseri Develi → Ankara); kalıp onu bozmaz
        $msg = 'Kayseri Develi - Ankara 20 ton 0532 111 22 33';
        $sig = TemplateMemory::signature($msg);
        $this->assertSame(['Kayseri Develi', 'Ankara'], array_column($sig['locations'], 'label'), 'il + ilçe tek yer');
        $template = $tm->find($phone, $sig['hash']);
        $this->assertNotNull($template, 'aynı kalıp');
        $applied = $tm->apply($template, $sig, $parser->parseCheap($msg));
        $this->assertSame(['Kayseri Develi', 'Ankara'], [$applied['pickup_location'], $applied['delivery_location']]);

        // Kişi adı {yer} olmaz ("Sinan" Sincan değildir); yer geçen kalıp "ilan değil" diye öğrenilmez
        $this->assertSame([], TemplateMemory::signature('Sinan Bey arayın 0532 111 22 33')['locations']);
        $this->assertNull($tm->learn($phone, 'Ankara İzmir 24 ton bulundu kapandı 0532 111 22 33', null, null, null, null, false, 0.9));

        // Yönetici onayıyla öğrenilmiş kalıp, yapay zekanın daha düşük güvenli çözümüyle ezilmez
        $again = $tm->learn($phone, $learned, 'Kayseri', 'Ankara Sincan', null, null, true, 0.85);
        $this->assertSame(1.0, (float) $again->confidence);
        $this->assertSame('tir', $again->vehicle_type);
        $this->assertSame(1, AiTemplate::query()->count());
    }

    public function test_forced_relocation_keeps_ai_result_for_ambiguous_districts_and_skips_series(): void
    {
        $source = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $base = ['scraper_id' => $source->id, 'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'status' => 'parsed_success', 'visibility' => 'public',
            'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'ai_status' => 'done', 'parse_confidence' => 0.9];
        $std = app(LoadStandardizer::class);

        // Yapay zeka "Zonguldak Ereğli" dedi; kural tek başına "Ereğli"yi Konya okur: belirsiz ilçe yapay zekayı ezmez
        $ai = ScrapedLoad::create($base + ['content_hash' => 'a1', 'raw_message' => 'Ereğli - Ankara 24 ton 0532 111 22 33', 'pickup_location' => 'Zonguldak Ereğli', 'pickup_province_code' => 67, 'pickup_district' => 'Ereğli']);
        $this->assertFalse($std->relocateFromRaw($ai, force: true));
        $this->assertSame(67, (int) $ai->fresh()->pickup_province_code);

        // İl adı metinde yazılıysa kural kesindir: zehirli sözlükle "İzmir Torbalı" olmuş kayıt Ankara'ya döner ve iz bırakır
        $poisoned = ScrapedLoad::create($base + ['content_hash' => 'a2', 'raw_message' => 'Ankara - Konya 24 ton 0532 111 22 33', 'pickup_location' => 'İzmir Torbalı', 'pickup_province_code' => 35, 'delivery_location' => 'Konya', 'delivery_province_code' => 42]);
        $this->assertTrue($std->relocateFromRaw($poisoned, force: true));
        $poisoned->refresh();
        $this->assertSame([6, 'İzmir Torbalı'], [(int) $poisoned->pickup_province_code, $poisoned->parse_metadata['relocated_from']['pickup']]);

        // Seri ilan parçası ham mesajdan yeniden okunamaz
        $series = ScrapedLoad::create($base + ['content_hash' => 'a3', 'raw_message' => "KONYA YÜKLER\nGÖLBAŞI BOŞALTIR\nKEMALPAŞA BOŞALTIR\n0532 111 22 33", 'pickup_location' => 'Konya', 'pickup_province_code' => 42,
            'delivery_location' => 'İzmir Kemalpaşa', 'delivery_province_code' => 35, 'parse_metadata' => ['series' => true], 'ai_status' => 'skipped']);
        $this->assertFalse($std->relocateFromRaw($series));
    }

    public function test_ai_wins_a_homonym_conflict_when_it_names_the_province(): void
    {
        $merged = app(AiParserService::class)->merge(['pickup_location' => 'Yenişehir', 'delivery_location' => 'Adana'], ['pickup_location' => 'Mersin Yenişehir', 'delivery_location' => 'Adana']);
        $this->assertSame('Mersin Yenişehir', $merged['pickup_location']);
        $this->assertArrayNotHasKey('ai_conflict', $merged);

        $still = app(AiParserService::class)->merge(['pickup_location' => 'Bursa Yenişehir', 'delivery_location' => 'Adana'], ['pickup_location' => 'Mersin Yenişehir', 'delivery_location' => 'Adana']);
        $this->assertSame('Bursa Yenişehir', $still['pickup_location'], 'kural il adını yazdıysa uyuşmazlık kalır, insan bakar');
        $this->assertArrayHasKey('ai_conflict', $still);
    }

    public function test_classifier_learns_only_from_trusted_examples_and_lexicon_short_phrases_are_ignored(): void
    {
        $source = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $make = fn (array $o) => ScrapedLoad::create(array_merge(['scraper_id' => $source->id, 'content_hash' => uniqid(), 'raw_message' => 'Ankara İzmir 24 ton tenteli 0532 123 45 67',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'), 'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'visibility' => 'public'], $o));
        $learning = app(LearningService::class);
        $learning->onApproved($make(['ai_status' => 'skipped', 'auto_approved_at' => now()]), byAdmin: false);
        $this->assertSame(0, Settings::int('ai_local_docs_load'), 'kural puanıyla kendiliğinden yayınlanan aday sınıflandırıcıyı eğitmez');
        $learning->onApproved($make(['ai_status' => 'done', 'parse_confidence' => 0.9, 'is_incomplete' => true]), byAdmin: true);
        $this->assertSame(0, Settings::int('ai_local_docs_load'), 'eksik bilgili ilan örnek değildir');
        $learning->onApproved($make(['ai_status' => 'done', 'parse_confidence' => 0.9]), byAdmin: false);
        $this->assertSame(1, Settings::int('ai_local_docs_load'), 'yapay zekanın çelişkisiz yüksek güvenli çözümü örnek olur');
        $this->assertSame(1, AiTemplate::query()->count(), 'yöneticinin tek tek yayınladığı ilanın kalıbı öğrenilir');
        $learning->onApproved($make(['ai_status' => 'skipped', 'raw_message' => 'Bursa Konya 10 ton kapalı 0532 123 45 67', 'pickup_location' => 'Bursa', 'pickup_province_code' => 16, 'delivery_location' => 'Konya', 'delivery_province_code' => 42]), byAdmin: true, bulk: true);
        $this->assertSame(2, Settings::int('ai_local_docs_load'));
        $this->assertSame(1, AiTemplate::query()->count(), 'toplu yayın kalıp öğrenmez');
        $this->assertSame(0, AiLexicon::query()->count(), 'toplu yayın konum öğrenmez');

        AiLexicon::create(['kind' => 'not_load', 'term' => 'ok', 'canonical' => null, 'status' => 'active', 'source' => 'admin']);
        AiLexicon::create(['kind' => 'not_load', 'term' => 'satılık', 'canonical' => null, 'status' => 'active', 'source' => 'admin']);
        Lexicon::flush();
        $this->assertFalse(Lexicon::isNotLoad('Ankara İzmir ok 24 ton'), 'iki harfli ifade her mesajda geçer, sayılmaz');
        $this->assertTrue(Lexicon::isNotLoad('Satılık kamyonet'));

        $before = (int) Cache::get('ai:lexicon:version', 0);
        Lexicon::flush();
        $this->assertSame($before + 1, (int) Cache::get('ai:lexicon:version'), 'her değişiklik sürümü artırır; işçiler bunu görüp yeniden okur');

        $c = app(LocalClassifier::class);
        $rebuilt = $c->rebuild();
        $this->assertSame(2, $rebuilt['load'], 'yeniden kurulumda yalnız güvenilir olumlu örnekler: yapay zeka kesin ve yönetici yayınları');
    }
}
