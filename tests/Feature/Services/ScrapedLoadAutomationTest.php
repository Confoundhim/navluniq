<?php

namespace Tests\Feature\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LoadIntakeService;
use App\Services\ScrapedLoadService;
use App\Services\TelegramPublisher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScrapedLoadAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function source(bool $active = true): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => $active]);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id,
            'content_hash' => hash('sha256', uniqid('', true)),
            'raw_message' => 'Ankara İzmir 24 ton 0532 123 45 67',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'weight' => 24000,
            'price' => null,
            'status' => 'parsed_success',
            'parsed_by_llm' => 'regex_verified',
            'visibility' => 'private',
            'duplicate_count' => 1,
            'seen_sources' => ['Grup A'],
        ], $overrides));
    }

    public function test_auto_approval_respects_setting_and_criteria(): void
    {
        $source = $this->source();
        $load = $this->candidate($source);
        $service = app(ScrapedLoadService::class);

        $this->assertSame(0, $service->autoApproveDue(), 'Ayar kapalıyken onaylanmamalı');

        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_price', '1');
        $this->assertSame(0, $service->autoApproveDue(), 'Fiyat zorunluyken fiyatsız aday onaylanmamalı');

        Settings::set('scraper_auto_approve_require_price', '0');
        Settings::set('scraper_free_delay_minutes', '30');
        $this->assertSame(1, $service->autoApproveDue());

        $load->refresh();
        $this->assertSame('public', $load->visibility);
        $this->assertNotNull($load->auto_approved_at);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $load->available_to_free_at->timestamp, 5);

        $inactive = Scraper::create(['name' => 'Grup B', 'type' => 'notification', 'source_identifier' => 'notif:grup-b', 'is_active' => false]);
        $this->candidate($inactive);
        $this->assertSame(0, $service->autoApproveDue(), 'Pasif kaynağın adayı otomatik onaylanmamalı');
    }

    public function test_duplicates_from_other_groups_increase_counter_instead_of_creating_rows(): void
    {
        Cache::flush();
        Http::fake();
        $intake = app(LoadIntakeService::class);
        $text = "Ankara'dan İzmir'e 24 ton palet 0532 123 45 67";
        foreach (['Grup A', 'Grup B', 'Grup C'] as $i => $group) {
            Scraper::create(['name' => $group, 'type' => 'notification', 'source_identifier' => 'notif:'.$i, 'is_active' => true]);
            $intake->intake(['group_name' => $group, 'source_jid' => 'notif:'.$i, 'message_id' => "m{$i}", 'raw_message' => $text]);
            // aynı gruptan tekrar teslim sayaca yazılmaz
            $intake->intake(['group_name' => $group, 'source_jid' => 'notif:'.$i, 'message_id' => "m{$i}-again", 'raw_message' => $text.' 🚛']);
        }

        $this->assertSame(1, ScrapedLoad::count());
        $load = ScrapedLoad::first();
        $this->assertSame(3, $load->duplicate_count);
        $this->assertSame(['Grup A', 'Grup B', 'Grup C'], $load->seen_sources);
    }

    public function test_external_loads_are_never_posted_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        Settings::set('telegram_post_enabled', '1');
        Settings::set('telegram_bot_token', '123456:ABCDEF');
        Settings::set('telegram_channel_id', '@navluniq');
        $source = $this->source();
        $due = $this->candidate($source, ['visibility' => 'public', 'available_to_free_at' => now()->subMinute(), 'duplicate_count' => 3, 'price' => 45000]);

        $this->artisan('loads:release-to-free')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull($due->fresh()->telegram_posted_at);
        $this->assertSame('https://t.me/navluniq', TelegramPublisher::channelUrl());
    }
}
