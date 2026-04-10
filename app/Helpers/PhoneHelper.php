<?php

namespace App\Helpers;

/**
 * Phone number normalization helper for Kenyan mobile numbers.
 * Converts various formats to standard E.164 format (254XXXXXXXXX).
 *
 * Examples:
 * - 0712345678 → 254712345678
 * - +254712345678 → 254712345678
 * - 254712345678 → 254712345678 (unchanged)
 * - 712345678 → 254712345678
 */
class PhoneHelper
{
    /**
     * Normalize a phone number to Kenyan E.164 format (254XXXXXXXXX).
     *
     * @param string $phone The phone number to normalize
     * @return string The normalized phone number
     */
    public static function normalize(string $phone): string
    {
        // Strip all non-digit characters except + at the start
        $phone = preg_replace('/[\s\-()]+/', '', $phone);

        // +254... → 254...
        if (str_starts_with($phone, '+254')) {
            return '254' . substr($phone, 4);
        }

        // Already in correct format
        if (str_starts_with($phone, '254')) {
            return $phone;
        }

        // 0... → 254...
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        // Bare digits → prepend 254
        return '254' . $phone;
    }
}
