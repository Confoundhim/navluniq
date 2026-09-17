<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScrapedLoad;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dış kaynak ilan adaylarının yayın kararları: elle onay, kriterlere göre otomatik onay, ret.
 */
class ScrapedLoadService
{
    public function freeDelayMinutes(): int
    {
        return max(0, Settings::int('scraper_free_delay_minutes'));
    }

    public function approve(ScrapedLoad $load, ?int $userId = null, bool $auto = false): void
    {
        if ($load->visibility === 'public') {
            throw new RuntimeException('İlan adayı zaten yayında.');
        }
        if ($load->status === 'rejected') {
            throw new RuntimeException('Reddedilmiş aday yayınlanamaz.');
        }
        if (! $load->pickup_location || ! $load->delivery_location) {
            throw new RuntimeException('Kalkış ve varış bilgisi olmayan aday yayınlanamaz.');
        }
        // Yayın öncesi son standartlaştırma: eski kayıtlar ve sonradan iyileşen sözlükler için.
        app(LoadStandardizer::class)->restandardize($load);
        $load->refresh();
        if (! $load->pickup_province_code || ! $load->delivery_province_code) {
            throw new RuntimeException('Kalkış ya da varış ili çözülemedi; ilanı düzenleyip ili seçin.');
        }

        $load->update([
            'status' => 'parsed_success',
            'visibility' => 'public',
            'available_to_free_at' => now()->addMinutes($this->freeDelayMinutes()),
            'auto_approved_at' => $auto ? now() : null,
        ]);

        ActivityLog::record(
            $auto ? 'scraped_load.auto_approved' : 'scraped_load.approved',
            ($auto ? 'Dış kaynak ilanı otomatik yayınlandı' : 'Dış kaynak ilanı yayınlandı')." #{$load->id}",
            $userId,
            $load
        );
    }

    public function reject(ScrapedLoad $load, ?int $userId = null): void
    {
        $load->update(['status' => 'rejected', 'visibility' => 'private']);
        ActivityLog::record('scraped_load.rejected', "Dış kaynak ilanı #{$load->id} reddedildi", $userId, $load);
    }

    /** Otomatik onay kriterlerini sağlamıyorsa nedenini, sağlıyorsa null döndürür. */
    public function autoApprovalBlocker(ScrapedLoad $load): ?string
    {
        if ($load->visibility === 'public' || $load->status === 'rejected') {
            return 'durum';
        }
        if (! $load->scraper || ! $load->scraper->is_active) {
            return 'kaynak pasif';
        }
        if (! $load->pickup_location || ! $load->delivery_location) {
            return 'rota eksik';
        }
        if (! $load->encrypted_sender_phone && ! $load->sender_phone) {
            return 'telefon yok';
        }
        if (Settings::bool('scraper_auto_approve_require_price') && (float) $load->price <= 0) {
            return 'fiyat yok';
        }
        if (Settings::bool('scraper_auto_approve_require_weight') && (int) $load->weight <= 0) {
            return 'tonaj yok';
        }

        return null;
    }

    /** Ayar açıksa bekleyen adayları tarar; kriterleri sağlayanları yayınlar ve sayısını döndürür. */
    public function autoApproveDue(): int
    {
        if (! Settings::bool('scraper_auto_approve')) {
            return 0;
        }

        $approved = 0;
        ScrapedLoad::query()->with('scraper')
            ->where('visibility', 'private')->where('status', '!=', 'rejected')
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('id')->limit(200)->get()
            ->each(function (ScrapedLoad $load) use (&$approved): void {
                if ($this->autoApprovalBlocker($load) !== null) {
                    return;
                }
                try {
                    $this->approve($load, null, true);
                    $approved++;
                } catch (\Throwable $e) {
                    Log::warning('Otomatik onay başarısız.', ['scraped_load_id' => $load->id, 'error' => $e->getMessage()]);
                }
            });

        return $approved;
    }
}
