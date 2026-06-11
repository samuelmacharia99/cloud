<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base (ledger) currency — all catalog prices are stored in this currency.
    |--------------------------------------------------------------------------
    */
    'base' => 'KES',

    /*
    |--------------------------------------------------------------------------
    | PayPal settlement currency for international checkouts.
    |--------------------------------------------------------------------------
    */
    'paypal_settlement' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | ISO 4217 currencies without fractional units (Stripe minor-unit rules).
    |--------------------------------------------------------------------------
    */
    'zero_decimal' => [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default currency when country is unknown or unmapped.
    |--------------------------------------------------------------------------
    */
    'default' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | ISO 3166-1 alpha-2 country code → ISO 4217 currency code.
    |--------------------------------------------------------------------------
    */
    'countries' => [
        'KE' => 'KES',
        'UG' => 'UGX',
        'TZ' => 'TZS',
        'RW' => 'RWF',
        'NG' => 'NGN',
        'GH' => 'GHS',
        'ZA' => 'ZAR',
        'EG' => 'EGP',
        'MA' => 'MAD',
        'ET' => 'ETB',
        'SN' => 'XOF',
        'CI' => 'XOF',
        'CM' => 'XAF',
        'US' => 'USD',
        'GB' => 'GBP',
        'DE' => 'EUR',
        'FR' => 'EUR',
        'IT' => 'EUR',
        'ES' => 'EUR',
        'NL' => 'EUR',
        'BE' => 'EUR',
        'AT' => 'EUR',
        'IE' => 'EUR',
        'PT' => 'EUR',
        'FI' => 'EUR',
        'GR' => 'EUR',
        'CA' => 'CAD',
        'AU' => 'AUD',
        'NZ' => 'NZD',
        'IN' => 'INR',
        'SG' => 'SGD',
        'AE' => 'AED',
        'SA' => 'SAR',
        'JP' => 'JPY',
        'CN' => 'CNY',
        'HK' => 'HKD',
        'CH' => 'CHF',
        'SE' => 'SEK',
        'NO' => 'NOK',
        'DK' => 'DKK',
        'PL' => 'PLN',
        'BR' => 'BRL',
        'MX' => 'MXN',
    ],

    /*
    |--------------------------------------------------------------------------
    | M-Pesa is only offered when the invoice is in KES and country is Kenya.
    |--------------------------------------------------------------------------
    */
    'mpesa_countries' => ['KE', 'Kenya'],

];
