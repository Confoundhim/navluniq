<?php

namespace Tests\Feature\Intake;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LoadIntakeService;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Engin Abi'nin 2026-10-09 ekran görüntüleri (uydurma numaralarla): varış satırlarının ortasında kalkış başlığı ("➡️KOCAELİ … ⏎ 🟢BAYRAMPAŞA
 * YÜKLEME ⏎ ➡️ANTALYA …"), "...." ile ayrılmış ilan listesi, çoğul ilçe adı ("Ayrancılar"), "0 507 …" boşluklu numara.
 */
class EnginShapesTest extends TestCase
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

    public function test_pickup_header_in_the_middle_of_destination_lines_applies_to_all_of_them(): void
    {
        $r = $this->intake("➡️KOCAELİ KAPALI TENTELİ TIR\n\n🟢BAYRAMPAŞA YÜKLEME\n\n➡️ANTALYA KAPALI TIR\n\n☎️ ERKİN 0(533) 111 22 33\n☎️\n☎️ ÜMİT 0533 111 22 34\n☎️", 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['İstanbul Bayrampaşa', 'Kocaeli'], ['İstanbul Bayrampaşa', 'Antalya']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all(), 'başlıktan önceki varış satırı da aynı kalkışa bağlanır');
        $this->assertSame(['tir', 'tir'], $loads->pluck('vehicle_type')->all());
    }

    public function test_dotted_separator_list_becomes_separate_ads(): void
    {
        $r = $this->intake('ANKARA ZİLE 2 METRE PARÇA....KONYA ZİLE 6 METRE 6 TON AÇIK TIRA PARÇA....ELAZIĞ ZİLE 2 TON 7 METRE KAPALI TIR OLACAK....KONYA CİHANBEYLİ ERZURUM PASİNLER 4 TON 6 METRE AÇIK TIRA PARÇA...0532 111 22 33....0532 111 22 34', 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['Ankara', 'Tokat Zile'], ['Konya', 'Tokat Zile'], ['Elazığ', 'Tokat Zile'], ['Konya Cihanbeyli', 'Erzurum Pasinler']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame([null, 6000, 2000, 4000], $loads->pluck('weight')->all());
        $this->assertSame('parca', $loads[0]->load_kind);
        // İki nokta rota bağlacı olarak kalır
        $this->assertSame('Ankara..İzmir 10 ton', LoadIntakeService::splitDottedList('Ankara..İzmir 10 ton'));
    }

    public function test_plural_district_name_and_spaced_phone_prefix(): void
    {
        $this->assertSame('Ayrancı', TurkishLocations::resolve('Karaman Ayrancılar')['district']);
        $r = $this->intake("Çorum - Osmaniye TIR (Açık)\n\n\nKaraman Ayrancılar - Kars Sarıkamış 10 TEKER\n\n\n0552 111 2233\nSEYİR LOJİSTİK", 'm1');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['Çorum', 'Osmaniye', 'tir'], ['Karaman Ayrancı', 'Kars Sarıkamış', '10_teker_kamyon']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location, $l->vehicle_type])->all());
        $this->assertSame('Ayrancı', $loads[1]->pickup_district, 'ilçe süzgeci (Karaman → Ayrancı) bu ilanı görür');

        $r = $this->intake("HEMEN YÜKLEME\n\nLüleburgaz ➡️ Ankara Frigo Tır\nLüleburgaz ➡️ İst. Avrupa Frigo Tır\n\n\n0 507 111 22 33\n0 506 111 22 34\n\nÖRNEK LOJİSTİK", 'm2');
        $loads = ScrapedLoad::query()->whereIn('id', $r['created_ids'])->orderBy('id')->get();
        $this->assertSame([['Kırklareli Lüleburgaz', 'Ankara'], ['Kırklareli Lüleburgaz', 'İstanbul']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame('5071112233', $loads[0]->plainPhone());
    }
}
