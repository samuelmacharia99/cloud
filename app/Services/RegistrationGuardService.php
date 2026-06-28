<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RegistrationGuardService
{
    public function shouldRejectAsBot(Request $request): bool
    {
        return $this->honeypotFilled($request);
    }

    /**
     * User-facing validation for the encrypted registration timing token.
     * Returns null when the submission timing is acceptable.
     */
    public function submissionTimingError(Request $request): ?string
    {
        $token = (string) $request->input('registration_token', '');
        if ($token === '') {
            return 'Your registration session expired. Please refresh the page and try again.';
        }

        try {
            $startedAt = (int) Crypt::decryptString($token);
        } catch (\Throwable) {
            return 'Your registration session expired. Please refresh the page and try again.';
        }

        $elapsed = now()->timestamp - $startedAt;
        $minSeconds = (int) config('registration.min_submit_seconds', 3);
        $maxAge = (int) config('registration.max_form_age_seconds', 7200);

        if ($elapsed < $minSeconds) {
            return 'Please wait a moment after the form loads, then try again.';
        }

        if ($elapsed > $maxAge) {
            return 'Your registration session expired. Please refresh the page and try again.';
        }

        return null;
    }

    public function rejectBotSubmission(Request $request): RedirectResponse
    {
        return back()
            ->withInput($request->except('password', 'password_confirmation'))
            ->withErrors([
                'email' => 'Unable to complete registration. Please try again or contact support if this continues.',
            ]);
    }

    public function enforceRateLimits(Request $request): void
    {
        $ip = (string) $request->ip();
        $config = config('registration.rate_limit', []);

        $ipKey = 'registration:ip:'.$ip;
        $ipLimit = (int) ($config['per_ip_per_day'] ?? 10);
        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            throw ValidationException::withMessages([
                'email' => 'Too many registration attempts from your network. Please try again later.',
            ]);
        }

        $globalKey = 'registration:global';
        $globalLimit = (int) ($config['global_per_hour'] ?? 50);
        if (RateLimiter::tooManyAttempts($globalKey, $globalLimit)) {
            throw ValidationException::withMessages([
                'email' => 'Registration is temporarily unavailable. Please try again later.',
            ]);
        }

        RateLimiter::hit($ipKey, 86400);
        RateLimiter::hit($globalKey, 3600);
    }

    public function makeFormToken(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }

    public function buildDisplayName(string $firstName, ?string $lastName = null): string
    {
        $first = trim(preg_replace('/\s+/u', ' ', $firstName) ?? $firstName);
        $last = trim(preg_replace('/\s+/u', ' ', (string) $lastName) ?? (string) $lastName);

        return $last !== '' ? $first.' '.$last : $first;
    }

    public function validateNamePart(string $name, string $fieldLabel = 'name'): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return "Please enter your {$fieldLabel}.";
        }

        if (! preg_match('/^[\p{L}\s\'\-\.]+$/u', $name)) {
            return ucfirst($fieldLabel).' contains invalid characters.';
        }

        $minWord = (int) config('registration.name.min_word_length', 2);
        foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
            if (mb_strlen($part) < $minWord) {
                return 'Please enter a valid '.($fieldLabel === 'name' ? 'name' : $fieldLabel).'.';
            }
        }

        if ($this->looksLikeRandomName($name)) {
            return 'Please enter a valid '.($fieldLabel === 'name' ? 'name' : $fieldLabel).'.';
        }

        return null;
    }

    public function validateHumanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return 'Please enter your full name.';
        }

        if (! preg_match('/^[\p{L}\s\'\-\.]+$/u', $name)) {
            return 'Name contains invalid characters.';
        }

        $config = config('registration.name', []);
        $parts = preg_split('/\s+/u', $name) ?: [];

        if (($config['require_two_words'] ?? true) && count($parts) < 2) {
            return 'Please enter your first and last name.';
        }

        $minWord = (int) ($config['min_word_length'] ?? 2);
        foreach ($parts as $part) {
            if (mb_strlen($part) < $minWord) {
                return 'Please enter a valid full name.';
            }
        }

        if (count($parts) === 1) {
            $maxSingle = (int) ($config['max_single_word_length'] ?? 24);
            if (mb_strlen($parts[0]) > $maxSingle) {
                return 'Please enter a valid full name.';
            }
        }

        if ($this->looksLikeRandomName($name)) {
            return 'Please enter a valid full name.';
        }

        return null;
    }

    public function validateEmailDomain(string $email): ?string
    {
        $domain = strtolower((string) substr(strrchr($email, '@'), 1));
        if ($domain === '') {
            return 'Please enter a valid email address.';
        }

        $blocked = config('registration.disposable_domains', []);
        if (in_array($domain, $blocked, true)) {
            return 'Disposable email addresses are not allowed. Please use a permanent email.';
        }

        if (config('registration.check_mx_record', false)) {
            if (! checkdnsrr($domain, 'MX') && ! checkdnsrr($domain, 'A')) {
                return 'Email domain does not appear to accept mail. Please use a valid email address.';
            }
        }

        return null;
    }

    private function honeypotFilled(Request $request): bool
    {
        $field = (string) config('registration.honeypot_field', 'contact_website');

        return trim((string) $request->input($field, '')) !== '';
    }

    private function looksLikeRandomName(string $name): bool
    {
        $compact = preg_replace('/\s+/u', '', $name) ?? $name;
        if (mb_strlen($compact) < 12) {
            return false;
        }

        if (! preg_match('/[A-Z]/', $compact) || ! preg_match('/[a-z]/', $compact)) {
            return false;
        }

        $transitions = 0;
        $length = strlen($compact);
        for ($i = 1; $i < $length; $i++) {
            $prevUpper = ctype_upper($compact[$i - 1]);
            $currUpper = ctype_upper($compact[$i]);
            if ($prevUpper !== $currUpper) {
                $transitions++;
            }
        }

        return $transitions >= 5;
    }
}
