<?php

namespace App\Services;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NetGsmService
{
    public const ENDPOINT = 'https://api.netgsm.com.tr/sms/send/get';

    public function isConfigured(): bool
    {
        return filled(config('services.netgsm.user')) && filled(config('services.netgsm.password'));
    }

    /**
     * Tekil SMS gönderir. Dönüş: ['success' => bool, 'message' => string, 'job_id' => ?string]
     */
    public function sendSms(string $phone, string $message): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'SMS sağlayıcısı yapılandırılmamış.', 'job_id' => null];
        }

        $normalized = Phone::normalize($phone);
        if (! $normalized) {
            return ['success' => false, 'message' => 'Geçersiz telefon numarası.', 'job_id' => null];
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::ENDPOINT, [
                'usercode' => config('services.netgsm.user'),
                'password' => config('services.netgsm.password'),
                'gsmno' => '0'.$normalized,
                'message' => mb_substr($message, 0, 917),
                'msgheader' => config('services.netgsm.header'),
                'filter' => '0',
                'dil' => 'TR',
            ]);

            $body = trim($response->body());

            // NetGSM: "00 <jobid>" veya "01 <jobid>" (tümü/kısmen kabul), diğer kodlar hata.
            if ($response->successful() && preg_match('/^0[01]\s+(\S+)/', $body, $m)) {
                return ['success' => true, 'message' => 'SMS operatöre iletildi.', 'job_id' => $m[1]];
            }

            Log::warning('NetGSM SMS reddedildi.', ['code' => mb_substr($body, 0, 40)]);

            return ['success' => false, 'message' => 'SMS sağlayıcısı isteği reddetti (kod '.mb_substr($body, 0, 10).').', 'job_id' => null];
        } catch (\Throwable $e) {
            Log::error('NetGSM bağlantı hatası.', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'SMS sağlayıcısına ulaşılamadı.', 'job_id' => null];
        }
    }
}
