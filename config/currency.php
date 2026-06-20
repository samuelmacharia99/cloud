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
    | East African country codes (ISO 3166-1 alpha-2) for UI prioritization.
    |--------------------------------------------------------------------------
    */
    'east_africa_countries' => [
        'KE', 'TZ', 'UG', 'RW', 'BI', 'SS', 'ET', 'SO', 'DJ', 'ER', 'CD', 'MG', 'MW', 'MU', 'SC',
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
        // East Africa
        'KE' => 'KES',
        'TZ' => 'TZS',
        'UG' => 'UGX',
        'RW' => 'RWF',
        'BI' => 'BIF',
        'SS' => 'SSP',
        'ET' => 'ETB',
        'SO' => 'SOS',
        'DJ' => 'DJF',
        'ER' => 'ERN',
        'CD' => 'CDF',
        'MG' => 'MGA',
        'MW' => 'MWK',
        'MU' => 'MUR',
        'SC' => 'SCR',
        // Other Africa
        'NG' => 'NGN',
        'GH' => 'GHS',
        'ZA' => 'ZAR',
        'EG' => 'EGP',
        'MA' => 'MAD',
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
    'mpesa_countries' => ['KE'],

    /*
    |--------------------------------------------------------------------------
    | Maximum age of exchange rates before billing is blocked (hours).
    |--------------------------------------------------------------------------
    */
    'max_rate_age_hours' => (int) env('CURRENCY_MAX_RATE_AGE_HOURS', 48),

];
