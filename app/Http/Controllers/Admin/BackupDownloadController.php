<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Services\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(Backup $backup): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('manage settings'), 403);
        $path = BackupService::path($backup);
        abort_unless($backup->status === 'completed' && $path !== null && is_file($path), 404);
        ActivityLog::record('backup.downloaded', "Yedek indirildi: {$backup->filename}", auth()->id());

        return response()->download($path, $backup->filename, ['Content-Type' => 'application/zip']);
    }
}
