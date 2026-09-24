<?php

namespace App\Livewire\Concerns;

use App\Models\DriverProfile;
use App\Models\DriverSavedLoad;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Services\DriverTripService;
use App\Services\ScrapedLoadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Şoför ekranlarındaki ilan kartı eylemleri: yıldız (kaydet) ve "Bu işi aldım" penceresi.
 * <x-external-load-card> ve <x-take-trip-modal> bileşenleri bu trait'i kullanan Livewire bileşeninde çalışır.
 */
trait HandlesExternalLoadActions
{
    /** "Bu işi aldım" penceresi (dış kaynak ilanı) */
    public bool $takeModalOpen = false;

    #[Locked]
    public ?int $takeLoadId = null;

    public string $takePickupDate = '';

    public string $takeDeliveryDate = '';

    public bool $takeNotify = true;

    private function actionProfile(): ?DriverProfile
    {
        return Auth::user()?->driverProfile;
    }

    /** Kaydet / kaydı kaldır (yıldız). */
    public function toggleSave(string $kind, int $id): void
    {
        $profile = $this->actionProfile();
        if (! $profile || ! $profile->isKycApproved()) {
            return;
        }
        $column = $kind === 'external' ? 'scraped_load_id' : 'load_id';
        if ($kind === 'external' && ! $profile->isPremium()) {
            return;
        }
        $existing = DriverSavedLoad::query()->where('driver_profile_id', $profile->id)->where($column, $id)->first();
        if ($existing) {
            $existing->delete();

            return;
        }
        $exists = $kind === 'external'
            ? ScrapedLoad::query()->whereKey($id)->where('visibility', 'public')->exists()
            : Load::query()->whereKey($id)->where('visibility', 'public')->exists();
        if ($exists) {
            DriverSavedLoad::create(['driver_profile_id' => $profile->id, $column => $id]);
        }
    }

    /** Eksik bilgili ilan: şoför arayıp öğrendiği araç tipini girer, ilan tamamlanır ve normal listeye geçer. */
    public function completeExternal(int $scrapedId, string $vehicleType): void
    {
        $profile = $this->actionProfile();
        $load = ScrapedLoad::query()->whereKey($scrapedId)->where('visibility', 'public')->where('is_incomplete', true)->first();
        if (! $profile?->isPremium() || ! $profile->isKycApproved() || ! $load || $vehicleType === '') {
            return;
        }
        try {
            app(ScrapedLoadService::class)->completeByDriver($load, $profile, $vehicleType);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }
        session()->flash('success_message', 'Teşekkürler, ilan tamamlandı; artık herkese normal listede görünür.');
    }

    public function openTake(int $scrapedId): void
    {
        $profile = $this->actionProfile();
        if (! $profile?->isPremium() || ! ScrapedLoad::query()->whereKey($scrapedId)->where('visibility', 'public')->exists()) {
            session()->flash('error_message', 'İlan bulunamadı.');

            return;
        }
        $this->takeLoadId = $scrapedId;
        $this->takePickupDate = now()->toDateString();
        $this->takeDeliveryDate = now()->addDay()->toDateString();
        $this->takeNotify = true;
        $this->resetErrorBag();
        $this->takeModalOpen = true;
    }

    public function closeTake(): void
    {
        $this->takeModalOpen = false;
        $this->takeLoadId = null;
        $this->resetErrorBag();
    }

    public function submitTake(DriverTripService $trips): void
    {
        $this->validate([
            'takePickupDate' => ['required', 'date'],
            'takeDeliveryDate' => ['required', 'date', 'after_or_equal:takePickupDate'],
        ], ['takeDeliveryDate.after_or_equal' => 'Teslim tarihi yükleme tarihinden önce olamaz.']);
        $profile = $this->actionProfile();
        $load = $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null;
        if (! $profile || ! $load) {
            $this->closeTake();

            return;
        }
        try {
            $trips->takeExternal($profile, $load, Carbon::parse($this->takePickupDate), Carbon::parse($this->takeDeliveryDate), $this->takeNotify);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
            $this->closeTake();

            return;
        }
        $this->closeTake();
        session()->flash('success_message', 'İş kaydedildi. '.($this->takeNotify ? 'Varış yerinizin çevresinden çıkan yeni ilanlar size bildirilecek.' : 'İşlerim sayfasından takip edebilirsiniz.'));
    }
}
