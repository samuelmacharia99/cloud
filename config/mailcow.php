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
    | DKIM generation defaults
    |--------------------------------------------------------------------------
    */

    'dkim_selector' => env('MAILCOW_DKIM_SELECTOR', 'dkim'),
    'dkim_key_size' => (int) env('MAILCOW_DKIM_KEY_SIZE', 2048),

    /*
    |--------------------------------------------------------------------------
    | DMARC policy published for Mailcow domains
    |--------------------------------------------------------------------------
    |
    | Quarantine aligns better with Gmail/Yahoo bulk-sender expectations once
    | SPF + DKIM are published. Override via MAILCOW_DMARC_POLICY if needed
    | (e.g. p=none while monitoring). Optional {domain} is replaced at apply time.
    |
    */

    'dmarc_policy' => env('MAILCOW_DMARC_POLICY', 'v=DMARC1; p=quarantine; adkim=r; aspf=r'),

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
