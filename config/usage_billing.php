<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usage billing (application hosting)
    |--------------------------------------------------------------------------
    |
    | New platform app services use an included floor + metered overage model.
    | Existing package services keep fixed renewals unless they exceed specs.
    |
    */

    'enabled' => filter_var(env('USAGE_BILLING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /** Monthly floor charged even with zero overage (KES). */
    'floor_price_monthly' => (float) env('USAGE_BILLING_FLOOR_MONTHLY', 1500),

    /**
     * Included allotment for usage-mode services (also used as soft package
     * comparison when grandfathered packages enable overage).
     */
    'included' => [
        'cpu' => (float) env('USAGE_BILLING_INCLUDED_CPU', 1),
        'memory_mb' => (int) env('USAGE_BILLING_INCLUDED_MEMORY_MB', 1024),
        'disk_gb' => (float) env('USAGE_BILLING_INCLUDED_DISK_GB', 20),
        'mailboxes' => (int) env('USAGE_BILLING_INCLUDED_MAILBOXES', 5),
        'aliases' => (int) env('USAGE_BILLING_INCLUDED_ALIASES', 10),
        'mailbox_quota_mb' => (int) env('USAGE_BILLING_INCLUDED_MAILBOX_QUOTA_MB', 5120),
        'quota_mb' => (int) env('USAGE_BILLING_INCLUDED_QUOTA_MB', 25600),
        'msgs_per_day' => (int) env('USAGE_BILLING_INCLUDED_MSGS_PER_DAY', 500),
    ],

    /** Overage unit prices (KES). Bandwidth is intentionally off in v1. */
    'rates' => [
        'cpu_per_core_hour' => (float) env('USAGE_BILLING_CPU_RATE', 2),
        'ram_per_gb_hour' => (float) env('USAGE_BILLING_RAM_RATE', 1),
        'disk_per_gb_hour' => (float) env('USAGE_BILLING_DISK_RATE', 0.05),
        'mailbox_per_month' => (float) env('USAGE_BILLING_MAILBOX_RATE', 100),
        'bandwidth_per_gb' => (float) env('USAGE_BILLING_BANDWIDTH_RATE', 0),
    ],

    /**
     * Hard caps (suspend / block scale-up). Soft overage bills below these.
     * null = no hard cap for that metric.
     */
    'hard_caps' => [
        'cpu' => (float) env('USAGE_BILLING_HARD_CPU', 4),
        'memory_mb' => (int) env('USAGE_BILLING_HARD_MEMORY_MB', 4096),
        'disk_gb' => (float) env('USAGE_BILLING_HARD_DISK_GB', 100),
        'mailboxes' => (int) env('USAGE_BILLING_HARD_MAILBOXES', 50),
    ],

    /** Do not bill overage until usage exceeds included * (1 + grace/100). */
    'grace_percent' => (float) env('USAGE_BILLING_GRACE_PERCENT', 10),

    /** Warn customers when period usage reaches this % of included. */
    'warn_percent' => (float) env('USAGE_BILLING_WARN_PERCENT', 80),

    /** Always attach Mailcow email for new usage-mode app orders. */
    'auto_include_email' => filter_var(env('USAGE_BILLING_AUTO_EMAIL', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Optional email product id for auto-bundled mail. When null, the first
     * active email_hosting product is used.
     */
    'email_product_id' => env('USAGE_BILLING_EMAIL_PRODUCT_ID')
        ? (int) env('USAGE_BILLING_EMAIL_PRODUCT_ID')
        : null,

    /** Prefer this application product id when auto-selecting after tech stack. */
    'default_app_product_id' => env('USAGE_BILLING_APP_PRODUCT_ID')
        ? (int) env('USAGE_BILLING_APP_PRODUCT_ID')
        : null,

    /** Collect Mailcow mailbox counts for usage invoices / UI. */
    'mail_snapshots_enabled' => filter_var(env('USAGE_BILLING_MAIL_SNAPSHOTS', true), FILTER_VALIDATE_BOOLEAN),

    /** Bill bandwidth overage in v1 (off by default). */
    'bandwidth_billing_enabled' => filter_var(env('USAGE_BILLING_BANDWIDTH', false), FILTER_VALIDATE_BOOLEAN),

];
