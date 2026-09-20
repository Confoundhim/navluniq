<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'scraper' => [
        'token' => env('SCRAPER_API_TOKEN'),
    ],

    'ai' => [
        'active_provider' => env('ACTIVE_AI_PROVIDER', 'gemini'),
        'gemini_key' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', ''),
        'groq_key' => env('GROQ_API_KEY'),
        'cerebras_key' => env('CEREBRAS_API_KEY'),
        'openrouter_key' => env('OPENROUTER_API_KEY'),
        'mistral_key' => env('MISTRAL_API_KEY'),
        'claude_key' => env('CLAUDE_API_KEY'),
        'claude_model' => env('CLAUDE_MODEL', ''),
    ],

    // Etkin ödeme kuruluşu: iyzico | paytr (GatewayManager::REGISTRY); panel ayarı önceliklidir. Anahtarlar boşsa ödeme kapalı kalır.
    'payment' => [
        'provider' => env('PAYMENT_PROVIDER', 'iyzico'),
    ],

    // iyzico: anahtarlar panelden (Sistem Ayarları → Ödeme altyapısı) ya da .env
    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        'sandbox' => env('IYZICO_SANDBOX_MODE', true),
    ],
    'paytr' => [
        'merchant_id' => env('PAYTR_MERCHANT_ID'),
        'merchant_key' => env('PAYTR_MERCHANT_KEY'),
        'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
        'sandbox' => env('PAYTR_SANDBOX_MODE', true),
    ],
    'netgsm' => [
        'user' => env('NETGSM_USER'),
        'password' => env('NETGSM_PASS'),
        'header' => env('NETGSM_HEADER', 'NavlunIQ'),
    ],

    'nvi' => [
        'enabled' => env('NVI_ENABLED', true),
    ],

];
