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
        return Setting::getValue('recaptcha_enabled', 'false') === 'true'
            && !empty(Setting::getValue('recaptcha_secret_key', ''))
            && !empty(Setting::getValue('recaptcha_site_key', ''));
    }

    public function verify(string $token, string $action = 'register', float $threshold = 0.5): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (empty($token)) {
            Log::warning('reCAPTCHA verification: missing token');
            return false;
        }

        try {
            $response = $this->recaptcha->verify($token);

            if (!$response->isSuccess()) {
                Log::warning('reCAPTCHA verification failed', [
                    'errors' => $response->getErrorCodes(),
                ]);
                return false;
            }

            if ($response->getAction() !== $action) {
                Log::warning('reCAPTCHA verification: action mismatch', [
                    'expected' => $action,
                    'got' => $response->getAction(),
                ]);
                return false;
            }

            $score = $response->getScore();
            if ($score < $threshold) {
                Log::warning('reCAPTCHA verification: score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification exception: ' . $e->getMessage());
            return false;
        }
    }

    public function getSiteKey(): string
    {
        return Setting::getValue('recaptcha_site_key', '');
    }
}
