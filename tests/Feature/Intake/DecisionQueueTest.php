<?php

namespace Tests\Feature\Intake;

use App\Models\AiProviderUsage;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\LearningService;
use App\Services\LoadStandardizer;
use App\Services\LocalClassifier;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-04 denetimi, 2. paket: karar puanı ve kuyruk. Kuralın okuduğu araç "ai" kaynaklı yazılıyor, %60-75 bandı kuyrukta
 * çürüyüp 48 saatte reddediliyor, kural tamamken yapay zeka bekleniyor, zamanlanmış iş tükenmiş sağlayıcıları dövüyor,
 * sınıflandırıcı her retten "ilan değil" öğreniyordu. Uydurma numaralar.
 */
class DecisionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
            'raw_message' => 'Ankara İzmir 24 ton tenteli 0532 111 22 33', 'encrypted_sender_phone' => Crypt::encryptString('5321112233'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private', 'duplicate_count' => 1, 'seen_sources' => ['Grup A'],
        ], $overrides));
    }

    public function test_rule_parsed_vehicle_keeps_its_own_source_and_only_marked_ai_output_is_ai(): void
    {
        $parser = app(AiParserService::class);
        $std = app(LoadStandardizer::class);

        // Yalnız kural: "tenteli" kasa ipucu → tır, kaynak kuralın verdiği (hint), "ai" değil.
        $parsed = $parser->parseCheap('Ankara - İzmir 24 ton tenteli 0532 111 22 33');
        $out = $std->standardize('Ankara - İzmir 24 ton tenteli 0532 111 22 33', $parsed);
        $this->assertSame('tir', $out['vehicle_type']);
        $this->assertNotSame('ai', $out['vehicle_type_source']);
        $this->assertContains($out['vehicle_type_source'], ['keyword', 'hint']);
        $this->assertSame('medium', $out['metadata']['vehicle_confidence']);

        // Açık araç adı: keyword.
        $text = 'Ankara - İzmir 24 ton palet tır lazım 0532 111 22 33';
        $this->assertSame('keyword', $std->standardize($text, $parser->parseCheap($text))['vehicle_type_source']);

        // Yapay zekanın verdiği araç (merge "ai" işaretler) ancak kural kesin değilse geçer ve "ai" kaynaklı kalır.
        $vague = 'Ankara - İzmir 24 ton palet 0532 111 22 33';
        $merged = $parser->merge($parser->parseCheap($vague), ['provider' => 'gemini', 'vehicle_type' => 'kirkayak', 'confidence' => 0.9]);
        $this->assertSame(['kirkayak', 'ai'], [$merged['vehicle_type'], $merged['vehicle_type_source']]);
        $this->assertSame(['kirkayak', 'ai'], array_values(array_intersect_key($std->standardize($vague, $merged), ['vehicle_type' => 1, 'vehicle_type_source' => 1])));

        // Şablon kaynağı korunur.
        $tpl = $std->standardize($vague, array_merge($parser->parseCheap($vague), ['vehicle_type' => '10_teker_kamyon', 'vehicle_type_source' => 'template']));
        $this->assertSame(['10_teker_kamyon', 'template'], [$tpl['vehicle_type'], $tpl['vehicle_type_source']]);
    }

    public function test_strong_body_hint_counts_as_certain_vehicle_and_inferred_vehicle_adds_partial_evidence(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '1');
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test');
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        $hint = $this->candidate($source, ['ai_status' => 'pending', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'hint', 'parse_metadata' => ['vehicle_confidence' => 'medium']]);
        $this->assertTrue(ScrapedLoadService::vehicleCertain($hint));
        $this->assertNull($service->autoApprovalBlocker($hint), 'tenteli/dorse ipucu kesin sayılır; yapay zeka beklenmez');

        $weakHint = $this->candidate($source, ['ai_status' => 'pending', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'hint', 'parse_metadata' => ['vehicle_confidence' => 'low']]);
        $this->assertFalse(ScrapedLoadService::vehicleCertain($weakHint));
        $this->assertSame('yapay zeka doğrulaması bekleniyor', $service->autoApprovalBlocker($weakHint));

        // Yükten çıkarılan araç kısmi kanıt: il çifti 55 + telefon 10 + araç 8 = 0,73
        $goods = $this->candidate($source, ['ai_status' => 'skipped', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'goods']);
        $this->assertSame(0.73, $service->decision($goods)['rule']);
        $none = $this->candidate($source, ['ai_status' => 'skipped', 'vehicle_type' => null, 'vehicle_type_source' => null]);
        $this->assertSame(0.65, $service->decision($none)['rule']);
    }

    public function test_score_band_candidates_publish_incomplete_and_are_never_age_rejected(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '0');
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        // il çifti + telefon + yük türü = %70: eskiden 60-75 bandında kuyrukta bekliyordu
        $band = $this->candidate($source, ['ai_status' => 'skipped', 'vehicle_type' => null, 'vehicle_type_source' => null, 'goods_type' => 'Paletli yük']);
        $this->assertSame(0.7, $service->decision($band)['score']);
        $this->assertStringContainsString('karar puanı %70', (string) $service->autoApprovalBlocker($band));
        $this->assertTrue($service->incompleteEligible($band));

        // Eksik yayın kapalıyken bile puan bandındaki aday yaşla reddedilmez; il çözülemeyen reddedilir.
        Settings::set('scraper_incomplete_publish', '0');
        $band->forceFill(['created_at' => now()->subHours(60)])->save();
        $unresolved = $this->candidate($source, ['ai_status' => 'skipped', 'delivery_location' => 'Bilinmeyenköy', 'delivery_province_code' => null]);
        $unresolved->forceFill(['created_at' => now()->subHours(60)])->save();
        $service->autoApproveDue();
        $this->assertSame(['parsed_success', 'private'], [$band->fresh()->status, $band->fresh()->visibility]);
        $this->assertSame('rejected', $unresolved->fresh()->status);
        $this->assertFalse(ScrapedLoadService::isAgeRejectable('karar puanı %70 (eşik %75); elle kontrol'));
        $this->assertFalse(ScrapedLoadService::isAgeRejectable('yapay zeka doğrulaması bekleniyor'));
        $this->assertTrue(ScrapedLoadService::isAgeRejectable('il çözülemedi'));

        // Eksik yayın açılınca bant yayın eşiğine kadar eksik bilgili yayınlanır; incomplete_max_score yalnız eşikten yüksekse üst sınırdır.
        Settings::set('scraper_incomplete_publish', '1');
        $service->autoApproveDue();
        $this->assertSame(['public', true], [$band->fresh()->visibility, $band->fresh()->is_incomplete]);
        Settings::set('scraper_auto_approve_min_confidence', '90');
        Settings::set('scraper_incomplete_max_score', '95');
        $high = $this->candidate($source, ['ai_status' => 'skipped', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'weight' => 24000, 'price' => 45000]); // kural 1,0
        $this->assertNull($service->autoApprovalBlocker($high));
        $band2 = $this->candidate($source, ['ai_status' => 'skipped', 'vehicle_type' => null, 'vehicle_type_source' => null, 'weight' => 24000, 'price' => 45000]); // 0,85
        $this->assertTrue($service->incompleteEligible($band2));
    }

    public function test_candidates_whose_ai_wait_just_expired_are_rechecked_before_the_ten_minute_cycle(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '1');
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('scraper_ai_wait_minutes', '15');
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        // Araç tonajdan çıkarılmış (kural tamam değil): yapay zeka beklenir; ilk taramada bakılır ve bekler.
        $load = $this->candidate($source, ['ai_status' => 'pending', 'vehicle_type' => 'tir', 'vehicle_type_source' => 'weight', 'weight' => 24000]);
        $this->assertSame(0, $service->autoApproveDue());
        $this->assertNotNull($load->fresh()->auto_checked_at);

        // 16 dakika sonra: bekleme doldu ama son bakıştan 10 dakika geçmemiş gibi görünse de (auto_checked_at ileri alınır) yeniden değerlendirilir.
        $this->travel(16)->minutes();
        ScrapedLoad::whereKey($load->id)->update(['auto_checked_at' => now()->subMinutes(2)]);
        $this->assertSame(1, $service->autoApproveDue());
        $this->assertSame('public', $load->fresh()->visibility);
    }

    public function test_scheduled_ai_enrichment_skips_exhausted_providers(): void
    {
        Settings::set('ai_parse_mode', 'fill_gaps');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_gemini_model', 'gemini-test');
        Settings::set('ai_provider', 'gemini');
        AiProviderUsage::create(['provider' => 'gemini', 'usage_date' => now()->toDateString(), 'quota_exhausted' => true, 'quota_resets_at' => now()->addHours(5), 'request_count' => 1]);
        $source = $this->source();
        $load = $this->candidate($source, ['ai_status' => 'pending']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['post_type' => 'load', 'confidence' => 0.9, 'ads' => []])]]]]]])]);

        $this->assertSame(0, app(ScrapedLoadService::class)->aiEnrichPending());
        Http::assertNothingSent();
        $this->assertSame('pending', $load->fresh()->ai_status);

        // Yönetici düğmesi (manual) tükenmiş sağlayıcıyı yine dener.
        $this->assertTrue(app(ScrapedLoadService::class)->reparseWithAi($load->fresh(), null, true));
        Http::assertSentCount(1);
    }

    public function test_only_not_load_rejections_teach_the_classifier(): void
    {
        $source = $this->source();
        $service = app(ScrapedLoadService::class);
        $classifier = app(LocalClassifier::class);
        $admin = User::factory()->create()->id;

        $service->reject($this->candidate($source, ['raw_message' => 'Satılık kamyonet 0532 111 22 33']), $admin, 'not_load');
        $service->reject($this->candidate($source, ['raw_message' => 'Sohbet metni 0532 111 22 33']), $admin); // gerekçesiz: eski tek düğme = ilan değil
        $service->reject($this->candidate($source, ['raw_message' => 'Ankara İzmir 24 ton tenteli 0532 111 22 33']), $admin, 'duplicate');
        $service->reject($this->candidate($source, ['raw_message' => 'Bursa Konya 10 ton 0532 111 22 33']), $admin, 'stale');
        $service->reject($this->candidate($source, ['raw_message' => 'Adana Mersin 10 ton 0532 111 22 33']), $admin, 'wrong_route');
        $this->assertSame(2, Settings::int('ai_local_docs_other'));
        $this->assertSame('duplicate', ScrapedLoad::query()->where('raw_message', 'like', 'Ankara İzmir%')->first()->meta('reject_reason'));

        // Yeniden eğitim: kendiliğinden ret, tekrar ve "ilan değil" dışı gerekçeler olumsuz örnek değildir.
        $service->autoReject($this->candidate($source, ['raw_message' => 'Otomatik ret 0532 111 22 33']), 'puan düşük');
        $dup = $this->candidate($source, ['raw_message' => 'Tekrar diye kapatılan 0532 111 22 33']);
        $dup->update(['status' => 'rejected', 'parse_metadata' => ['duplicate_of' => 1]]);
        $this->assertSame(['load' => 0, 'other' => 2], $classifier->rebuild());
        $this->assertFalse(LocalClassifier::isNegativeExample($dup->fresh()));

        // LearningService doğrudan çağrılsa da aynı kural.
        $learning = app(LearningService::class);
        $learning->onRejected($dup->fresh());
        $this->assertSame(2, Settings::int('ai_local_docs_other'));
    }
}
