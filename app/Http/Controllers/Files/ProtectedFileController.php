<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\KycDocument;
use App\Models\Load;
use App\Models\ShipmentEvidence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Özel diskteki dosyaları yalnız yetkili taraflara sunar; dosyalar web kökünde değildir.
 */
class ProtectedFileController extends Controller
{
    public function kyc(KycDocument $document): StreamedResponse
    {
        $user = Auth::user();
        $isOwner = $user && $user->id === $document->user_id;
        $isAdmin = $user && $user->isAdminPanelUser() && ($user->can('view users') || $user->can('verify kyc'));
        abort_unless($isOwner || $isAdmin, 403);

        return $this->stream($document->storage_disk, $document->storage_path);
    }

    public function evidence(ShipmentEvidence $evidence): StreamedResponse
    {
        $load = $evidence->shipment?->cargoLoad;
        abort_unless($load && $this->isPartyOrAdmin($load), 403);

        return $this->stream($evidence->storage_disk, $evidence->storage_path);
    }

    public function disputePhoto(Dispute $dispute, string $side): StreamedResponse
    {
        $load = $dispute->cargoLoad;
        abort_unless($load && $this->isPartyOrAdmin($load), 403);
        $path = $side === 'defense' ? $dispute->driver_proof_photo_path : $dispute->claim_photo_path;
        abort_unless($path, 404);

        return $this->stream('private', $path);
    }

    public function eIrsaliye(Load $load): StreamedResponse
    {
        abort_unless($this->isPartyOrAdmin($load) && $load->e_irsaliye_path, $load->e_irsaliye_path ? 403 : 404);

        return $this->stream('private', $load->e_irsaliye_path);
    }

    private function isPartyOrAdmin(Load $load): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->id === $load->cargoOwnerProfile?->user_id
            || $user->id === $load->driverProfile?->user_id
            || $user->isAdminPanelUser();
    }

    private function stream(string $disk, string $path): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, basename($path), ['Cache-Control' => 'private, no-store']);
    }
}
