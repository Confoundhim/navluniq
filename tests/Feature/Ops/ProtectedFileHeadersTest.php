<?php

namespace Tests\Feature\Ops;

use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** I16: özel dosyalar sandbox politikasıyla sunulur; PDF satır içi açılmaz, indirilir. */
class ProtectedFileHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $user, string $name, string $content): KycDocument
    {
        Storage::disk('kyc_private')->put('users/'.$user->id.'/'.$name, $content);

        return KycDocument::create(['user_id' => $user->id, 'document_type' => 'driver_license', 'storage_disk' => 'kyc_private', 'storage_path' => 'users/'.$user->id.'/'.$name, 'sha256' => 'a', 'status' => 'approved']);
    }

    public function test_images_are_inline_but_sandboxed(): void
    {
        Storage::fake('kyc_private');
        $user = User::factory()->driver()->create();
        $doc = $this->document($user, 'ehliyet.jpg', 'resim');

        $response = $this->actingAs($user)->get(route('files.kyc', $doc));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_is_downloaded_not_rendered(): void
    {
        Storage::fake('kyc_private');
        $user = User::factory()->driver()->create();
        $doc = $this->document($user, 'ruhsat.pdf', '%PDF-1.4 deneme');

        $response = $this->actingAs($user)->get(route('files.kyc', $doc));

        $response->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'");
    }

    public function test_strangers_cannot_open_the_file(): void
    {
        Storage::fake('kyc_private');
        $owner = User::factory()->driver()->create();
        $doc = $this->document($owner, 'ehliyet.jpg', 'resim');

        $this->actingAs(User::factory()->create())->get(route('files.kyc', $doc))->assertForbidden();
    }
}
