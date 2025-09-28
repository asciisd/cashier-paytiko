<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paytiko Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Paytiko payment processor integration with Cashier Core.
    |
    */

    'merchant_secret_key' => env('PAYTIKO_MERCHANT_SECRET_KEY'),
    
    /*
    |--------------------------------------------------------------------------
    | Paytiko API Base URLs
    |--------------------------------------------------------------------------
    |
    | UAT API Base URL: https://uat-core.paytiko.com for testing
    | PROD API Base URL: https://core.paytiko.com for production
    |
    */
    'core_url' => env('PAYTIKO_CORE_URL', 'https://core.paytiko.com'),
    
    'webhook_url' => env('PAYTIKO_WEBHOOK_URL'),
    
    'success_redirect_url' => env('PAYTIKO_SUCCESS_REDIRECT_URL'),
    
    'failed_redirect_url' => env('PAYTIKO_FAILED_REDIRECT_URL'),
    
    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for Paytiko transactions.
    |
    */
    'default_currency' => env('PAYTIKO_DEFAULT_CURRENCY', 'USD'),
    
    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for webhook handling and verification.
    |
    */
    'webhook' => [
        'verify_signature' => env('PAYTIKO_VERIFY_WEBHOOK_SIGNATURE', true),
        'tolerance' => env('PAYTIKO_WEBHOOK_TOLERANCE', 300), // 5 minutes
    ],
    
    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP client used to communicate with Paytiko API.
    |
    */
    'http' => [
        'timeout' => env('PAYTIKO_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('PAYTIKO_HTTP_CONNECT_TIMEOUT', 10),
        'verify' => env('PAYTIKO_HTTP_VERIFY_SSL', true),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable or disable logging for Paytiko transactions.
    |
    */
    'logging' => [
        'enabled' => env('PAYTIKO_LOGGING_ENABLED', true),
        'channel' => env('PAYTIKO_LOG_CHANNEL', 'default'),
        'level' => env('PAYTIKO_LOG_LEVEL', 'info'),
    ],
];
