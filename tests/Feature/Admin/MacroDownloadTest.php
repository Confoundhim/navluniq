<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\NotificationIntakeParser;
use App\Services\ScrapedLoadService;
use App\Support\MacroDroidMacro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hazır MacroDroid makro dosyası: anahtar ve adres içinde, MacroDroid dışa aktarma yapısında; sözlük (JSON) ekran parçaları çözülür. */
class MacroDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_a_ready_macro_file_with_token_and_url_inside(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);

        $this->get(route('admin.macrodroid.download'))->assertRedirect(); // giriş yok
        $this->actingAs($admin->fresh());
        $r = $this->get(route('admin.macrodroid.download'))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="navluniq-akis.macro"');
        $d = json_decode($r->getContent(), true);
        $this->assertSame(1, $d['macroExportVersion']);
        $m = $d['macro'];
        $this->assertSame('NavlunIQ akış', $m['m_name']);
        $this->assertSame(['FloatingButtonTrigger'], array_column($m['m_triggerList'], 'm_classType'));
        $this->assertSame('fb://groups', $m['m_actionList'][0]['m_urlToOpen']);
        $this->assertSame(['OpenWebPageAction', 'PauseAction', 'SetVariableAction', 'LoopAction', 'UIInteractionAction', 'PauseAction', 'ReadScreenContentsAction', 'SetVariableAction', 'UIInteractionAction', 'PauseAction', 'EndLoopAction', 'HttpRequestAction', 'NotificationAction'], array_column($m['m_actionList'], 'm_classType'));
        $http = $m['m_actionList'][11]['httpRequestConfig'];
        $this->assertSame(['POST', url('/api/v1/webhook/notification'), 'application/json'], [$http['requestType'], $http['urlToOpen'], $http['contentType']]);
        $body = json_decode($http['contentBodyText'], true);
        $this->assertSame(['Facebook', 'screen', '{lv=ekran}', ScrapedLoadService::apiToken()], [$body['app'], $body['kind'], $body['text'], $body['token']]);
        $this->assertNotSame('', $body['token']);
        $this->assertSame('{lv=ekran}'."\n-----\n".'{lvjson=parca}', $m['m_actionList'][7]['m_newStringValue']);
        $this->assertSame(['ekran', 'parca'], array_column($m['localVariables'], 'm_name'));
        $this->assertSame(15, $m['m_actionList'][3]['m_fixedOptionCount']);
        $this->assertFalse($m['m_triggerList'][0]['preventRemoveByDrag'], 'çöp kutusuna sürükleyince kapanır');
        $d2 = json_decode($this->get(route('admin.macrodroid.download', ['ekran' => 8]))->getContent(), true);
        $this->assertSame(8, $d2['macro']['m_actionList'][3]['m_fixedOptionCount']);
        $d3 = json_decode($this->get(route('admin.macrodroid.download', ['ekran' => 999]))->getContent(), true);
        $this->assertSame(40, $d3['macro']['m_actionList'][3]['m_fixedOptionCount'], 'üst sınır 40');
        $this->assertSame(MacroDroidMacro::FILENAME, 'navluniq-akis.macro');
    }

    public function test_screen_dump_chunks_in_dictionary_json_form_are_flattened_to_lines(): void
    {
        $chunk1 = json_encode(['id/1' => 'Gruplar', 'id/2' => 'Nakliye Yük İlanları', 'id/3' => 'Ahmet Örnek · 2 sa', 'id/4' => "Ankara - İzmir 24 ton palet tenteli\n0532 111 22 33", 'id/5' => 'Beğen', 'id/6' => 'Yorum yap', 'id/7' => 'Paylaş'], JSON_UNESCAPED_UNICODE);
        $chunk2 = json_encode(['id/2' => 'Karadeniz Tır Grubu', 'id/3' => 'Mehmet Deneme · 1 sa', 'id/4' => 'Samsun - Mardin gübre damperli 0533 444 55 66', 'id/5' => 'Beğen', 'id/6' => 'Yorum yap', 'id/7' => 'Paylaş'], JSON_UNESCAPED_UNICODE);
        $r = NotificationIntakeParser::parse(['kind' => 'screen', 'title' => 'ekran', 'text' => "\n-----\n".$chunk1."\n-----\n".$chunk2]);
        $this->assertNull($r['skipped']);
        $this->assertSame([['Nakliye Yük İlanları', "Ankara - İzmir 24 ton palet tenteli\n0532 111 22 33"], ['Karadeniz Tır Grubu', 'Samsun - Mardin gübre damperli 0533 444 55 66']], array_map(fn ($m) => [$m['group'], $m['text']], $r['messages']));
    }
}
