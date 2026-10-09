<?php

namespace Tests\Feature\Intake;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LearningService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\NotificationIntakeParser;
use App\Services\ScrapedLoadService;
use App\Support\BodyTypes;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-09 canlı kuyruk dökümü (5.217 bekleyen, 2.065 reddedilen; uydurma örneklerle yeniden yazıldı): bekleyenlerin %99'u "araç tipi yok"
 * engelinde yüksek puanla takılıyordu, reddedilenlerin yarısı ilçesi farklı ilanların tekrar sayılmasıydı, 1.000 adayda sözlükten gelen
 * hayali il vardı, seri ilanlarda kalkış öneki varış sanılıyordu, Facebook grup listesi satırları rota oluyordu.
 */
class QueueAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        Settings::set('ai_parse_mode', 'off');
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function intake(string $text, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:grup-a', 'sender_phone' => null]);
    }

    public function test_vehicle_missing_with_high_score_is_published_as_incomplete_when_vehicle_is_required(): void
    {
        Settings::set('scraper_auto_approve_require_vehicle', 1);
        Settings::set('scraper_incomplete_publish', 1);
        $r = $this->intake("Aydın Nazilli\nAnkara\n0537 111 22 33", 'm1');
        $load = ScrapedLoad::query()->find($r['created_ids'][0]);
        $service = app(ScrapedLoadService::class);
        $this->assertNull($load->vehicle_type);
        $this->assertSame('araç tipi yok', $service->autoApprovalBlocker($load));
        $this->assertGreaterThan(0.25, $service->decision($load)['score']);
        $this->assertTrue($service->incompleteEligible($load), 'zorunlu alan eksikse puan bandına bakılmaz; eksik bilgili yayına gider (eski sürümde kuyrukta süresi dolana kadar bekliyordu)');

        // Puan ret sınırının altındaysa yine yayınlanmaz
        Settings::set('scraper_auto_reject_max_score', 99);
        $this->assertFalse($service->incompleteEligible($load));
    }

    public function test_same_phone_same_province_pair_but_different_districts_are_separate_ads(): void
    {
        $first = $this->intake("Malkara yükler\nAfyon Sandıklı damper 1.400+\n0533 111 22 33", 'm1');
        $this->assertSame('created', $first['status']);
        $load1 = ScrapedLoad::query()->find($first['created_ids'][0]);
        app(ScrapedLoadService::class)->approve($load1, null, true);

        $second = $this->intake("Malkara yükler\nAfyon Emirdağ damper 1.200+\n0533 111 22 33", 'm2');
        $this->assertSame('created', $second['status'], 'ilçesi farklı ikinci yük tekrar değildir');
        $load2 = ScrapedLoad::query()->find($second['created_ids'][0]);
        $this->assertSame(['Afyonkarahisar Emirdağ', 'Emirdağ'], [$load2->delivery_location, $load2->delivery_district]);
        $this->assertNull($load2->meta('supersedes'), 'yerine geçme de değil: ikisi birlikte yayında kalır');
        $this->assertNull(app(ScrapedLoadService::class)->publishedDuplicateOf($load2));
        $this->assertNotSame("tekrar (#{$load1->id} yayında)", app(ScrapedLoadService::class)->autoApprovalBlocker($load2));

        // Aynı ilçe, aynı metin: yine tekrar
        $third = $this->intake("Malkara yükler\nAfyon Emirdağ damper 1.200+\n0533 111 22 33", 'm3');
        $this->assertSame('duplicate', $third['status']);
    }

    public function test_series_lines_prefixed_with_the_pickup_resolve_to_their_destinations(): void
    {
        $r = $this->intake("MERSİN HAYAT KİMYADAN YÜKLER\nMERSİN-SİVAS\nMERSİN-BURSA 3 NOKTA\nMERSİN-İSTANBUL ANADOLU\nMERSİN-K.MARAŞ\n0505 111 22 33", 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame(['Sivas', 'Bursa', 'İstanbul', 'Kahramanmaraş'], $loads->pluck('delivery_location')->all());
        $this->assertSame(['Mersin'], array_values(array_unique($loads->pluck('pickup_location')->all())));

        $r = $this->intake("SAMSUN YÜKLER\nSAMSUN\nARNAVUTKÖY+AVRUPA\n0533 111 22 33", 'm2');
        $load = ScrapedLoad::query()->find($r['created_ids'][0]);
        $this->assertSame(['Samsun', 'İstanbul Arnavutköy'], [$load->pickup_location, $load->delivery_location]);
        $this->assertNotContains('same_route_ends', (array) $load->meta('warnings', []));
    }

    public function test_glued_vehicle_spellings_and_truck_availability_messages(): void
    {
        $this->assertSame('tir', VehicleClassifier::analyze('AYDIN TIRkapalı')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('FRIGOTIR -18')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('ARAÇLAR DAMPERDORSE OLACAKTIR')['type']);
        $this->assertSame('kamyonet', VehicleClassifier::analyze('KAPALI KAMYONONET')['type']);
        $this->assertSame('10_teker_kamyon', VehicleClassifier::analyze('ÇANAKKALE 1O TEKER KAPALI')['type']);
        $this->assertSame('10_teker_kamyon', VehicleClassifier::analyze('Isuzu veya 10 tekere parçada olur')['type']);
        $this->assertSame('10_teker_kamyon', VehicleClassifier::analyze('KAPALI 10TKR')['type']);
        $this->assertNull(VehicleClassifier::analyze('Denizliden urfa 1adet tiraktor')['type'], 'traktör yüktür, tır değil');

        $this->assertTrue(LoadIntakeService::isNotLoadPattern("Yarın Çorluda sabah 09:00'da kamyonetimiz boşa çıkacak yön Avrupa 0530 111 22 33"));
        $this->assertTrue(LoadIntakeService::isNotLoadPattern('Sabah istanbul AVRUPA da boş 10 tk mevcut Denizli çevresi uyar 0542 111 22 33'));
        $this->assertFalse(LoadIntakeService::isNotLoadPattern('Ankara İzmir boş araç var mı 0532 111 22 33'), 'yük sahibi araç arıyor');
    }

    public function test_generic_words_in_the_location_lexicon_are_ignored_and_never_learned(): void
    {
        foreach (['yüklemeli' => 'İzmir Torbalı', 'açık' => 'Denizli Tavas', 'sabah' => 'Kocaeli Kartepe', 'sarar' => 'Aydın Didim'] as $term => $canonical) {
            AiLexicon::create(['kind' => 'location', 'term' => $term, 'canonical' => $canonical, 'status' => 'active', 'source' => 'admin']);
        }
        AiLexicon::create(['kind' => 'location', 'term' => 'ostim', 'canonical' => 'Ankara Yenimahalle', 'status' => 'active', 'source' => 'admin']);
        Lexicon::flush();
        $this->assertNull(TurkishLocations::resolve('yüklemeli'));
        $this->assertNull(TurkishLocations::resolve('açık'));
        $this->assertSame('Ankara', TurkishLocations::resolve('ostim')['province'] ?? null, 'gerçek jargon çalışmaya devam eder');
        $this->assertTrue(LearningService::isNoiseTerm('acik'));
        $this->assertTrue(LearningService::isNoiseTerm('yuklemeli'));
        $this->assertFalse(LearningService::isNoiseTerm('ostim'));

        $r = $this->intake("YARIN YÜKLER\nBUGÜN YÜKLEMELİ\n0530 111 22 33", 'm1');
        $this->assertNotSame('created', $r['status'], 'yer adı olmayan mesaj sözlükten hayali il almaz');
    }

    public function test_group_name_supplies_vehicle_and_body_when_the_ad_does_not(): void
    {
        // Osman (2026-10-09): "13.60 ilanları diye grup var, burada araç belirtmesine gerek yok" — araçsız bekleyen 5.192 adayın 362'si böyle gruplardandı
        Settings::set('scraper_auto_approve_require_vehicle', 1);
        Scraper::create(['name' => '13.60 TÜRKİYE GENELİ', 'type' => 'notification', 'source_identifier' => 'notif:grup-1360', 'is_active' => true]);
        $intake = fn (string $text, string $id) => app(LoadIntakeService::class)->intake(['group_name' => '13.60 TÜRKİYE GENELİ', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:grup-1360', 'sender_phone' => null]);

        $load = ScrapedLoad::query()->find($intake("Konya Ereğli'den İstanbul Tuzla yükleme var\n0532 111 22 33", 'g1')['created_ids'][0]);
        $this->assertSame(['tir', 'ai_guess', true], [$load->vehicle_type, $load->vehicle_type_source, $load->vehicle_type_source !== null], 'grup adı aracı söyler; tahmin olarak yazılır (filtre yumuşak uygular)');
        $this->assertSame('tir', $load->meta('vehicle_from_group'));
        $this->assertContains('uzun_dorse', (array) $load->body_types);
        $this->assertSame('group', $load->body_type_source);
        $this->assertNotSame('araç tipi yok', app(ScrapedLoadService::class)->autoApprovalBlocker($load), 'araç zorunluluğu grup aracıyla karşılanır: eksik bilgili değil normal yayın');

        // İlanda açıkça yazan araç ve "fark etmez" grubu ezer; uyumsuz kasa (dorse boyu kamyonete) yazılmaz
        $small = ScrapedLoad::query()->find($intake("Konya Ereğli'den İstanbul Tuzla kamyonet lazım\n0532 111 22 34", 'g2')['created_ids'][0]);
        $this->assertSame(['kamyonet', 'keyword', null], [$small->vehicle_type, $small->vehicle_type_source, $small->body_types]);
        $any = ScrapedLoad::query()->find($intake("Konya Ereğli'den İstanbul Tuzla araç fark etmez\n0532 111 22 35", 'g3')['created_ids'][0]);
        $this->assertTrue((bool) $any->vehicle_any);
        $this->assertNull($any->vehicle_type);

        // Yeniden standartlaştırma da grubu bilir (güncelleme sonrası scraped-loads:classify eski kayıtları doldurur)
        $load->forceFill(['vehicle_type' => null, 'vehicle_type_source' => null, 'body_types' => null, 'body_type_source' => null])->save();
        app(LoadStandardizer::class)->restandardize($load->fresh());
        $this->assertSame('tir', $load->fresh()->vehicle_type);

        // Grup adı okuması: kasa adlı gruplar ağır araç, "Tire" ilçesi araç değil, parça grubu yük biçimi verir
        $this->assertSame(['tir', ['damperli'], null], array_values(VehicleClassifier::fromGroupName('TÜRKİYE DAMPER')));
        $this->assertSame(['tir', ['kisa_dorse', 'uzun_dorse'], null], array_values(VehicleClassifier::fromGroupName('42 KONYA KISA DORSE 13-60 🇹🇷')));
        $this->assertSame('panelvan', VehicleClassifier::fromGroupName('İstanbul Minivan ve Panelvan 7/24 2')['vehicle']);
        $this->assertSame('6_teker_kamyon', VehicleClassifier::fromGroupName('Konya Kamyon Garajı 4')['vehicle']);
        $this->assertSame([null, [], 'parca'], array_values(VehicleClassifier::fromGroupName('YAĞIZ NAK PARÇA YÜKLER 3.')));
        $this->assertNull(VehicleClassifier::fromGroupName('TİRE NAKLİYECİLER')['vehicle']);
        $this->assertNull(VehicleClassifier::fromGroupName('TÜRKİYE GENELİ NAKLİYAT')['vehicle']);
        $this->assertNull(VehicleClassifier::fromGroupName('Kısa Mesafe Nakliye')['vehicle']);
    }

    public function test_trailer_length_words_body_options_and_space_in_metres(): void
    {
        // Tek başına "uzun / kısa" dorse boyudur → TIR; "uzun yol", "kısa mesafe", "en kısa sürede" boy değildir
        $this->assertSame(['tir', 'hint'], [VehicleClassifier::analyze('SİVAS TİREBOLU UZUN')['type'], VehicleClassifier::analyze('SİVAS TİREBOLU UZUN')['source']]);
        $this->assertSame('tir', VehicleClassifier::analyze('ÇORLU KISA')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('Gemerek -> Erbaa uzun kısa 2.100tl')['type']);
        $this->assertContains('kisa_dorse', BodyTypes::detect(VehicleClassifier::normalize('ÇORLU KISA'))['types']);
        $this->assertContains('uzun_dorse', BodyTypes::detect(VehicleClassifier::normalize('Erbaa uzun'))['types']);
        $this->assertNull(VehicleClassifier::analyze('Konya uzun yol 3 ay şoför aranıyor')['type']);
        $this->assertNull(VehicleClassifier::analyze('kısa mesafe 20 km en kısa sürede')['type']);
        $this->assertSame([], BodyTypes::detect(VehicleClassifier::normalize('en kısa sürede uzun yola'))['types']);

        // Kasa seçenekleri sayan ilan dorse ister: "Kapalı/Açık" (normalize "/" işaretini boşluk yapar, eski kalıp hiç eşleşmiyordu), "fark etmez", "olur"
        $this->assertSame('tir', VehicleClassifier::analyze('Adana - Antalya Kapalı/Açık')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('Sakaryadan ankara bir araç lazım açık kapalı fark etmez')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('1 araç kısa uzun olur')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('kapalı tentenli yük üstü olur')['type']);
        $this->assertTrue(VehicleClassifier::anyVehicle(VehicleClassifier::normalize('Her araca uyar')));
        $this->assertTrue(VehicleClassifier::anyVehicle(VehicleClassifier::normalize('tüm araçlara açık')));
        $this->assertFalse(VehicleClassifier::anyVehicle(VehicleClassifier::normalize('açık kapalı fark etmez')), 'kasa fark etmez ≠ araç fark etmez');

        // "basar" (tonajını basar), "yük üstü" (açık kasa üstü) ağır araç; "Başar Nakliyat" firma adı
        $this->assertSame('tir', VehicleClassifier::analyze('ELAZIĞ MALATYA BASAR')['type']);
        $this->assertNull(VehicleClassifier::analyze('Başar Nakliyat Ankara İzmir')['type']);
        $this->assertSame(['tir', ['acik']], [VehicleClassifier::analyze('AYDIN SÖKE YÜK ÜSTÜ')['type'], BodyTypes::detect(VehicleClassifier::normalize('AYDIN SÖKE YÜK ÜSTÜ'))['types']]);

        // Tam sayı metre kasa boyu; "2 metre yer / 1 metre parsiyel" parça yükün yeri, araç değil; "on tkr" 10 teker; "Tire" ilçesi tır değil
        $this->assertSame('8_teker_kamyon', VehicleClassifier::analyze('Beypazarı 7 metre açık')['type']);
        $this->assertSame('10_teker_kamyon', VehicleClassifier::analyze('Esenyurt 8 metre')['type']);
        $this->assertNull(VehicleClassifier::analyze('Antep-Bingöl 2 metre yer')['type']);
        $this->assertSame('parca', BodyTypes::detectLoadKind(VehicleClassifier::normalize('Hatay’a 1 metre parsiyel yükümüz var')));
        $this->assertSame('parca', BodyTypes::detectLoadKind(VehicleClassifier::normalize('Kayseri 1.20 mt yer alır')));
        $this->assertSame('10_teker_kamyon', VehicleClassifier::analyze('Efeler on tkr açık')['type']);
        $this->assertNotSame('tir', VehicleClassifier::analyze("Tire'den Ödemiş'e 2 ton")['type']);
        $load = ScrapedLoad::query()->find($this->intake("Tire'den Ödemiş'e 2 ton kuru gıda\n0532 111 22 33", 'm1')['created_ids'][0]);
        $this->assertSame('İzmir Tire', $load->pickup_location);
        $this->assertNotSame('tir', $load->vehicle_type);
    }

    public function test_facebook_group_list_chrome_does_not_become_a_route(): void
    {
        $junk = "NAKLİYE VE YÜK İŞLERİ\n1 yeni gönderi\nGrubu sabitle\nSıralama: En sık ziyaret ettiklerin\nÇORLU TEKİRDAĞ TRAKYA EDİRNE NAKLİYECİLER SİTESİ\nÇORLU TEKİRDAĞ TRAKYA EDİRNE NAKLİYECİLER SİTESİ\n1 yeni gönderi\nGrubu sabitle\nGroup Cover Photo\nAKÇAKALE TIRCILARI topluluk'da Ara\n13 B üye • Günde 10+ gönderi\n0544 111 22 33";
        $clean = NotificationIntakeParser::stripFeedChrome($junk);
        $this->assertSame("NAKLİYE VE YÜK İŞLERİ\n0544 111 22 33", $clean);
        $r = $this->intake($junk, 'm1');
        $this->assertNotSame('created', $r['status'], 'grup adlarındaki iller rota olmaz');

        // Gerçek gönderi metni dokunulmaz
        $this->assertSame("Aliağa adana\n0544 111 22 33", NotificationIntakeParser::stripFeedChrome("Aliağa adana\n0544 111 22 33"));
    }
}
