<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayPal Partner (platform) credentials
    |--------------------------------------------------------------------------
    |
    | Used for "Connect with PayPal" in Admin → Settings → Payment Methods.
    | These are Talksasa's partner app credentials (not the merchant's).
    | Merchants link their PayPal account via Partner Referrals; payments use
    | partner tokens + PayPal-Auth-Assertion on their behalf.
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
