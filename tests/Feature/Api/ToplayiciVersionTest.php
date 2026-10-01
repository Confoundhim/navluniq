<?php

namespace Tests\Feature\Api;

use App\Support\Toplayici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** NavlunIQ Toplayıcı (Android) sürüm ucu ve APK: uygulama açılışta bakar, panel indirme bağlantısını buradan alır. */
class ToplayiciVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_endpoint_describes_the_committed_apk(): void
    {
        $r = $this->getJson('/api/v1/toplayici/version')->assertOk();
        $this->assertTrue($r->json('available'), 'public/toplayici/navluniq-toplayici.apk depoda olmalı (android/build.sh üretir)');
        $this->assertGreaterThanOrEqual(1, $r->json('versionCode'));
        $this->assertSame(url('/toplayici/navluniq-toplayici.apk'), $r->json('url'));
        $this->assertGreaterThan(10000, $r->json('bytes'));
        $this->assertSame($r->json('versionCode'), Toplayici::version()['versionCode']);

        // Manifest ve Prefs aynı sürümü taşır; biri artırılıp öbürü unutulursa uygulama güncellemeyi hiç görmez.
        $manifest = (string) file_get_contents(base_path('android/toplayici/AndroidManifest.xml'));
        preg_match('/android:versionCode="(\d+)"/', $manifest, $mc);
        preg_match('/android:versionName="([^"]+)"/', $manifest, $mn);
        $prefs = (string) file_get_contents(base_path('android/toplayici/src/com/navluniq/toplayici/Prefs.java'));
        preg_match('/VERSION_CODE = (\d+);/', $prefs, $pc);
        preg_match('/VERSION_NAME = "([^"]+)";/', $prefs, $pn);
        $this->assertSame([$mc[1], $mn[1]], [$pc[1], $pn[1]], 'AndroidManifest ve Prefs sürümleri aynı olmalı');
        $this->assertSame((int) $mc[1], $r->json('versionCode'), 'derlenen APK manifestle aynı sürüm (android/build.sh yeniden çalıştırılmalı)');
    }
}
