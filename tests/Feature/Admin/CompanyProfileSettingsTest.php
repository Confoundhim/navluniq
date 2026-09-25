<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\RefreshLegalTextsCommand;
use App\Models\CmsContent;
use App\Models\User;
use App\Support\Company;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CompanyProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles(['super_admin']);

        return $user->fresh();
    }

    public function test_company_info_comes_from_defaults_then_panel_and_fills_contract_tokens(): void
    {
        $this->assertSame(Company::DEFAULTS['name'], Company::get('name'));
        $this->assertSame('0630148318100001', Company::get('mersis_no'));

        CmsContent::setVal('company_name', 'Panel Lojistik A.Ş.');
        CmsContent::setVal('company_mersis_no', '0123456789012345');
        $this->assertSame('Panel Lojistik A.Ş.', Company::get('name'));

        $html = Company::fillTokens('<p>{{COMPANY_NAME}} · {{COMPANY_TAX_OFFICE}} / {{COMPANY_TAX_NO}}</p>');
        $this->assertSame('<p>Panel Lojistik A.Ş. · '.Company::DEFAULTS['tax_office'].' / '.Company::DEFAULTS['tax_no'].'</p>', $html);

        // Sözleşme sayfası yer tutucuyu değil künyeyi gösterir.
        CmsContent::setVal('contract_kvkk', '<p>Veri sorumlusu: {{COMPANY_NAME}}</p>');
        $this->get('/sozlesmeler/kvkk')->assertOk()->assertSee('Veri sorumlusu: Panel Lojistik A.Ş.')->assertDontSee('{{COMPANY_NAME}}');
    }

    public function test_admin_can_test_an_ai_provider_from_settings(): void
    {
        $this->actingAs($this->admin());
        Settings::set('ai_gemini_key', 'AIza-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '5321234567', 'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null])]]]]]])]);

        Settings::set('ai_gemini_model', 'gemini-2.5-flash');
        Volt::test('admin.settings-center')->set('activeTab', 'scraper')->assertSee('Bağlantıyı sına')->assertSee('Modelleri getir')
            ->call('testAiProvider', 'gemini')->assertSee('Çalışıyor:')->assertSee('Ankara Ostim → İzmir');
    }

    public function test_legal_refresh_reseeds_only_stale_texts(): void
    {
        $this->assertTrue(RefreshLegalTextsCommand::isStale(), 'Boş metinler eski sayılmalı');

        $this->artisan('legal:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertFalse(RefreshLegalTextsCommand::isStale());
        // Yeni gizlilik maddesi olmayan eski sözleşme metni güncelleme sırasında yenilenir
        CmsContent::setVal('contract_terms', (string) str_replace('data-clause="dis-kaynak-gizlilik"', '', (string) CmsContent::getVal('contract_terms')));
        $this->assertTrue(RefreshLegalTextsCommand::isStale());
        $this->artisan('legal:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertFalse(RefreshLegalTextsCommand::isStale());
        $this->assertStringContainsString('{{COMPANY_NAME}}', (string) CmsContent::getVal('contract_kvkk'));
        $this->assertStringContainsString('Dış Kaynak İlanları', (string) CmsContent::getVal('contract_kvkk'));
        $this->assertStringContainsString('3.4 Dış Kaynak İlan Bilgilerinin Gizliliği', (string) CmsContent::getVal('contract_terms'));
        $this->assertStringContainsString('üçüncü kişilerle hiçbir biçimde paylaşamaz', (string) CmsContent::getVal('contract_terms'));
        $this->assertStringContainsString('WhatsApp ve Facebook grupları dahil', (string) CmsContent::getVal('contract_kvkk'));
        $this->assertStringContainsString('ilanının derhal kaldırılmasını isteyebilir', (string) CmsContent::getVal('contract_kvkk'));
        $this->assertStringContainsString('md. 3.4 ile taahhüt eder', (string) CmsContent::getVal('contract_privacy'));
        $this->assertStringContainsString('3.3 Dış Kaynak İlanları', (string) CmsContent::getVal('contract_terms'));
        $this->assertStringContainsString('4.4 Bildirimler ve E-posta Tercihi', (string) CmsContent::getVal('contract_terms'));
        $this->assertStringContainsString('şoför panelinden her zaman kapatılıp açılabilir', (string) CmsContent::getVal('contract_kvkk'));

        // Dış kaynak maddesi olmayan (önceki sürüm) metin eski sayılır ve yenilenir.
        CmsContent::setVal('contract_terms', '<p>{{COMPANY_NAME}} eski sözleşme</p>');
        $this->assertTrue(RefreshLegalTextsCommand::isStale());
        $this->artisan('legal:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertFalse(RefreshLegalTextsCommand::isStale());

        // Künyesi metne gömülü eski biçim: yer tutucu yok → yenilenir.
        foreach (['contract_kvkk', 'contract_terms', 'contract_privacy', 'contract_distance_sale'] as $key) {
            CmsContent::setVal($key, '<p>Eski A.Ş. metni</p>');
        }
        $this->assertTrue(RefreshLegalTextsCommand::isStale());
        $this->artisan('legal:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertStringContainsString('{{COMPANY_NAME}}', (string) CmsContent::getVal('contract_terms'));

        // Güncel biçimdeki el düzenlemesi --if-stale ile korunur.
        CmsContent::setVal('contract_privacy', '<p>{{COMPANY_NAME}} özel gizlilik metni</p>');
        $this->artisan('legal:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertSame('<p>{{COMPANY_NAME}} özel gizlilik metni</p>', CmsContent::getVal('contract_privacy'));
    }

    public function test_admin_saves_company_profile_and_vat_from_settings_page(): void
    {
        $this->actingAs($this->admin());

        Volt::test('admin.settings-center')
            ->set('activeTab', 'payment')
            ->assertSee('Şirket künyesi')
            ->set('company.name', 'NavlunIQ Test Ltd. Şti.')
            ->set('company.mersis_no', '0123456789012345')
            ->set('company.etbis_code', 'ETBIS-123')
            ->set('company.payment_vat_rate', '10')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $this->assertSame('NavlunIQ Test Ltd. Şti.', Company::get('name'));
        $this->assertSame('0123456789012345', Company::get('mersis_no'));
        $this->assertSame('ETBIS-123', CmsContent::getVal('etbis_code'));
        $this->assertSame(10.0, Settings::float('payment_vat_rate'));
        // Varsayılanla aynı bırakılan alan ayrıca saklanmaz.
        $this->assertSame('', Company::stored('address'));

        Volt::test('admin.settings-center')
            ->set('activeTab', 'payment')
            ->set('company.tax_no', '12ab')
            ->call('saveCompany')
            ->assertHasErrors(['company.tax_no']);

        $this->get('/iletisim')->assertOk()->assertSee(Company::get('phone'));
    }
}
