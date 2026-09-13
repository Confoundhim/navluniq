<?php

return [
    'admin' => [
        'email' => env('ADMIN_INIT_EMAIL'),
        'password' => env('ADMIN_INIT_PASSWORD'),
        'phone' => env('ADMIN_INIT_PHONE'),
    ],

    'demo_user' => [
        'enabled' => env('DEMO_USER_ENABLED', false),
        'email' => env('DEMO_USER_EMAIL'),
        'password' => env('DEMO_USER_PASSWORD'),
        'phone' => env('DEMO_USER_PHONE'),
    ],
];
