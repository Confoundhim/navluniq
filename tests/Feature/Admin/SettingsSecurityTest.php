<?php

namespace Tests\Feature\Admin;

use App\Models\CmsContent;
use App\Models\PaymentOrder;
use App\Models\SettingRevision;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PaymentService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y1 / Y2 / Y5 (sabit kod) / Y20 / Y21: gizli ayarlar, ödeme sekmesi ve geri alma kuralları. */
class SettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['current_role' => 'admin', 'password' => 'Sifre12345!']);
        $user->syncRoles(['super_admin']);

        return $user->fresh();
    }

    /** "manage settings" izni olan ama süper yönetici olmayan personel. */
    private function settingsAdmin(): User
    {
        $user = User::factory()->create(['current_role' => 'admin', 'password' => 'Sifre12345!']);
        $user->syncRoles(['support_agent']);
        $user->givePermissionTo('manage settings');

        return $user->fresh();
    }

    public function test_secret_and_security_settings_cannot_be_rolled_back_but_plain_ones_can(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        Settings::set('mail_password', 'gercek-sifre-7781');
        Settings::set('legal_document_version', '3');
        Settings::set('commission_standard_driver', '7.00');

        $secretRev = SettingRevision::create(['user_id' => $admin->id, 'key' => 'mail_password', 'setting_label' => 'Şifre', 'old_value' => '••••abcd', 'new_value' => '••••7781']);
        $legalRev = SettingRevision::create(['user_id' => $admin->id, 'key' => 'legal_document_version', 'setting_label' => 'Sözleşme sürümü', 'old_value' => '2', 'new_value' => '3']);
        $plainRev = SettingRevision::create(['user_id' => $admin->id, 'key' => 'commission_standard_driver', 'setting_label' => 'Komisyon', 'old_value' => '5.00', 'new_value' => '7.00']);

        $c = Volt::test('admin.rollback-center')->set('activeTab', 'revisions')->assertSee('Geri alınamaz');

        $c->call('rollback', $secretRev->id)->assertSee('geri alınamaz');
        $this->assertSame('gercek-sifre-7781', Settings::string('mail_password'), 'Maskeli revizyon gerçek anahtarın yerine geçmez');

        $c->call('rollback', $legalRev->id);
        $this->assertSame('3', Settings::string('legal_document_version'));

        $c->call('rollback', $plainRev->id)->assertSee('eski değere döndürüldü');
        $this->assertSame(5.0, Settings::float('commission_standard_driver'));

        $this->assertFalse(Settings::isRollbackable('scraper_api_token'));
        $this->assertFalse(Settings::isRollbackable('review_login_code'));
        $this->assertTrue(Settings::isRollbackable('scraper_list_days'));
    }

    public function test_payment_tab_is_super_admin_only(): void
    {
        $this->actingAs($this->settingsAdmin());
        Volt::test('admin.settings-center')->assertDontSee('Ödeme altyapısı')
            ->set('activeTab', 'payment')->assertSet('activeTab', 'general')
            ->set('paymentForm.iyzico_api_key', 'sandbox-yeni')->call('savePayment')->assertSee('yalnız süper yönetici');
        $this->assertSame('', Settings::string('iyzico_api_key'));

        $this->actingAs($this->superAdmin());
        Volt::test('admin.settings-center')->assertSee('Ödeme altyapısı')->set('activeTab', 'payment')->assertSee('Ödeme kuruluşu ve anahtarlar');
    }

    public function test_saving_a_secret_requires_the_current_password_and_notifies_admins(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($admin);

        $c = Volt::test('admin.settings-center')->set('activeTab', 'scraper')->set('scraper.ai_groq_key', 'gsk_deneme_anahtari');
        $c->call('saveScraper')->assertHasErrors(['currentPassword']);
        $this->assertSame('', Settings::string('ai_groq_key'), 'Şifre girilmeden anahtar yazılmaz');

        $c->set('currentPassword', 'yanlis-sifre')->call('saveScraper')->assertHasErrors(['currentPassword']);
        $this->assertSame('', Settings::string('ai_groq_key'));

        $c->set('currentPassword', 'Sifre12345!')->call('saveScraper')->assertHasNoErrors()->assertSet('currentPassword', '');
        $this->assertSame('gsk_deneme_anahtari', Settings::string('ai_groq_key'));
        $this->assertTrue(Settings::looksEncrypted((string) CmsContent::getVal('ai_groq_key')));
        $this->assertSame(1, UserNotification::query()->where('user_id', $other->id)->where('title', 'Ödeme/gizli ayar değişti')->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'setting.sensitive_changed']);

        // Gizli alan boş bırakılırsa şifre istenmez ve anahtar korunur.
        Volt::test('admin.settings-center')->set('activeTab', 'scraper')->set('scraper.scraper_list_days', '9')->call('saveScraper')->assertHasNoErrors();
        $this->assertSame('gsk_deneme_anahtari', Settings::string('ai_groq_key'));
        $this->assertSame(9, Settings::int('scraper_list_days'));
    }

    public function test_non_super_admin_cannot_change_secrets_and_never_sees_the_telegram_token(): void
    {
        Settings::set('telegram_bot_token', '123456:ABCgizli');
        Settings::set('mail_password', 'posta-sifresi');
        $this->actingAs($this->settingsAdmin());

        $c = Volt::test('admin.settings-center')->set('activeTab', 'scraper')->assertSet('scraper.telegram_bot_token', '')->assertDontSee('123456:ABCgizli')->assertSee('Yalnız süper yönetici değiştirir');
        $c->set('scraper.ai_gemini_key', 'AIza-deneme')->call('saveScraper')->assertHasErrors(['scraper.ai_gemini_key']);
        $this->assertSame('', Settings::string('ai_gemini_key'));

        Volt::test('admin.settings-center')->set('activeTab', 'mail')->set('mailForm.mail_password', 'yeni')->call('saveMail')->assertHasErrors(['mailForm.mail_password']);
        $this->assertSame('posta-sifresi', Settings::string('mail_password'));

        // Telegram anahtarı formda boş kalsa da kayıt mevcut anahtarı silmez.
        $this->actingAs($this->superAdmin());
        Volt::test('admin.settings-center')->set('activeTab', 'scraper')->set('scraper.telegram_channel_id', '@navluniq')->call('saveScraper')->assertHasNoErrors();
        $this->assertSame('123456:ABCgizli', Settings::string('telegram_bot_token'));
    }

    public function test_payment_provider_cannot_change_while_open_escrow_orders_exist(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        Settings::set('payment_provider', 'iyzico');
        $owner = User::factory()->create();
        PaymentOrder::create(['merchant_oid' => 'T'.uniqid(), 'user_id' => $owner->id, 'purpose' => PaymentService::PURPOSE_ESCROW, 'provider' => 'iyzico', 'amount' => 1500, 'currency' => 'TRY', 'status' => 'paid']);
        PaymentOrder::create(['merchant_oid' => 'T'.uniqid(), 'user_id' => $owner->id, 'purpose' => PaymentService::PURPOSE_ESCROW, 'provider' => 'iyzico', 'amount' => 900, 'currency' => 'TRY', 'status' => 'pending']);
        PaymentOrder::create(['merchant_oid' => 'T'.uniqid(), 'user_id' => $owner->id, 'purpose' => PaymentService::PURPOSE_ESCROW, 'provider' => 'iyzico', 'amount' => 700, 'currency' => 'TRY', 'status' => 'refunded']);

        $c = Volt::test('admin.settings-center')->set('activeTab', 'payment')->assertSee('2 açık navlun ödeme emri');
        // PayTR seçimden kaldırıldı (pazaryeri tek yol): başka sağlayıcı girişi doğrulamada düşer, ayar değişmez.
        $c->set('paymentForm.payment_provider', 'paytr')->set('currentPassword', 'Sifre12345!')->call('savePayment');
        $this->assertArrayHasKey('paymentForm.payment_provider', $c->errors()->toArray(), 'seçilemeyen sağlayıcı reddedilmeli');
        $this->assertSame('iyzico', Settings::string('payment_provider'));
        $this->assertSame(2, Volt::test('admin.settings-center')->instance()::openEscrowOrderCount());

        // Sağlayıcı aynı kalırken test modu değişebilir; şifre gerekir ve bildirim düşer.
        // Önceki deneme sağlayıcı kilidinde durduğu için şifre alanı temizlenmemişti; şifresiz deneme için boşaltılır.
        $c->set('currentPassword', '')->set('paymentForm.payment_provider', 'iyzico')->set('paymentForm.iyzico_sandbox', '0')->call('savePayment')->assertHasErrors(['currentPassword']);
        $c->set('currentPassword', 'Sifre12345!')->call('savePayment')->assertHasNoErrors();
        $this->assertFalse(Settings::bool('iyzico_sandbox'));
        $this->assertSame(1, UserNotification::query()->where('user_id', $admin->id)->where('title', 'Ödeme/gizli ayar değişti')->count());
        // Kutu kaydedildikten ve sayfa yeniden yüklendikten sonra gerçek boolean false taşır ("0" metni tarayıcıda işaretli görünür).
        $this->assertSame(false, $c->get('paymentForm.iyzico_sandbox'));
        $this->assertSame(false, Volt::test('admin.settings-center')->set('activeTab', 'payment')->get('paymentForm.iyzico_sandbox'));
        // Tarayıcı kutuyu işaretleyince true gönderir; kayıt '1' olur
        $c->set('paymentForm.iyzico_sandbox', true)->set('currentPassword', 'Sifre12345!')->call('savePayment')->assertHasNoErrors();
        $this->assertTrue(Settings::bool('iyzico_sandbox'));
        $this->assertSame(true, $c->get('paymentForm.iyzico_sandbox'));

        // Emirler kapanınca açık emir sayacı sıfırlanır (sağlayıcı kilidi kalkar).
        PaymentOrder::query()->whereIn('status', ['paid', 'pending'])->update(['status' => 'refunded']);
        $this->assertSame(0, Volt::test('admin.settings-center')->instance()::openEscrowOrderCount());
    }

    public function test_review_login_settings_need_manage_system_and_dead_general_keys_are_gone(): void
    {
        $this->actingAs($this->settingsAdmin());
        Volt::test('admin.settings-center')->assertDontSee('system_site_title')->assertDontSee('Bakım duyurusu')
            ->set('general.review_login_code', '123456')->call('saveGeneral')->assertHasErrors(['general.review_login_code']);
        $this->assertSame('', Settings::string('review_login_code'));

        $this->actingAs($this->superAdmin());
        Volt::test('admin.settings-center')->set('general.review_login_code', '123456')->set('general.review_login_emails', 'inceleme@ornek.test')->call('saveGeneral')->assertHasNoErrors();
        $this->assertSame('123456', Settings::string('review_login_code'));
    }

    public function test_telegram_and_scraper_tokens_are_encrypted_and_plain_legacy_values_still_read(): void
    {
        // Eski düz değer: get() okur, migration şifreler, ikinci çalıştırma dokunmaz.
        CmsContent::setVal('telegram_bot_token', '777:duzmetin');
        CmsContent::setVal('scraper_api_token', 'eskiduzanahtar');
        $this->assertSame('777:duzmetin', Settings::string('telegram_bot_token'));
        $this->assertSame('eskiduzanahtar', Settings::string('scraper_api_token'));

        $migration = require base_path('database/migrations/0001_01_50_000000_encrypt_telegram_and_scraper_tokens.php');
        $migration->up();
        $stored = (string) CmsContent::getVal('telegram_bot_token');
        $this->assertTrue(Settings::looksEncrypted($stored));
        $this->assertSame('777:duzmetin', Settings::string('telegram_bot_token'));
        $this->assertSame('eskiduzanahtar', Settings::string('scraper_api_token'));

        $migration->up();
        $this->assertSame($stored, (string) CmsContent::getVal('telegram_bot_token'), 'Yeniden çalıştırma şifreliyi bir daha şifrelemez');

        // Yeni yazım doğrudan şifreli; çözülemeyen şifreli değer varsayılana düşer.
        Settings::set('telegram_bot_token', '888:yeni');
        $this->assertTrue(Settings::looksEncrypted((string) CmsContent::getVal('telegram_bot_token')));
        $this->assertSame('888:yeni', Settings::string('telegram_bot_token'));
        CmsContent::setVal('telegram_bot_token', Crypt::encryptString('x').'bozuk');
        $this->assertNotSame('x', Settings::string('telegram_bot_token'));
    }
}
