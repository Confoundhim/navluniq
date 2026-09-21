<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DeployService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panelden başlatılan güncellemenin durumu (JSON). Güncelleme sırasında site bakım modundadır;
 * bu adres bakım modundan muaf tutulur (bootstrap/app.php) ki Sistem Sağlığı sayfası çıktıyı izleyebilsin.
 */
class UpdateStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('manage settings'), 403);

        return response()->json(DeployService::status())->header('Cache-Control', 'no-store');
    }
}
