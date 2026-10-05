<?php

namespace Tests\Feature\Ops;

use Tests\TestCase;

/** I11: CI ve Dependabot dosyaları; I6: kurulum betiği Redis bellek sınırı ve otomatik yeniden başlatma yazar. */
class CiAndRedisConfigTest extends TestCase
{
    public function test_ci_workflow_runs_pint_tests_golden_set_build_and_audits(): void
    {
        $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('pull_request:', $ci);
        $this->assertStringContainsString("php-version: '8.4'", $ci);
        foreach (['vendor/bin/pint --test', 'php artisan test --compact', 'php artisan ilan:dogruluk --hatalar', 'composer audit', 'npm audit --audit-level=high', 'npm run build', 'npm ci'] as $step) {
            $this->assertStringContainsString($step, $ci, $step);
        }
        $this->assertStringContainsString("node-version: '20'", $ci);
        $this->assertStringContainsString('continue-on-error: true', $ci);
        // Testler ağa çıkmaz: SQLite ve sahte sürücüler
        $this->assertStringContainsString('DB_CONNECTION: sqlite', $ci);
        // Derleme testlerden önce gelir (Vite manifesti olmadan sayfa testleri 500 verir).
        $this->assertLessThan(strpos($ci, 'php artisan test --compact'), strpos($ci, 'npm run build'));
    }

    public function test_dependabot_groups_composer_and_npm_monthly(): void
    {
        $yml = file_get_contents(base_path('.github/dependabot.yml'));

        $this->assertStringContainsString('package-ecosystem: composer', $yml);
        $this->assertStringContainsString('package-ecosystem: npm', $yml);
        $this->assertSame(3, substr_count($yml, 'interval: monthly'));
        $this->assertStringContainsString('groups:', $yml);
    }

    public function test_install_script_limits_redis_memory_and_restarts_it(): void
    {
        $script = file_get_contents(base_path('deploy/install.sh'));

        $this->assertStringContainsString('maxmemory 256mb', $script);
        $this->assertStringContainsString('maxmemory-policy allkeys-lru', $script);
        $this->assertStringContainsString('redis-server.service.d', $script);
        $this->assertStringContainsString('Restart=always', $script);
        $this->assertStringContainsString('include ${REDIS_DROPIN}', $script);
    }
}
