<?php

namespace Tests\Feature\Launch;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Yayın öncesi: Türkçe hata sayfaları, robots.txt, sitemap ve arama motoru üst bilgileri. */
class LaunchPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_page_shows_turkish_404(): void
    {
        $this->get('/boyle-bir-sayfa-yok-'.uniqid())
            ->assertNotFound()
            ->assertSee('Sayfa bulunamadı')
            ->assertSee('Ana sayfa')
            ->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_sitemap_lists_public_pages_only(): void
    {
        $r = $this->get('/sitemap.xml')->assertOk();
        $r->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $r->assertSee('<urlset', false)
            ->assertSee(route('home'), false)
            ->assertSee(route('for-drivers'), false)
            ->assertSee(route('for-cargo-owners'), false);
        $this->assertStringNotContainsString('/panel', $r->getContent());
        $this->assertStringNotContainsString('/adminsystem', $r->getContent());
    }

    public function test_robots_blocks_private_areas_and_points_to_sitemap(): void
    {
        $body = file_get_contents(public_path('robots.txt'));
        foreach (['/adminsystem', '/panel', '/api', '/odeme'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $body);
        }
        $this->assertStringContainsString('Sitemap: https://navluniq.com/sitemap.xml', $body);
    }

    public function test_home_has_description_and_canonical_but_login_is_noindex(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('og:title', false);

        $this->get('/giris')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
