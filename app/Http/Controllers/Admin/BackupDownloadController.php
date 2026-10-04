<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Services\BackupService;
use App\Services\NotificationService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(Backup $backup): BinaryFileResponse
    {
        // Yedek .env'i ve tüm kimlik belgelerini içerir: yalnız süper yönetici indirir, diğer süper yöneticilere haber gider.
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);
        $path = BackupService::path($backup);
        abort_unless($backup->status === 'completed' && $path !== null && is_file($path), 404);
        ActivityLog::record('backup.downloaded', "Yedek indirildi: {$backup->filename}", auth()->id());
        app(NotificationService::class)->notifyAdmins('manage settings', 'Tam yedek indirildi',
            [auth()->user()->full_name." yedek dosyasını indirdi: {$backup->filename} (".now()->format('d.m.Y H:i').'). Yedek, ayarları ve kimlik belgelerini içerir.'],
            route('admin.backups'), 'Yedekleri gör', 'admin');

        return response()->download($path, $backup->filename, ['Content-Type' => 'application/zip']);
    }
}
