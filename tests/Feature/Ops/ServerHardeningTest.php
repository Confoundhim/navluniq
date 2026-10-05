<?php

namespace Tests\Feature\Ops;

use App\Support\RuntimeMailConfig;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** I7/I8/I10/I12/I17: kurulum betiği, nginx ve SMTP sertleştirmeleri. */
class ServerHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_has_timeout_and_verifies_certificates_by_default(): void
    {
        $this->assertSame(15, config('mail.mailers.smtp.timeout'));
        $this->assertTrue(config('mail.mailers.smtp.verify_peer'));
        $this->assertTrue(config('mail.mailers.smtp.verify_peer_name'));
        $this->assertSame(1, Settings::DEFAULTS['mail_verify_tls']);
    }

    public function test_panel_setting_can_disable_smtp_certificate_verification(): void
    {
        Settings::set('mail_verify_tls', '0');
        RuntimeMailConfig::apply();
        $this->assertFalse(config('mail.mailers.smtp.verify_peer'));
        $this->assertFalse(config('mail.mailers.smtp.verify_peer_name'));

        Settings::set('mail_verify_tls', '1');
        RuntimeMailConfig::apply();
        $this->assertTrue(config('mail.mailers.smtp.verify_peer'));
        $this->assertTrue(config('mail.mailers.smtp.verify_peer_name'));
    }

    public function test_install_script_keeps_https_logs_warnings_and_locks_down_ownership(): void
    {
        $script = file_get_contents(base_path('deploy/install.sh'));

        $this->assertStringContainsString('/etc/letsencrypt/live/${DOMAIN}/fullchain.pem', $script);
        $this->assertStringContainsString("grep -qE '^APP_URL=\"?https://' .env", $script);
        $this->assertStringNotContainsString('set_env LOG_LEVEL error', $script);
        $this->assertStringContainsString('set_env LOG_LEVEL warning', $script);
        $this->assertStringContainsString('chown -R root:www-data "$APP_DIR"', $script);
        $this->assertStringContainsString('chown -R www-data:www-data storage bootstrap/cache', $script);
        $this->assertStringContainsString('chown root:www-data .env && chmod 640 .env', $script);
        $this->assertStringContainsString('safe.directory', $script);
        $this->assertStringContainsString('request_terminate_timeout = 120', $script);
        $this->assertStringContainsString('pm.max_requests = 500', $script);
        // Eski "her şey www-data'nın" satırı kalmadı.
        $this->assertStringNotContainsString('chown -R www-data:www-data "$APP_DIR"', $script);
        $this->assertStringNotContainsString('chown -R www-data:www-data "$APP_DIR"', file_get_contents(base_path('deploy/update.sh')));
    }

    public function test_nginx_config_hides_version_compresses_text_blocks_php_in_storage_and_rate_limits(): void
    {
        $conf = file_get_contents(base_path('deploy/nginx.conf'));

        $this->assertStringContainsString('server_tokens off;', $conf);
        $this->assertStringContainsString('gzip on;', $conf);
        $this->assertStringContainsString('client_body_timeout 30s;', $conf);
        $this->assertStringContainsString('limit_req_zone $binary_remote_addr zone=navluniq_login:10m rate=120r/m;', $conf);
        $this->assertStringContainsString('location = /giris {', $conf);
        $this->assertStringContainsString('location ^~ /api/v1/webhook/notification {', $conf);
        // /storage/*.php engeli php bloğundan ÖNCE gelmeli; yoksa php bloğu önce eşleşir ve dosya çalışır.
        $deny = strpos($conf, 'location ~ ^/storage/.*\.php$');
        $php = strpos($conf, 'location ~ \.php$');
        $this->assertNotFalse($deny);
        $this->assertLessThan($php, $deny);
    }
}
