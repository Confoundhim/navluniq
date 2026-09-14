<?php

namespace App\Services;

use App\Mail\SystemNoticeMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kullanıcıya e-posta bildirimi gönderir. Bildirim hatası iş akışını durdurmaz, yalnız loglanır.
 */
class NotificationService
{
    public function notify(User $user, string $subject, array $lines, ?string $actionUrl = null, ?string $actionText = null): void
    {
        try {
            Mail::to($user->email)->send(new SystemNoticeMail($subject, $lines, $actionUrl, $actionText));
        } catch (\Throwable $e) {
            Log::warning('Bildirim e-postası gönderilemedi.', ['user_id' => $user->id, 'subject' => $subject, 'error' => $e->getMessage()]);
        }
    }
}
