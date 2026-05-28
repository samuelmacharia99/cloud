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
        if ($this->honeypotFilled($request)) {
            return true;
        }

        if ($this->isTooFastSubmission($request)) {
            return true;
        }

        return false;
    }

    public function fakeSuccessRedirect(Request $request): RedirectResponse
    {
        $email = strtolower((string) $request->input('email', ''));

        return redirect()
            ->route('verification.code.show')
            ->with('email', $email !== '' ? $email : null)
            ->with('message', 'We sent a verification code to your email. Please enter it below.');
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

        RateLimiter::hit($ipKey, decay: 86400);
        RateLimiter::hit($globalKey, decay: 3600);
    }

    public function makeFormToken(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
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

    private function isTooFastSubmission(Request $request): bool
    {
        $token = (string) $request->input('registration_token', '');
        if ($token === '') {
            return true;
        }

        try {
            $startedAt = (int) Crypt::decryptString($token);
        } catch (\Throwable) {
            return true;
        }

        $elapsed = now()->timestamp - $startedAt;
        $minSeconds = (int) config('registration.min_submit_seconds', 3);
        $maxAge = (int) config('registration.max_form_age_seconds', 7200);

        if ($elapsed < $minSeconds) {
            return true;
        }

        if ($elapsed > $maxAge) {
            return true;
        }

        return false;
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
