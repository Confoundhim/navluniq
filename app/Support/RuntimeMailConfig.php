<?php

namespace App\Support;

/**
 * Panelden girilen SMTP ayarlarını her istekte config'e uygular; böylece sunucuda .env düzenlemek
 * ve config:cache çalıştırmak gerekmez. Şifre boşsa panel ayarı devreye girmez, .env MAIL_* kalır.
 */
final class RuntimeMailConfig
{
    public static function apply(): void
    {
        try {
            $password = Settings::string('mail_password');
            $host = Settings::string('mail_host');
        } catch (\Throwable $e) {
            // Veritabanı hazır değil (ilk kurulum, migrate) — .env ayarları geçerli kalır.
            return;
        }

        if ($password === '' || $host === '') {
            return;
        }

        $encryption = strtolower(Settings::string('mail_encryption')) ?: 'tls';
        $port = Settings::int('mail_port') ?: ($encryption === 'ssl' ? 465 : 587);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => Settings::string('mail_username'),
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.encryption' => $encryption === 'none' ? null : $encryption,
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.from.address' => Settings::string('mail_from_address') ?: Settings::string('mail_username'),
            'mail.from.name' => Settings::string('mail_from_name') ?: 'NavlunIQ',
        ]);
    }

    /** Geçerli kaynak: panel | env */
    public static function source(): string
    {
        try {
            return Settings::string('mail_password') !== '' && Settings::string('mail_host') !== '' ? 'panel' : 'env';
        } catch (\Throwable) {
            return 'env';
        }
    }
}
