<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailcow defaults
    |--------------------------------------------------------------------------
    |
    | Used when product resource_limits omit a value. Quotas are megabytes.
    |
    */

    'default_mailboxes' => (int) env('MAILCOW_DEFAULT_MAILBOXES', 10),
    'default_aliases' => (int) env('MAILCOW_DEFAULT_ALIASES', 20),
    'default_quota_mb' => (int) env('MAILCOW_DEFAULT_QUOTA_MB', 51200),
    'default_mailbox_quota_mb' => (int) env('MAILCOW_DEFAULT_MAILBOX_QUOTA_MB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Connection settings shown to customers
    |--------------------------------------------------------------------------
    |
    | Hostname defaults to the Mailcow node's hostname when empty.
    |
    */

    'imap_port' => (int) env('MAILCOW_IMAP_PORT', 993),
    'smtp_port' => (int) env('MAILCOW_SMTP_PORT', 587),
    'smtp_ssl_port' => (int) env('MAILCOW_SMTP_SSL_PORT', 465),
    'webmail_path' => env('MAILCOW_WEBMAIL_PATH', '/SOGo/'),

    /*
    |--------------------------------------------------------------------------
    | DMARC starter record
    |--------------------------------------------------------------------------
    */

    'dmarc_policy' => env('MAILCOW_DMARC_POLICY', 'v=DMARC1; p=none'),

    /*
    |--------------------------------------------------------------------------
    | Shared hosting sales
    |--------------------------------------------------------------------------
    |
    | When false (or Setting shared_hosting_sales_enabled=0), hide DirectAdmin
    | shared hosting from customer browse / techstack. Admin convert still works.
    |
    */

    'shared_hosting_sales_enabled_default' => filter_var(
        env('SHARED_HOSTING_SALES_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN
    ),

];
