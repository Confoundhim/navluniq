<?php

namespace App\Support;

use App\Console\Commands\RefreshLegalTextsCommand;
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
        $checks[] = self::item('Ödeme kuruluşu', 'Sağlayıcı seçili', $selected->id() !== 'none', $selected->label(), 'Bu sekmedeki "Ödeme kuruluşu ve anahtarlar" formundan sağlayıcı seçin.');
        $checks[] = self::item('Ödeme kuruluşu', 'Anahtarlar tanımlı', $active->isConfigured(), $active->isConfigured() ? 'Etkin: '.$active->label() : 'Anahtarlar boş; kart tahsilatı kapalı',
            'iyzico: bu sekmedeki formdan API anahtarı ve gizli anahtarı girin. PayTR: sunucu .env (PAYTR_*).');
        $checks[] = self::item('Ödeme kuruluşu', 'Canlı mod', $active->isConfigured() && ! $active->isSandbox(), $active->isSandbox() ? 'Test (sandbox) modu' : 'Canlı',
            'Canlı anahtarları girip test (sandbox) modunu kapatın.');
        $checks[] = self::item('Ödeme kuruluşu', 'HTTPS adres', $https, $appUrl, 'APP_URL https:// ile başlamalı; SSL sertifikası kurulu olmalı.');
        $checks[] = self::item('Ödeme kuruluşu', 'Sunucu bildirimi (webhook) adresi', true, $appUrl.'/odeme/bildirim/'.$selected->id(), null);
        $checks[] = self::item('Ödeme kuruluşu', 'Sonuç sayfaları', true, $appUrl.'/odeme/sonuc/{sipariş}/basarili · …/basarisiz', null);
        $logos = file_exists(public_path('images/payment/iyzico-band-colored.svg')) && file_exists(public_path('images/payment/iyzico-ile-ode.svg'));
        $checks[] = self::item('Ödeme kuruluşu', 'Kart markaları ve "iyzico ile Öde" logoları', $logos, $logos ? 'Altbilgi ve ödeme sayfalarında' : 'Eksik', 'public/images/payment altındaki logo dosyaları eksik.');
        $checks[] = self::item('Ödeme kuruluşu', 'Pazaryeri (alt üye işyeri) aktarımı', $active->supportsSubMerchants(),
            $active->supportsSubMerchants() ? 'Destekleniyor; şoför ödemeleri kuruluş üzerinden' : 'Desteklenmiyor; şoför ödemeleri finans ekibince banka transferiyle',
            'iyzico pazaryeri sözleşmesi imzalanınca formdaki "Pazaryeri ürünü aktif" kutusunu işaretleyin.');

        // 2) Şirket bilgileri (sitede görünür olmalı)
        foreach (Company::LABELS as $key => $label) {
            $val = Company::get($key);
            $checks[] = self::item('Şirket bilgileri', $label, $val !== '', $val !== '' ? $val : 'Boş', 'Bu sekmedeki "Şirket künyesi" formundan '.mb_strtolower($label).' alanını doldurun.');
        }
        $etbis = trim((string) CmsContent::getVal('etbis_code'));
        $checks[] = self::item('Şirket bilgileri', 'ETBİS kaydı', $etbis !== '', $etbis !== '' ? $etbis : 'Boş', 'Bu sekmedeki "Şirket künyesi" formuna ETBİS kodunu yazın (etbis.ticaret.gov.tr kaydı sonrası verilir).');

        // 3) Yasal sayfalar
        $legal = ['contract_kvkk' => 'KVKK aydınlatma', 'contract_terms' => 'Kullanıcı sözleşmesi', 'contract_privacy' => 'Gizlilik politikası', 'contract_distance_sale' => 'Mesafeli satış sözleşmesi', 'contract_cancellation' => 'İptal ve iade politikası'];
        $badWords = 0;
        foreach ($legal as $key => $label) {
            $val = (string) CmsContent::getVal($key, '');
            $ok = mb_strlen(trim(strip_tags($val))) > 200;
            $badWords += preg_match_all('/havuz|bloke|escrow/iu', $val);
            $checks[] = self::item('Yasal sayfalar', $label, $ok, $ok ? mb_strlen(strip_tags($val)).' karakter' : 'Eksik', 'Bu sekmedeki "Yasal metinleri güncel şablonla yenile" düğmesini kullanın.');
        }
        $checks[] = self::item('Yasal sayfalar', 'Sözleşmelerde "havuz/bloke/escrow" ifadesi yok', $badWords === 0, $badWords === 0 ? 'Temiz' : $badWords.' geçiş bulundu',
            'Bu sekmedeki "Yasal metinleri güncel şablonla yenile" düğmesini kullanın ya da İçerik ve CMS\'den düzenleyin.');
        $stale = RefreshLegalTextsCommand::isStale();
        $checks[] = self::item('Yasal sayfalar', 'Sözleşmeler şirket künyesini panelden alıyor', ! $stale, $stale ? 'Eski biçim (künye metne gömülü ya da boş)' : 'Güncel',
            'Bu sekmedeki "Yasal metinleri güncel şablonla yenile" düğmesini kullanın; künye değişiklikleri metne otomatik yansır.');

        // 4) Fiyatlandırma ve vergi
        $premium = Settings::float('premium_monthly_price');
        $checks[] = self::item('Fiyatlandırma', 'Premium aylık ücret', $premium > 0, number_format($premium, 2, ',', '.').' ₺ (KDV dahil)', 'Komisyon ve limitler sekmesinden ücret girin.');
        $vat = Settings::float('payment_vat_rate');
        $checks[] = self::item('Fiyatlandırma', 'KDV oranı', $vat > 0, '%'.number_format($vat, 0), 'Bu sekmedeki "Şirket künyesi" formundan KDV oranını girin.');
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
        $smtpOk = config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host'));
        $checks[] = self::item('Bildirim', 'SMTP sunucusu tanımlı', $smtpOk, $smtpOk ? (string) config('mail.mailers.smtp.host') : 'Tanımsız ya da log sürücüsü', 'MAIL_HOST/PORT/USERNAME/PASSWORD girin; E-posta ve bildirim sekmesinden deneme gönderin.');

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
