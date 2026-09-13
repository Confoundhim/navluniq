<?php

// app/Services/NviService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NviService
{
    /**
     * T.C. Nüfus ve Vatandaşlık İşleri (NVİ) Kimlik Doğrulama API Entegrasyonu.
     * Canlı (Production) ortamında doğrudan devletin KPS Public SOAP servisiyle konuşur.
     */
    public function verify(string $tcNo, string $firstName, string $lastName, string $birthYear): array
    {
        // Temel doğrulama: TC No 11 haneli ve sadece rakamlardan oluşmalıdır.
        if (strlen($tcNo) !== 11 || ! ctype_digit($tcNo)) {
            return [
                'success' => false,
                'is_match' => false,
                'message' => 'Geçersiz T.C. Kimlik Numarası formatı.',
                'source' => 'Sistem',
            ];
        }

        try {
            // NVİ'nin beklediği katı SOAP XML formatı
            $xml = '<?xml version="1.0" encoding="utf-8"?>
            <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Body>
                <TCKimlikNoDogrula xmlns="http://tckimlik.nvi.gov.tr/WS">
                  <TCKimlikNo>'.$tcNo.'</TCKimlikNo>
                  <Ad>'.$this->turkishToUpper($firstName).'</Ad>
                  <Soyad>'.$this->turkishToUpper($lastName).'</Soyad>
                  <DogumYili>'.$birthYear.'</DogumYili>
                </TCKimlikNoDogrula>
              </soap:Body>
            </soap:Envelope>';

            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => 'http://tckimlik.nvi.gov.tr/WS/TCKimlikNoDogrula',
            ])
                ->timeout(10) // Sunucu kilitlenmesini önlemek için 10 saniye zaman aşımı
                ->send('POST', 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx', [
                    'body' => $xml,
                ]);

            if ($response->successful()) {
                // SOAP yanıtını güvenli bir şekilde Regex ile parse ediyoruz (SimpleXML namespace hatalarını önlemek için)
                if (preg_match('/<TCKimlikNoDogrulaResult>(.*?)<\/TCKimlikNoDogrulaResult>/is', $response->body(), $matches)) {
                    $result = strtolower(trim($matches[1])) === 'true';

                    return [
                        'success' => true,
                        'is_match' => $result,
                        'message' => $result ? 'Kimlik bilgileri NVİ kayıtlarıyla eşleşti.' : 'Kimlik bilgileri hatalı veya eşleşmiyor.',
                        'source' => 'Nüfus ve Vatandaşlık İşleri Genel Müdürlüğü',
                    ];
                }
            }

            Log::error('NVİ KPS Yanıt Hatası: Beklenmeyen XML formatı.', ['response' => $response->body()]);

        } catch (\Exception $e) {
            Log::error('NVİ KPS Servis Bağlantı Hatası: '.$e->getMessage());
        }

        return [
            'success' => false,
            'is_match' => false,
            'message' => 'Nüfus Müdürlüğü sunucularına şu an ulaşılamıyor. Lütfen daha sonra tekrar deneyin.',
            'source' => 'Hata',
        ];
    }

    /**
     * NVİ servisi Türkçe karakterlerin (ı, i, ş, ğ vb.) büyük harfe kusursuz çevrilmesini zorunlu kılar.
     */
    private function turkishToUpper(string $string): string
    {
        $string = str_replace(
            ['i', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç'],
            ['İ', 'I', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'],
            $string
        );

        return mb_strtoupper($string, 'UTF-8');
    }
}
