<?php

namespace Tests\Feature\Admin;

use App\Models\AiTokenStat;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y4: ret gerekçesi açılır kutusu; yalnız açık "İlan değil" sınıflandırıcıya öğretir. */
class RejectReasonLearningTest extends TestCase
{
    use RefreshDatabase;

    private Scraper $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        $this->source = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function candidate(string $text): ScrapedLoad
    {
        return ScrapedLoad::create([
            'scraper_id' => $this->source->id, 'content_hash' => hash('sha256', $text.uniqid('', true)),
            'raw_message' => $text, 'encrypted_sender_phone' => Crypt::encryptString('5321112233'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private', 'duplicate_count' => 1, 'seen_sources' => ['Grup A'],
        ]);
    }

    public function test_default_and_non_not_load_reasons_do_not_train_the_classifier(): void
    {
        $dup = $this->candidate('Ankara İzmir 24 ton tenteli yük var 0532 111 22 33');
        $plain = $this->candidate('Bursa Konya 10 ton kapalı araç lazım 0532 111 22 33');

        $c = Volt::test('admin.scrapers-center')->assertSee('Tekrar');
        $c->set('rejectReasons.'.$dup->id, 'duplicate')->call('reject', $dup->id)->assertSee('Tekrar');
        $c->call('reject', $plain->id)->assertSee('Diğer');

        $this->assertSame('rejected', $dup->fresh()->status);
        $this->assertSame('duplicate', $dup->fresh()->meta('reject_reason'));
        $this->assertSame('other', $plain->fresh()->meta('reject_reason'), 'Gerekçe seçilmediyse "Diğer" yazılır, "ilan değil" sayılmaz');
        $this->assertSame(0, Settings::int('ai_local_docs_other'), 'Tekrar / diğer gerekçesi sınıflandırıcıya ilan-değil öğretmez');
        $this->assertSame(0, AiTokenStat::query()->count());
    }

    public function test_explicit_not_load_trains_and_bulk_reject_uses_the_toolbar_reason(): void
    {
        $spam = $this->candidate('Satılık 2018 model kamyonet temiz 0532 111 22 33');
        Volt::test('admin.scrapers-center')->set('rejectReasons.'.$spam->id, 'not_load')->call('reject', $spam->id);
        $this->assertSame('not_load', $spam->fresh()->meta('reject_reason'));
        $this->assertSame(1, Settings::int('ai_local_docs_other'));
        $this->assertGreaterThan(0, AiTokenStat::query()->count());

        $a = $this->candidate('Adana Mersin 10 ton 0532 111 22 33 eski ilan');
        $b = $this->candidate('Samsun Ankara 20 ton 0532 111 22 33 eski ilan');
        Volt::test('admin.scrapers-center')->set('selected', [(string) $a->id, (string) $b->id])->set('bulkRejectReason', 'stale')->call('bulk', 'reject')->assertSee('2 aday reddedildi');
        $this->assertSame('stale', $a->fresh()->meta('reject_reason'));
        $this->assertSame('stale', $b->fresh()->meta('reject_reason'));
        $this->assertSame(1, Settings::int('ai_local_docs_other'), 'Toplu "eski" reddi öğretmez');
    }
}
