<?php

/**
 * Security Configuration
 *
 * Comprehensive security settings for the Talksasa Cloud platform
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    |
    | Define password requirements for user accounts
    |
    */
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_symbols' => true,
        'min_strength' => 3, // 0-4 scale
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent brute force and abuse attacks
    |
    */
    'rate_limit' => [
        'login_attempts' => 5,        // Failed login attempts per 15 minutes
        'login_window' => 15,          // Minutes
        'api_requests' => 100,         // API requests per minute
        'password_reset' => 3,         // Password resets per hour
        'email_verification' => 5,     // Email verification attempts per hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    |
    | Session configuration and timeout settings
    |
    */
    'session' => [
        'timeout' => 120,              // Minutes until session expires
        'idle_timeout' => 60,          // Minutes of inactivity before logout
        'secure_cookies' => true,      // HTTPS only cookies
        'http_only' => true,           // No JavaScript access to session cookies
        'same_site' => 'lax',          // CSRF protection
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | HTTP security headers to prevent common attacks
    |
    */
    'headers' => [
        'X-Frame-Options' => 'DENY',                                      // Clickjacking protection
        'X-Content-Type-Options' => 'nosniff',                            // MIME type sniffing
        'X-XSS-Protection' => '1; mode=block',                            // XSS protection
        'Referrer-Policy' => 'strict-origin-when-cross-origin',           // Referrer handling
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()', // Feature policy
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' fonts.bunny.net; font-src fonts.bunny.net; img-src 'self' data: https:; connect-src 'self' https:;",
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Security
    |--------------------------------------------------------------------------
    |
    | Restrictions on file uploads
    |
    */
    'file_upload' => [
        'max_size_mb' => 50,           // Maximum file size in MB
        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', // Images
            'pdf', 'doc', 'docx', 'xls', 'xlsx', // Documents
            'txt', 'csv', 'json',                 // Data files
        ],
        'scan_for_viruses' => false,   // Set to true if ClamAV is available
        'store_outside_web' => true,   // Store uploads outside public directory
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist/Blacklist
    |--------------------------------------------------------------------------
    |
    | Control access by IP address
    |
    */
    'ip_filtering' => [
        'enabled' => false,
        'whitelist' => [], // If not empty, only these IPs can access
        'blacklist' => [], // Block these IPs
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Two-factor authentication settings
    |
    */
    '2fa' => [
        'enabled' => false,
        'optional' => true,
        'backup_codes' => 10,
        'remember_device_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Data encryption settings
    |
    */
    'encryption' => [
        'sensitive_fields' => [
            'phone',
            'ssn',
            'credit_card',
        ],
        'encrypt_database_connections' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Activity logging for compliance and security
    |
    */
    'audit' => [
        'enabled' => true,
        'log_authentication' => true,
        'log_authorization_failures' => true,
        'log_data_changes' => true,
        'log_api_requests' => true,
        'retention_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Scanning
    |--------------------------------------------------------------------------
    |
    | Enable automated security checks
    |
    */
    'scanning' => [
        'check_ssl_certificate' => true,
        'check_headers' => true,
        'check_dependencies' => true,
    ],
];
