<?php

// app/Services/GibService.php

namespace App\Services;

class GibService
{
    /**
     * Vergi Kimlik Numarası (VKN) Matematiksel Algoritma Doğrulaması.
     * Türkiye'deki VKN'ler rastgele değildir, özel bir Mod-10 algoritması ile üretilir.
     * Bu metot, dışarıya API isteği atmadan (sıfır maliyet ve gecikmeyle) numaranın sahte olup olmadığını anlar.
     * Şirket unvanı ise KYC (Evrak Yükleme) aşamasında Yapay Zeka OCR ile belgeden okunacaktır.
     */
    public function verifyTax(string $vkn): array
    {
        // VKN tam olarak 10 haneli ve sadece rakamlardan oluşmalıdır.
        if (strlen($vkn) !== 10 || ! ctype_digit($vkn)) {
            return [
                'success' => true,
                'is_match' => false,
                'company_title' => null,
                'tax_office' => null,
                'message' => 'Geçersiz Vergi Kimlik Numarası formatı. VKN 10 haneli olmalıdır.',
                'source' => 'NavlunIQ Algoritması',
            ];
        }

        // Türkiye Cumhuriyeti VKN Mod-10 Doğrulama Algoritması
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $tmp = ($vkn[$i] + (9 - $i)) % 10;
            if ($tmp !== 0) {
                $tmp = ($tmp * pow(2, 9 - $i)) % 9;
                if ($tmp === 0) {
                    $tmp = 9;
                }
            }
            $sum += $tmp;
        }

        $lastDigit = (10 - ($sum % 10)) % 10;
        $isValid = ($lastDigit === (int) $vkn[9]);

        if ($isValid) {
            return [
                'success' => true,
                'is_match' => true,
                'company_title' => 'Unvan KYC Aşamasında Doğrulanacak', // Unvanı formdan alıp evrakla eşleştireceğiz
                'tax_office' => 'Vergi Dairesi KYC Aşamasında Doğrulanacak',
                'message' => 'Vergi Kimlik Numarası algoritması geçerli. Resmi unvan evrak yükleme aşamasında teyit edilecektir.',
                'source' => 'NavlunIQ Algoritması',
            ];
        }

        return [
            'success' => true,
            'is_match' => false,
            'company_title' => null,
            'tax_office' => null,
            'message' => 'Girdiğiniz Vergi Kimlik Numarası matematiksel olarak geçersizdir (Sahte VKN).',
            'source' => 'NavlunIQ Algoritması',
        ];
    }
}
