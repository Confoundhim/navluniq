<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\LocalClassifier;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y19: "Geçmişten yeniden öğren" yalnız süper yönetici, onay metni ve önceki sayaçların kaydı. */
class ClassifierRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role, array $extra = []): User
    {
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles([$role]);
        foreach ($extra as $p) {
            $user->givePermissionTo($p);
        }

        return $user->fresh();
    }

    public function test_rebuild_requires_super_admin_and_the_confirmation_phrase_and_logs_previous_stats(): void
    {
        app(LocalClassifier::class)->train('Ankara İzmir 24 ton tenteli yük 0532 111 22 33', true);
        app(LocalClassifier::class)->train('Satılık kamyonet temiz bakımlı', false);
        $this->assertSame(1, Settings::int('ai_local_docs_load'));

        $this->actingAs($this->staff('support_agent', ['manage scrapers']));
        Volt::test('admin.scrapers-center')->set('activeTab', 'lexicon')->assertSee('yalnız süper yöneticiye açıktır')
            ->set('rebuildConfirm', 'YENİDEN ÖĞREN')->call('rebuildClassifier')->assertSee('yalnız süper yönetici');
        $this->assertSame(1, Settings::int('ai_local_docs_load'), 'Yetkisiz çağrı sayaçları sıfırlamaz');

        $this->actingAs($this->staff('super_admin'));
        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'lexicon')->assertSee('Geçmişten yeniden öğren');
        $c->set('rebuildConfirm', 'yeniden ogren')->call('rebuildClassifier')->assertHasErrors(['rebuildConfirm']);
        $this->assertSame(1, Settings::int('ai_local_docs_load'), 'Yanlış onay metni çalıştırmaz');

        $c->set('rebuildConfirm', 'YENİDEN ÖĞREN')->call('rebuildClassifier')->assertHasNoErrors()->assertSee('Yeniden öğrenildi')->assertSet('rebuildConfirm', '');
        // Geçmişte yayınlanmış/reddedilmiş aday yok: sayaçlar sıfırlandı, önceki değerler işlem kaydında.
        $this->assertSame(0, Settings::int('ai_local_docs_load'));
        $log = ActivityLog::query()->where('action', 'classifier.rebuilt')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->metadata['before']['docs_load']);
        $this->assertSame(1, $log->metadata['before']['docs_other']);
        $this->assertSame(0, $log->metadata['after']['docs_load']);
    }
}
