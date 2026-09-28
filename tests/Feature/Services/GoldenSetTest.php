<?php

namespace Tests\Feature\Services;

use App\Support\IntakeBenchmark;
use App\Support\Lexicon;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Altın ölçüm seti: gruplarda görülen yazım biçimlerinden türetilmiş örnekler (uydurma numaralarla) kural katmanından
 * yapay zekasız geçer; setin tamamı doğru çözülmeli. Bir örnek bozulursa hangi alanın nasıl bozulduğu yazılır.
 * Yeni bir yazım biçimi düzeltildiğinde resources/data/altin-set.json içine örnek eklenir.
 */
class GoldenSetTest extends TestCase
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

    public function test_every_golden_case_is_parsed_correctly_by_the_rules_alone(): void
    {
        $r = IntakeBenchmark::run();
        $this->assertGreaterThanOrEqual(200, $r['total'], 'set en az 200 örnek taşır');
        $report = implode("\n", array_map(fn ($f) => "- {$f['id']}: ".implode(' | ', $f['errors'])."\n    ".str_replace("\n", ' ⏎ ', mb_substr($f['message'], 0, 160)), $r['failed']));
        $this->assertSame([], $r['failed'], "Altın set: {$r['passed']}/{$r['total']} doğru.\n{$report}");
        Http::assertNothingSent();
    }
}
