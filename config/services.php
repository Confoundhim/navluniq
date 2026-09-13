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
        'free_only' => env('AI_FREE_ONLY', true),
        'paid_enabled' => env('AI_PAID_ENABLED', false),
        'gemini_key' => env('GEMINI_API_KEY'),
        'kimi_key' => env('KIMI_API_KEY'),
        'claude_key' => env('CLAUDE_API_KEY'),
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

];
