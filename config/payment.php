<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Configuration
    |--------------------------------------------------------------------------
    */

    // M-Pesa Configuration
    'mpesa' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'business_short_code' => env('MPESA_BUSINESS_SHORT_CODE'),
        'pass_key' => env('MPESA_PASS_KEY'),
        'is_production' => env('MPESA_PRODUCTION', false),
    ],

    // Stripe Configuration
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // PayPal Configuration
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'is_production' => env('PAYPAL_PRODUCTION', false),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    // Default currency for payments
    'currency' => env('PAYMENT_CURRENCY', 'USD'),
];
