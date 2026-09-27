<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Deneme kopyası yalıtımı: gerçek verinin kopyasıyla çalışan bir deneme sunucusunun (deploy/tasima.sh --deneme)
 * gerçek kullanıcılara e-posta / Telegram iletisi göndermesini ve gerçek ödeme almasını engeller; yöneticilerin
 * e-posta doğrulaması olmadan sabit kodla girmesini açar. Yeniden çalıştırmak güvenlidir.
 */
class IsolateTestCopyCommand extends Command
{
    protected $signature = 'deneme:izole
        {--email= : Sabit kodla giriş yapabilecek e-postalar (virgülle); boşsa tüm süper yöneticiler}
        {--code=123456 : 6 haneli sabit doğrulama kodu}';

    protected $description = 'Deneme kopyasını yalıtır: e-posta ve Telegram gönderimi kapanır, ödeme sağlayıcısı boşalır, yöneticiler sabit kodla girer';

    public function handle(): int
    {
        $code = (string) $this->option('code');
        if (! preg_match('/^\d{6}$/', $code)) {
            $this->error('Kod 6 haneli olmalıdır.');

            return self::FAILURE;
        }

        $emails = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) $this->option('email')))));
        if ($emails === []) {
            $emails = User::query()->role('super_admin')->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();
        }
        if ($emails === []) {
            $this->error('Sabit kodla giriş için e-posta bulunamadı; --email=... verin.');

            return self::FAILURE;
        }

        // E-posta: panel SMTP şifresi boşalınca .env geçerli olur; deneme sunucusunda MAIL_MAILER=log yazılıdır.
        Settings::set('mail_password', null);
        // Telegram kanalına gönderim ve ödeme sağlayıcısı: gerçek kanala / gerçek karta hiçbir şey gitmez.
        Settings::set('telegram_post_enabled', 0);
        Settings::set('telegram_bot_token', null);
        Settings::set('payment_provider', null);
        Settings::set('iyzico_sandbox', 1);
        // Yöneticiler e-posta beklemeden girer.
        Settings::set('review_login_emails', implode(', ', $emails));
        Settings::set('review_login_code', $code);

        $this->info('Deneme kopyası yalıtıldı: e-posta ve Telegram gönderimi kapalı, ödeme sağlayıcısı boş.');
        $this->line('  Sabit kodla giriş: '.implode(', ', $emails).' · kod '.$code);
        $this->line('  Giriş sayfası    : '.rtrim((string) config('app.url'), '/').'/giris');

        return self::SUCCESS;
    }
}
