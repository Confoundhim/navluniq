<?php

namespace App\Livewire\Concerns;

use App\Models\DriverTrip;
use App\Services\DriverTripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * İş kartı (<x-job-card>) eylemleri: yola çıktım / teslim ettim / kapat, dönüş yükü bildirimi ve
 * dönüş yüklerini aç-kapa. İşlerim sayfası ve Genel bakış aynı trait'i kullanır; kart her yerde aynı davranır.
 */
trait HandlesJobActions
{
    /** Dönüş yükleri açık gösterilen iş */
    public ?int $expandedJob = null;

    private function jobsQuery(): Builder
    {
        return DriverTrip::query()->where('driver_profile_id', Auth::user()->driverProfile?->id ?? 0);
    }

    /** Açık işler: yoldakiler önce, sonra yükleme tarihine göre planlananlar, en sonda teslim edilip onay bekleyenler. */
    private function openJobsQuery(): Builder
    {
        return $this->jobsQuery()->with(['scrapedLoad', 'cargoLoad.cargoOwnerProfile', 'shipment'])->open()
            ->orderByRaw("CASE status WHEN 'on_the_way' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END")
            ->orderBy('pickup_date')->orderByDesc('id');
    }

    public function toggleReturnLoads(int $id): void
    {
        $this->expandedJob = $this->expandedJob === $id ? null : $id;
    }

    public function setStatus(int $id, string $status, DriverTripService $trips): void
    {
        $trip = $this->jobsQuery()->with(['cargoLoad', 'shipment'])->whereKey($id)->first();
        $profile = Auth::user()->driverProfile;
        if (! $trip || ! $profile) {
            return;
        }
        try {
            $trip = $status === DriverTrip::STATUS_ON_THE_WAY ? $trips->start($trip, $profile) : $trips->setStatus($trip, $status);
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }
        session()->flash('success_message', 'İş durumu: '.$trip->displayStatusLabel());
    }

    public function toggleNotify(int $id): void
    {
        $trip = $this->jobsQuery()->whereKey($id)->first();
        if ($trip) {
            $trip->forceFill(['notify_return' => ! $trip->notify_return])->save();
        }
    }

    /** Açık işler arasında dönüş yükleri gösterilecek olanın ilanları; yoksa null. */
    private function returnLoadsForExpanded(Collection $trips, DriverTripService $service, int $limit = 10): ?array
    {
        if ($this->expandedJob === null) {
            return null;
        }
        $trip = $trips->firstWhere('id', $this->expandedJob);

        return $trip && $trip->isOpen() ? $service->returnLoadsFor($trip, onlyNew: false, limit: $limit) : null;
    }
}
