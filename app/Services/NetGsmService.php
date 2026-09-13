<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NetGsmService
{
    /**
     * NetGSM API üzerinden tekil veya toplu SMS gönderir.
     * Sağlayıcı ayarları eksikse güvenli biçimde başarısız olur.
     */
    public function sendSms(string $phone, string $message): array
    {
        $user = config('services.netgsm.user');
        $pass = config('services.netgsm.password');
        $header = config('services.netgsm.header', 'NavlunIQ');

        if (empty($user) || empty($pass)) {
            return ['success' => false, 'message' => 'SMS sağlayıcısı yapılandırılmamış.', 'source' => 'configuration'];
        }

        // 2. Gerçek NetGSM HTTP API Bağlantı Altyapısı (Canlıya hazır kod)
        try {
            $response = Http::get('https://api.netgsm.com.tr/sms/send/get', [
                'usercode' => $user,
                'password' => $pass,
                'gsmno' => preg_replace('/[^0-9]/', '', $phone), // Sadece rakamları gönder
                'message' => $message,
                'msgheader' => $header,
                'filter' => '0',
            ]);

            if ($response->successful() && str_contains($response->body(), '00')) {
                return [
                    'success' => true,
                    'message' => 'Gerçek NetGSM API: SMS başarıyla operatöre iletildi. Kod: '.$response->body(),
                    'source' => 'NetGSM API',
                ];
            }

            throw new \Exception('NetGSM Yanıt Hatası: '.$response->body());
        } catch (\Exception $e) {
            Log::error('NetGSM SMS Gönderim Hatası: '.$e->getMessage());
        }

        return [
            'success' => false,
            'message' => 'NetGSM sunucularıyla bağlantı kurulamadı.',
            'source' => 'Hata',
        ];
    }
}
