<?php

namespace App\Helpers;

use App\Services\SecurityService;

/**
 * Security Helper Functions
 *
 * Provides convenient helper functions for common security operations
 */

/**
 * Check if user can access resource
 */
function can_access($ability, $resource = null)
{
    return auth()->check() && auth()->user()->can($ability, $resource);
}

/**
 * Generate secure token
 */
function generate_secure_token($length = 32)
{
    return SecurityService::generateSecureToken($length);
}

/**
 * Check password strength
 */
function check_password_strength($password)
{
    return SecurityService::getPasswordStrength($password);
}

/**
 * Sanitize user input
 */
function sanitize($input)
{
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }

    return SecurityService::sanitizeInput($input);
}

/**
 * Check if IP is allowed
 */
function is_ip_allowed($ip)
{
    return SecurityService::isAccessAllowed($ip);
}

/**
 * Get remaining login attempts for an email
 */
function remaining_login_attempts($email)
{
    return SecurityService::getRemainingLoginAttempts($email);
}

/**
 * Get password policy requirements as string
 */
function password_policy_text()
{
    $config = config('security.password');

    $requirements = [];
    $requirements[] = "At least {$config['min_length']} characters";

    if ($config['require_uppercase'] ?? true) {
        $requirements[] = "Uppercase letters (A-Z)";
    }

    if ($config['require_lowercase'] ?? true) {
        $requirements[] = "Lowercase letters (a-z)";
    }

    if ($config['require_numbers'] ?? true) {
        $requirements[] = "Numbers (0-9)";
    }

    if ($config['require_symbols'] ?? true) {
        $requirements[] = "Symbols (!@#$%^&*)";
    }

    return implode(", ", $requirements);
}

/**
 * Check if password meets policy requirements (UI validation only)
 */
function meets_password_policy($password)
{
    $config = config('security.password');

    if (strlen($password) < ($config['min_length'] ?? 8)) {
        return false;
    }

    if (($config['require_uppercase'] ?? true) && !preg_match('/[A-Z]/', $password)) {
        return false;
    }

    if (($config['require_lowercase'] ?? true) && !preg_match('/[a-z]/', $password)) {
        return false;
    }

    if (($config['require_numbers'] ?? true) && !preg_match('/[0-9]/', $password)) {
        return false;
    }

    if (($config['require_symbols'] ?? true) && !preg_match('/[^a-zA-Z0-9]/', $password)) {
        return false;
    }

    return true;
}
