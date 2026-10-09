<?php

namespace Tests\Feature\Intake;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use App\Services\RuleFeedbackService;
use App\Support\Settings;
use App\Support\VehicleClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-09 yayın dökümü (20.000 yayındaki ilan; uydurma örneklerle yeniden yazıldı): fiyat binlik ayracı, "25.000 TON", şöför/boş araç/sigorta
 * ilan dışı, Facebook profil artığı, kesik grup başlığı, "KAPALI İSUZU", "AÇIK TR", aynı ilin iki ilçesi ters sırada, uygulama gönderisinde
 * iki satırlık ilan + ok satırları, tireli iki kalkışlı başlık, "&" ile iki kalkış, etiketli "+" zincirli kalkış, konum önerisi onayı.
 */
class PublishedAuditTest extends TestCase
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

    public function test_price_thousands_with_comma_or_space_and_kilogram_written_as_ton(): void
    {
        $p = app(AiParserService::class);
        $this->assertSame(15000.0, $p->parseCheap("ÇERKEZKÖY\nADAPAZARI\n10 TKR 15 TON 15,000+KDV\n0532 111 22 33")['price']);
        $this->assertSame(73000.0, $p->parseCheap("Yenibosna>Malatya\n1 tane 1360 kapalı\n73 000+kdv\n0531 111 22 33")['price']);
        $this->assertSame(11000.0, $p->parseCheap("Kazan Kütahya Simav\n6 PALET 5,500 kg 11,000+ KDV\n0552 111 22 33")['price']);
        $this->assertSame(25000, VehicleClassifier::weightFromText(VehicleClassifier::normalize('1 ADET 13.60 KAPALI TİR — 25.000 TON')), 'kilogramı ton diye yazmış');
        $this->assertSame(24000, VehicleClassifier::weightFromText(VehicleClassifier::normalize('24 ton palet')));
    }

    public function test_driver_wanted_empty_truck_offer_and_insurance_ads_are_not_loads(): void
    {
        $this->assertTrue(LoadIntakeService::isNotLoadPattern('Denizli burdur acıpayam şöför arayan 0543 111 22 33'));
        $this->assertTrue(LoadIntakeService::isNotLoadPattern('Kuveyt çalışacak şöför lazım yer Mardin Artuklu iletişim 0542 111 22 33'));
        $this->assertTrue(LoadIntakeService::isNotLoadPattern('Yarin sabah kayseriden izmir tarafina bos arac var renault master acik kasa yuku olan ulasabilir 0552 111 22 33'));
        $this->assertTrue(LoadIntakeService::isNotLoadPattern('Örnek Sigorta olarak sigorta ihtiyaçlarınız için 7/24 hizmet vermekteyiz 0532 111 22 33'));
        $this->assertFalse(LoadIntakeService::isNotLoadPattern('Ankara İzmir 24 ton boş araç lazım 0532 111 22 33'), 'yük sahibi araç arıyor');
    }

    public function test_facebook_profile_chrome_and_truncated_group_header_are_dropped(): void
    {
        $junk = "Facebook'ta arkadaş değilsiniz\n2 ortak arkadaş: Ali Veli ve Örnek Nakliyat\nŞişli Endüstri Meslek Lisesi'de okudu\nArkadaşlık isteği gönderildi\nProfili gör\nMesajlar ve aramalar uçtan uca şifrelemeyle korunur.\nDenizli Konya açık tır 0532 111 22 33";
        $this->assertSame('Denizli Konya açık tır 0532 111 22 33', NotificationIntakeParser::stripFeedChrome($junk));
        $this->assertSame("MERSİNDEN\nİSTANBUL\n(KISA DORSE)\nTEL: 0538 111 22 33", NotificationIntakeParser::stripFeedChrome("MERSİN NAKLİYECİLE…\nMERSİNDEN\nİSTANBUL\n(KISA DORSE)\nTEL: 0538 111 22 33"));
        $this->assertSame('Ankara İzmir tır', NotificationIntakeParser::stripFeedChrome("Örnek Lojistik kanalını takip edin: https://whatsapp.com/channel/abc\nAnkara İzmir tır"));
    }

    public function test_brand_and_abbreviated_vehicle_words(): void
    {
        $this->assertSame('6_teker_kamyon', VehicleClassifier::analyze('BAĞCILAR DAN MARAŞ A KAPALI İSUZU')['type']);
        $this->assertSame('tir', VehicleClassifier::analyze('KONYA YENİCEOBAYA ACIK TR LAZIM')['type']);
    }

    public function test_same_province_districts_in_reverse_order_use_the_other_province_as_delivery(): void
    {
        $load = ScrapedLoad::query()->find($this->intake("İSKENDERUN PAYASTAN\nKAYSERİ\nYÜK KÜSPE\n2 ARAÇ LAZIM KISA DORSE OLACAK\n☎️ 0552 111 22 33", 'm1')['created_ids'][0]);
        $this->assertSame(['Hatay Payas', 'Kayseri'], [$load->pickup_location, $load->delivery_location], 'İskenderun kalkışın niteleyicisidir, varış değil');
        // Gerçek şehir içi iki ilçe (sırası doğru) olduğu gibi kalır
        $city = ScrapedLoad::query()->find($this->intake("İstanbul Kağıthane\nİstanbul Şişli Tenteli Kamyonet 1.500kg\n☎️ 0501 111 22 33", 'm2')['created_ids'][0] ?? 0);
        if ($city !== null) {
            $this->assertSame('İstanbul Kağıthane', $city->pickup_location);
        }
    }

    public function test_app_post_with_two_line_ad_and_arrow_lines_becomes_separate_ads(): void
    {
        $r = $this->intake("Erzurum\nArdahan  TIR 25ton\nEskişehir Odunpazarı -> Balıkesir Altıeylül Kapalı/Tenteli 6 teker 250kg 9m³\n☎️ 0501 111 22 33", 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertCount(2, $loads, 'iki satırlık ilan + ok satırı: iki ayrı ilan (eski sürüm tek ilan sayıp alanları karıştırıyordu)');
        $this->assertSame([['Erzurum', 'Ardahan'], ['Eskişehir Odunpazarı', 'Balıkesir Altıeylül']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame(['tir', '6_teker_kamyon'], $loads->pluck('vehicle_type')->all());
    }

    public function test_headers_with_two_pickups_and_labeled_plus_chain(): void
    {
        $r = $this->intake("ÇORLU - ÇERKEZKÖY YÜKLEMELİ İŞLERİMİZ ‼\nMALATYA 18 TON TIR\n☎️ 0537 111 22 33", 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->get();
        $this->assertCount(1, $loads, 'tireli iki ilçe rota değil kalkış seçeneğidir; başlık kendi başına ilan olmaz');
        $this->assertSame([59, 44], [$loads[0]->pickup_province_code, $loads[0]->delivery_province_code]);

        $amp = ScrapedLoad::query()->find($this->intake("ADANA YÜZBAŞI & ADANA CEYHAN YÜKLER\nKONYA YUNAK\n☎️ 0506 111 22 33", 'm2')['created_ids'][0]);
        $this->assertSame('Konya Yunak', $amp->delivery_location, '"&" ile bağlı iki kalkış: varış sonraki satır');

        // "Yükleme Yeri: A+B+C": hepsi kalkış; varış yazılmamış → tam ilan değildir (eski sürüm B'yi varış sanıyordu)
        $p = app(AiParserService::class)->parseCheap("Yükleme Yeri  :SAKARYA+BAŞİSKELE+DİLOVASI\nAraç cinsi  :KAPALI 10 TEKER\nTonaj  :5-10 TON\niletişim  :0535 111 22 33");
        $this->assertNotSame('Kocaeli Başiskele', $p['delivery_location']);

        // "KIRŞEHİR-Kaman": il ile kendi ilçesi arasındaki tire rota değil; "Kaman" ilçe adıdır, "K+aman" kısaltmasıyla Karaman olmaz
        $k = app(AiParserService::class)->parseCheap("CEYHAN YÜKLEME\nKIRŞEHİR-Kaman\n☎️ 0533 111 22 33");
        $this->assertSame(['Adana Ceyhan', 'Kırşehir Kaman'], [$k['pickup_location'], $k['delivery_location']]);
    }

    public function test_location_suggestions_never_auto_approve(): void
    {
        Settings::set('ai_suggest_auto_approve_hits', 2);
        $svc = app(RuleFeedbackService::class);
        $svc->suggest('location', 'sanayikent', 'Kocaeli|Dilovası', 'örnek', 1);
        $svc->suggest('location', 'sanayikent', 'Kocaeli|Dilovası', 'örnek', 2);
        $this->assertSame('suggested', AiLexicon::query()->where('kind', 'location')->where('term', 'sanayikent')->value('status'), 'konum takma adı yalnız yönetici onayıyla sözlüğe girer');
        $svc->suggest('goods', 'xyzmalzeme', 'insaat', 'örnek', 1);
        $svc->suggest('goods', 'xyzmalzeme', 'insaat', 'örnek', 2);
        $this->assertSame('active', AiLexicon::query()->where('kind', 'goods')->where('term', 'xyzmalzeme')->value('status'));
    }

    public function test_incomplete_ad_lists_the_fields_the_driver_should_ask_for(): void
    {
        $load = new ScrapedLoad(['pickup_province_code' => 6, 'delivery_province_code' => 35, 'delivery_district' => 'Torbalı', 'weight' => null, 'price' => null]);
        $this->assertSame(['araç tipi', 'tonaj', 'fiyat', 'yükleme ilçesi'], $load->missingFields());
        $load->vehicle_type = 'tir';
        $load->weight = 24000;
        $load->price = 30000;
        $load->pickup_district = 'Sincan';
        $this->assertSame([], $load->missingFields());
    }
}
