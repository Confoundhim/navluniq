<?php

namespace Tests\Feature\Intake;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LoadIntakeService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lojistik firmalarının uzun liste mesajları (Engin Abi, 2026-10-08): "‼️Söke'den Van 2 tır" satırları, rota satırının altındaki
 * kalkışsız varış satırları, tek satırda fiyatlı iki varış, "art" fiyat kısaltması, adet yazım hataları, faks numarası, ton başı
 * fiyat ve mesaj bağlamından araç tahmini. Numaralar ve adlar uydurmadır.
 */
class ListMessagesTest extends TestCase
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

    public function test_same_province_pair_with_different_districts_in_one_message_opens_separate_ads(): void
    {
        $r = $this->intake("‼️Malkara'dan yüklemeler kısa uzun fark etmez Torbalı kömür ödeme 15 gün\n\nKARAPINAR  1.750\nBOZKIR           1.850\n\n‼️izmir kemalpaşa'dan diyarbakır hani bir tır\n‼️diyarbakır merkez 4 tır\n\n0533 111 22 33", 'liste-1');

        $this->assertSame('created', $r['status']);
        $this->assertCount(4, $r['created_ids'], 'Konya Karapınar, Konya Bozkır, Diyarbakır Hani, Diyarbakır: aynı il çifti ilçe düzeyinde ayrı ilan');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame(['Konya Karapınar', 'Konya Bozkır', 'Diyarbakır Hani', 'Diyarbakır'], $loads->pluck('delivery_location')->all());
        $this->assertSame(['Tekirdağ Malkara', 'Tekirdağ Malkara', 'İzmir Kemalpaşa', 'İzmir Kemalpaşa'], $loads->pluck('pickup_location')->all());
        $this->assertSame([1750.0, 1850.0], [(float) $loads[0]->price, (float) $loads[1]->price]);
        $this->assertSame(['per_ton', 'per_ton'], [$loads[0]->price_unit, $loads[1]->price_unit], '600 km damperli rotada 1.750 ₺ araç başı olamaz');
        $this->assertSame(4, $loads[3]->vehicle_count);
        $this->assertCount(4, array_unique($loads->pluck('route_key')->all()), 'rota anahtarları ilçe düzeyinde ayrışır');
    }

    public function test_one_line_with_two_priced_destinations_and_glued_price_words(): void
    {
        $r = $this->intake("‼️çan'dan muş 3400 artı kdv malatya 2700 + kdv\n\n💥Sındırgı'dan Urfa Damper 10 tır Hemen yüklenir2450art\n\n💥Yatağan'dan Urfa Bir tır 2450,art\n\n0533 111 22 33", 'liste-2');

        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['Çanakkale Çan', 'Muş', 3400.0], ['Çanakkale Çan', 'Malatya', 2700.0], ['Balıkesir Sındırgı', 'Şanlıurfa', 2450.0], ['Muğla Yatağan', 'Şanlıurfa', 2450.0]],
            $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location, (float) $l->price])->all());
        $this->assertSame(10, $loads[2]->vehicle_count);
        $this->assertContains('damperli', (array) $loads[2]->body_types);
    }

    public function test_vehicle_context_fax_number_and_count_typos(): void
    {
        $r = $this->intake("‼️Söke'den Van 2 tır\n\n‼️Turgutlu'dan Antep 2000 artı KDV Bir tır\n\n‼️Çankırı kurşunlu'dan şerefli koçhısar bırtır\n\n‼️aydın çine'den Diyarbakır dörttir Damper 2550art\n\n‼️Akhisar'dan Kızıltepe bir arac ödeme nakit\n\n☎️ İş : 02721112233\n📠 Fax. : 02721112244\n📱 Cep: 0533 111 22 33", 'liste-3');

        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertCount(5, $loads);
        $this->assertSame(['tir', 'tir', 'tir', 'tir', 'tir'], $loads->pluck('vehicle_type')->all());
        $this->assertSame('ai_guess', $loads[4]->vehicle_type_source, '"bir araç" satırı: araç mesaj bağlamından tahmin, kesin değil');
        $this->assertSame('keyword', $loads[2]->vehicle_type_source, '"bırtır" = bir tır');
        $this->assertSame(4, $loads[3]->vehicle_count, '"dörttir" = 4 tır');
        foreach ($loads as $load) {
            $phones = array_merge([$load->plainPhone()], $load->extraPhones());
            $this->assertContains('5331112233', $phones);
            $this->assertNotContains('2721112244', $phones, 'faks numarası hiçbir ilana yazılmaz');
        }
    }

    public function test_firm_slash_list_with_date_line_and_damper_footer(): void
    {
        $r = $this->intake("DENEME / NAKLİYAT\n______________________\n7 / 10 / ÇARŞAMBA\n\nDİNAR / TİRE 700+ KDV\n\nSANDIKLI / KORKUTELİ 700+ KDV\n\nDAMPERLİ TIR\n\n- 📱 Cep: 05321112233\n☎️ İş : 02721112233\n📠 Fax. : 02721112244", 'liste-4');

        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['Afyonkarahisar Dinar', 'İzmir Tire'], ['Afyonkarahisar Sandıklı', 'Antalya Korkuteli']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame(['tir', 'tir'], $loads->pluck('vehicle_type')->all());
        $this->assertSame(['per_ton', 'per_ton'], $loads->pluck('price_unit')->all());
        $this->assertSame('5321112233', $loads[0]->plainPhone());
        $this->assertNotContains('2721112244', $loads[0]->extraPhones());
    }
}
