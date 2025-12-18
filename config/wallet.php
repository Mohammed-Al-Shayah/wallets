<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default wallet currency
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('WALLET_DEFAULT_CURRENCY', 'QAR'),

    /*
    |--------------------------------------------------------------------------
    | Supported currencies
    |--------------------------------------------------------------------------
    */
    'supported_currencies' => [
        'QAR',
        'USD',
        'EUR',
        'ILS',
        'JOD',
        'EGP',
    ],

    /*
    |--------------------------------------------------------------------------
    | Top-up settings
    |--------------------------------------------------------------------------
    */
    'topup_fee_percent' => env('TOPUP_FEE_PERCENT', 0),
    'topup_fee_flat'    => env('TOPUP_FEE_FLAT', 0),
    'topup_payment_url' => env('TOPUP_PAYMENT_URL', 'https://payments.example/checkout'),
    'topup_webhook_token' => env('TOPUP_WEBHOOK_TOKEN', 'changeme'),
];
