<?php

return [
    'name' => env('APP_NAME', 'Talksasa Cloud'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),

    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    | Reseller custom-domain SSL (Let's Encrypt via certbot / provision script)
    | Run once on the server: sudo bash scripts/reseller-ssl/install-host.sh
    | RESELLER_SSL_CERTBOT_SUDO=true when www-data may run: sudo -n .../provision.sh
    */
    'reseller_ssl_certbot_path' => env('RESELLER_SSL_CERTBOT_PATH', 'certbot'),
    'reseller_ssl_certbot_sudo' => (bool) env('RESELLER_SSL_CERTBOT_SUDO', false),
    'reseller_ssl_use_provision_script' => (bool) env('RESELLER_SSL_USE_PROVISION_SCRIPT', true),
    'reseller_ssl_provision_script' => env('RESELLER_SSL_PROVISION_SCRIPT'),
];
