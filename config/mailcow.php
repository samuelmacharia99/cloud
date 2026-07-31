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
    // Domain-wide outbound messages per calendar day (Mailcow rl_frame=d). Keeps plans off bulk/campaign use.
    'default_msgs_per_day' => (int) env('MAILCOW_DEFAULT_MSGS_PER_DAY', 500),

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
    | API client networking
    |--------------------------------------------------------------------------
    |
    | Mailcow API keys are IP-allowlisted. App servers often prefer IPv6 when
    | connecting to https://MAIL_HOST, while the allowlist only has the IPv4
    | APP_IP — resulting in "api access denied for ip 2a01:...".
    | Force IPv4 by default so the allowlisted address is used.
    |
    */

    'force_ipv4' => filter_var(env('MAILCOW_FORCE_IPV4', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Shared hosting sales
    |--------------------------------------------------------------------------
    |
    | Platform DirectAdmin shared hosting sales are permanently off
    | (see App\Support\SharedHostingSales). Resellers keep their own catalogs.
    |
    */

    'shared_hosting_sales_enabled_default' => false,

];
