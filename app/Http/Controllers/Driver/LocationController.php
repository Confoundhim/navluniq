<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocationController extends Controller
{
    public function store(Request $request, DriverLocationService $locations): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric|min:0',
            'shipment_id' => 'nullable|integer',
        ]);

        $driver = Auth::user()?->driverProfile;
        if (! $driver) {
            return response()->json(['ok' => false], 403);
        }

        $location = $locations->record(
            $driver,
            (float) $data['lat'],
            (float) $data['lng'],
            isset($data['speed']) ? (float) $data['speed'] * 3.6 : null,
            isset($data['heading']) ? (float) $data['heading'] : null,
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            isset($data['shipment_id']) ? (int) $data['shipment_id'] : null,
        );

        return response()->json(['ok' => true, 'recorded' => $location !== null]);
    }
}
