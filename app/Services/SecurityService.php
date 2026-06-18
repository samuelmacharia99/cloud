<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class SecurityService
{
    /**
     * Validate password strength against configured policy.
     */
    public static function getPasswordRule(): Password
    {
        $config = config('security.password');

        $rule = Password::min($config['min_length'] ?? 8)
            ->letters()
            ->numbers();

        if ($config['require_uppercase'] ?? true) {
            $rule->mixedCase();
        }

        if ($config['require_symbols'] ?? true) {
            $rule->symbols();
        }

        return $rule->uncompromised();
    }

    /**
     * Check if login attempt is rate limited.
     */
    public static function isLoginRateLimited(string $email): bool
    {
        $config = config('security.rate_limit');
        $key = 'login_attempts:'.$email;
        $limit = $config['login_attempts'] ?? 5;
        $window = $config['login_window'] ?? 15;

        return RateLimiter::tooManyAttempts($key, $limit);
    }

    /**
     * Increment login attempt counter.
     */
    public static function recordFailedLoginAttempt(string $email): void
    {
        $config = config('security.rate_limit');
        $key = 'login_attempts:'.$email;
        $window = $config['login_window'] ?? 15;

        RateLimiter::hit($key, $window * 60);
    }

    /**
     * Clear login attempt counter on successful login.
     */
    public static function clearLoginAttempts(string $email): void
    {
        $key = 'login_attempts:'.$email;
        RateLimiter::clear($key);
    }

    /**
     * Get remaining login attempts.
     */
    public static function getRemainingLoginAttempts(string $email): int
    {
        $config = config('security.rate_limit');
        $key = 'login_attempts:'.$email;
        $limit = $config['login_attempts'] ?? 5;

        return max(0, $limit - RateLimiter::attempts($key));
    }

    /**
     * Check if email verification is rate limited.
     */
    public static function isEmailVerificationRateLimited(string $email): bool
    {
        $config = config('security.rate_limit');
        $key = 'email_verification:'.$email;
        $limit = $config['email_verification'] ?? 5;

        return RateLimiter::tooManyAttempts($key, $limit);
    }

    /**
     * Record email verification attempt.
     */
    public static function recordEmailVerificationAttempt(string $email): void
    {
        $key = 'email_verification:'.$email;
        RateLimiter::hit($key, 3600);
    }

    /**
     * Check if password reset is rate limited.
     */
    public static function isPasswordResetRateLimited(string $email): bool
    {
        $config = config('security.rate_limit');
        $key = 'password_reset:'.$email;
        $limit = $config['password_reset'] ?? 3;

        return RateLimiter::tooManyAttempts($key, $limit);
    }

    /**
     * Record password reset attempt.
     */
    public static function recordPasswordResetAttempt(string $email): void
    {
        $key = 'password_reset:'.$email;
        RateLimiter::hit($key, 3600);
    }

    /**
     * Hash and verify sensitive data.
     */
    public static function hashSensitiveData(string $data): string
    {
        return Hash::make($data);
    }

    /**
     * Verify sensitive data hash.
     */
    public static function verifySensitiveData(string $data, string $hash): bool
    {
        return Hash::check($data, $hash);
    }

    /**
     * Sanitize user input to prevent XSS.
     */
    public static function sanitizeInput(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Check if IP is whitelisted.
     */
    public static function isIpWhitelisted(string $ip): bool
    {
        $whitelist = config('security.ip_filtering.whitelist', []);

        if (empty($whitelist)) {
            return true; // No whitelist = all IPs allowed
        }

        return in_array($ip, $whitelist);
    }

    /**
     * Check if IP is blacklisted.
     */
    public static function isIpBlacklisted(string $ip): bool
    {
        $blacklist = config('security.ip_filtering.blacklist', []);

        return in_array($ip, $blacklist);
    }

    /**
     * Check if access should be allowed based on IP filtering.
     */
    public static function isAccessAllowed(string $ip): bool
    {
        if (! config('security.ip_filtering.enabled', false)) {
            return true;
        }

        if (self::isIpBlacklisted($ip)) {
            return false;
        }

        return self::isIpWhitelisted($ip);
    }

    /**
     * Generate secure random token.
     */
    public static function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a strong password that satisfies the configured policy.
     */
    public static function generateSecurePassword(int $length = 16): string
    {
        $config = config('security.password');
        $length = max((int) ($config['min_length'] ?? 8), min(32, $length));

        $sets = [
            'lower' => 'abcdefghjkmnpqrstuvwxyz',
            'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ',
            'digit' => '23456789',
            'symbol' => '!@#$%^&*-_=+',
        ];

        $password = '';
        foreach ($sets as $chars) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $all = implode('', $sets);
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Check password strength.
     */
    public static function getPasswordStrength(string $password): int
    {
        $strength = 0;

        // Length check
        if (strlen($password) >= 8) {
            $strength++;
        }
        if (strlen($password) >= 12) {
            $strength++;
        }

        // Character variety
        if (preg_match('/[a-z]/', $password)) {
            $strength++;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $strength++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $strength++;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $strength++;
        }

        return min($strength, 5); // Return 0-5
    }
}
