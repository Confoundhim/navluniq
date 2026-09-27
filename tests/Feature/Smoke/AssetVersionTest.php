<?php

namespace Tests\Feature\Smoke;

use App\Support\AssetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_asset_urls_carry_a_version_stamp_that_changes_with_the_file(): void
    {
        $url = AssetVersion::url('/images/fav-ico.png');
        $this->assertMatchesRegularExpression('#^/images/fav-ico\.png\?v=[0-9a-f]{8}$#', $url);
        $this->assertSame($url, asset_v('images/fav-ico.png'));
        $this->assertSame('/yok.png?v=0', AssetVersion::url('/yok.png'));
        $this->assertNotSame(AssetVersion::stamp('/images/fav-ico.png'), AssetVersion::stamp('/apple-touch-icon.png'));
    }

    public function test_layouts_reference_icons_and_logos_with_the_stamp(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#/images/fav-ico\.png\?v=[0-9a-f]{8}#', $html);
        $this->assertMatchesRegularExpression('#/apple-touch-icon\.png\?v=[0-9a-f]{8}#', $html);
        $this->assertMatchesRegularExpression('#/images/logo-dark\.png\?v=[0-9a-f]{8}#', $html);
    }
}
