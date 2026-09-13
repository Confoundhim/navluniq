<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Varsayılan Mail Sürücüsü
    |--------------------------------------------------------------------------
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Yapılandırmaları
    |--------------------------------------------------------------------------
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),

            // Modern Laravel (Symfony Mailer) Sertifika Uyuşmazlığı Kesin Çözümü (Düz Anahtarlar)
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gönderici Kimliği (From Address)
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'info@navluniq.com'),
        'name' => env('MAIL_FROM_NAME', 'NavlunIQ'),
    ],

];
