<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayPal Partner (platform) credentials
    |--------------------------------------------------------------------------
    |
    | Used for "Connect with PayPal" in Admin → Settings → Payment Methods.
    | Optional server-level defaults for the partner app. Admins can also set
    | these in Admin → Settings → Payment Methods → PayPal (stored in DB).
    |
    | Apply for PayPal partner access: https://developer.paypal.com/docs/multiparty/
    |
    */
    'partner' => [
        'client_id' => env('PAYPAL_PARTNER_CLIENT_ID'),
        'client_secret' => env('PAYPAL_PARTNER_CLIENT_SECRET'),
        'merchant_id' => env('PAYPAL_PARTNER_MERCHANT_ID'),
        'bn_code' => env('PAYPAL_PARTNER_BN_CODE'),
    ],

];
