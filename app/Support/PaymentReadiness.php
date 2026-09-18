<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Payments\GatewayManager;

/**
 * Ödeme kuruluşu başvurusu ve entegrasyon öncesi hazırlık kontrol listesi.
 * Her madde: group, label, ok, detail, fix (eksikse ne yapılmalı).
 */
final class PaymentReadiness
{
    /** @return list<array{group:string,label:string,ok:bool,detail:string,fix:?string}> */
    public static function checks(): array
    {
        $manager = app(GatewayManager::class);
        $selected = $manager->selected();
        $active = $manager->active();
        $appUrl = rtrim((string) config('app.url'), '/');
        $https = str_starts_with($appUrl, 'https://');
        $checks = [];

        // 1) Ödeme kuruluşu
        $checks[] = self::item('Ödeme kuruluşu', 'Sağlayıcı seçili', $selected->id() !== 'none', $selected->label(), 'PAYMENT_PROVIDER ayarını yapın (paytr).');
        $checks[] = self::item('Ödeme kuruluşu', 'Anahtarlar tanımlı', $active->isConfigured(), $active->isConfigured() ? 'Etkin: '.$active->label() : 'Anahtarlar boş; kart tahsilatı kapalı',
            'Sözleşme sonrası merchant anahtarlarını sunucu .env dosyasına yazın, php artisan config:cache çalıştırın.');
        $checks[] = self::item('Ödeme kuruluşu', 'Canlı mod', $active->isConfigured() && ! $active->isSandbox(), $active->isSandbox() ? 'Test (sandbox) modu' : 'Canlı',
            'Canlıya geçerken PAYTR_SANDBOX_MODE=false (ya da sağlayıcının eşdeğeri).');
        $checks[] = self::item('Ödeme kuruluşu', 'HTTPS adres', $https, $appUrl, 'APP_URL https:// ile başlamalı; SSL sertifikası kurulu olmalı.');
        $checks[] = self::item('Ödeme kuruluşu', 'Sunucu bildirimi (webhook) adresi', true, $appUrl.'/odeme/bildirim/'.$selected->id(), null);
        $checks[] = self::item('Ödeme kuruluşu', 'Sonuç sayfaları', true, $appUrl.'/odeme/sonuc/{sipariş}/basarili · …/basarisiz', null);
        $checks[] = self::item('Ödeme kuruluşu', 'Pazaryeri (alt üye işyeri) aktarımı', $active->supportsSubMerchants(),
            $active->supportsSubMerchants() ? 'Destekleniyor; şoför ödemeleri kuruluş üzerinden' : 'Desteklenmiyor; şoför ödemeleri finans ekibince banka transferiyle',
            'Pazaryeri ürünü olan bir sağlayıcı adaptörü eklendiğinde otomatik aktarım açılır.');

        // 2) Şirket bilgileri (sitede görünür olmalı)
        foreach (['name' => 'Şirket unvanı', 'address' => 'Adres', 'phone' => 'Telefon', 'email' => 'E-posta', 'tax_office' => 'Vergi dairesi', 'tax_no' => 'Vergi numarası', 'mersis_no' => 'MERSİS numarası'] as $key => $label) {
            $val = trim((string) config('company.'.$key));
            $checks[] = self::item('Şirket bilgileri', $label, $val !== '', $val !== '' ? $val : 'Boş', 'Sunucu .env dosyasında COMPANY_'.strtoupper($key).' alanını doldurup php artisan config:cache çalıştırın.');
        }
        $etbis = trim((string) CmsContent::getVal('etbis_code'));
        $checks[] = self::item('Şirket bilgileri', 'ETBİS kaydı', $etbis !== '', $etbis !== '' ? $etbis : 'Boş', 'İçerik ve CMS → etbis_code alanına ETBİS kodunu yazın.');

        // 3) Yasal sayfalar
        $legal = ['contract_kvkk' => 'KVKK aydınlatma', 'contract_terms' => 'Kullanıcı sözleşmesi', 'contract_privacy' => 'Gizlilik politikası', 'contract_distance_sale' => 'Mesafeli satış sözleşmesi', 'contract_cancellation' => 'İptal ve iade politikası'];
        $badWords = 0;
        foreach ($legal as $key => $label) {
            $val = (string) CmsContent::getVal($key, '');
            $ok = mb_strlen(trim(strip_tags($val))) > 200;
            $badWords += preg_match_all('/havuz|bloke|escrow/iu', $val);
            $checks[] = self::item('Yasal sayfalar', $label, $ok, $ok ? mb_strlen(strip_tags($val)).' karakter' : 'Eksik', 'php artisan db:seed --class=CmsContractSeeder --force');
        }
        $checks[] = self::item('Yasal sayfalar', 'Sözleşmelerde "havuz/bloke/escrow" ifadesi yok', $badWords === 0, $badWords === 0 ? 'Temiz' : $badWords.' geçiş bulundu',
            'Sözleşmeleri güncel seed ile yenileyin ya da CMS\'den düzenleyin.');

        // 4) Fiyatlandırma ve vergi
        $premium = Settings::float('premium_monthly_price');
        $checks[] = self::item('Fiyatlandırma', 'Premium aylık ücret', $premium > 0, number_format($premium, 2, ',', '.').' ₺ (KDV dahil)', 'Komisyon ve limitler sekmesinden ücret girin.');
        $vat = (float) config('services.payment.vat_rate', 20);
        $checks[] = self::item('Fiyatlandırma', 'KDV oranı', $vat > 0, '%'.number_format($vat, 0), 'PAYMENT_VAT_RATE ayarını yapın.');
        $checks[] = self::item('Fiyatlandırma', 'Şoför hizmet bedeli oranı', Settings::float('commission_standard_driver') >= 0, '%'.number_format(Settings::float('commission_standard_driver'), 1, ',', '.'), null);
        $checks[] = self::item('Fiyatlandırma', 'Asgari navlun bedeli', Settings::float('min_load_price') > 0, number_format(Settings::float('min_load_price'), 2, ',', '.').' ₺', null);

        // 5) Şoför ödeme altyapısı
        $drivers = DriverProfile::query()->count();
        $withIban = BankAccount::query()->where('is_default', true)->count();
        $withRef = DriverProfile::query()->whereNotNull('payout_provider_ref')->count();
        $checks[] = self::item('Şoför ödemeleri', 'Kayıtlı IBAN', true, "{$withIban} şoför IBAN girdi ({$drivers} şoför)", null);
        $checks[] = self::item('Şoför ödemeleri', 'Alt üye işyeri kaydı', ! $active->supportsSubMerchants() || $withRef > 0, "{$withRef} şoför ödeme kuruluşuna kayıtlı", 'Pazaryeri sağlayıcısı devreye alınınca şoförler otomatik kaydedilir.');

        // 6) E-posta ve bildirim
        $mailFrom = (string) config('mail.from.address');
        $checks[] = self::item('Bildirim', 'Gönderici e-posta', filled($mailFrom) && ! str_contains($mailFrom, 'example'), $mailFrom ?: 'Boş', 'MAIL_FROM_ADDRESS ve SMTP ayarlarını yapın.');

        return $checks;
    }

    /** @return array{total:int, ok:int} */
    public static function summary(array $checks): array
    {
        return ['total' => count($checks), 'ok' => count(array_filter($checks, fn ($c) => $c['ok']))];
    }

    private static function item(string $group, string $label, bool $ok, string $detail, ?string $fix): array
    {
        return ['group' => $group, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok ? null : $fix];
    }
}
