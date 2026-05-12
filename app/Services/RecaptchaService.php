<?php

namespace App\Services;

use ReCaptcha\ReCaptcha;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    protected ReCaptcha $recaptcha;

    public function __construct()
    {
        $secretKey = Setting::getValue('recaptcha_secret_key', '');
        $this->recaptcha = new ReCaptcha($secretKey);
    }

    public function isEnabled(): bool
    {
        return Setting::getValue('recaptcha_enabled', '0') == '1'
            && !empty(Setting::getValue('recaptcha_secret_key', ''))
            && !empty(Setting::getValue('recaptcha_site_key', ''));
    }

    public function verify(string $token, string $action = 'register', float $threshold = 0.5): bool
    {
        if (!$this->isEnabled()) {
            Log::info('reCAPTCHA verification skipped: CAPTCHA disabled');
            return true;
        }

        if (empty($token)) {
            Log::warning('reCAPTCHA verification failed: missing token');
            return false;
        }

        try {
            Log::info('reCAPTCHA verification starting', [
                'action' => $action,
                'threshold' => $threshold,
            ]);

            $response = $this->recaptcha->verify($token);

            if (!$response->isSuccess()) {
                $errors = $response->getErrorCodes();
                Log::warning('reCAPTCHA API returned failure', [
                    'errors' => $errors,
                    'token_length' => strlen($token),
                ]);
                return false;
            }

            $responseAction = $response->getAction();
            if ($responseAction !== $action) {
                Log::warning('reCAPTCHA verification: action mismatch', [
                    'expected' => $action,
                    'got' => $responseAction,
                ]);
                return false;
            }

            $score = $response->getScore();
            Log::info('reCAPTCHA verification score', [
                'score' => $score,
                'threshold' => $threshold,
            ]);

            if ($score < $threshold) {
                Log::warning('reCAPTCHA verification: score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                ]);
                return false;
            }

            Log::info('reCAPTCHA verification success');
            return true;
        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    public function getSiteKey(): string
    {
        return Setting::getValue('recaptcha_site_key', '');
    }
}
