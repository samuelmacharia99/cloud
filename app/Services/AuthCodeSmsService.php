<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one-time auth codes via SMS for login / email verification flows.
 * Does not check notification preferences — auth codes always attempt delivery.
 */
class AuthCodeSmsService
{
    public function __construct(
        private SmsService $platformSms,
        private TalksasaSmsService $resellerSms,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function canSend(User $user): bool
    {
        return $this->phoneFor($user) !== null && $this->isConfiguredFor($user);
    }

    public function isConfiguredFor(User $user): bool
    {
        if ($user->reseller_id !== null) {
            $reseller = $this->brandingResolver->resellerForCustomer($user);
            if ($reseller) {
                $sms = $reseller->settings['sms'] ?? [];
                if (! empty($sms['enabled']) && ! empty($sms['api_key']) && ! empty($sms['sender_id'])) {
                    return true;
                }
            }
        }

        return $this->platformSms->isConfigured();
    }

    /**
     * @return array{success: bool, message: string, channel?: string}
     */
    public function send(User $user, string $message): array
    {
        $phone = $this->phoneFor($user);

        if ($phone === null) {
            return [
                'success' => false,
                'message' => 'No phone number on file.',
            ];
        }

        if ($user->reseller_id !== null) {
            $reseller = $this->brandingResolver->resellerForCustomer($user);
            if ($reseller) {
                $sms = $reseller->settings['sms'] ?? [];
                if (! empty($sms['enabled']) && ! empty($sms['api_key'])) {
                    $result = $this->resellerSms->sendSms($reseller, $phone, $message);

                    return array_merge($result, ['channel' => 'reseller_sms']);
                }
            }
        }

        if ($this->platformSms->isConfigured()) {
            $result = $this->platformSms->send($phone, $message);

            return array_merge($result, ['channel' => 'platform_sms']);
        }

        Log::warning('Auth code SMS skipped — no SMS configuration', [
            'user_id' => $user->id,
            'reseller_id' => $user->reseller_id,
        ]);

        return [
            'success' => false,
            'message' => 'SMS is not configured.',
        ];
    }

    private function phoneFor(User $user): ?string
    {
        $phone = trim((string) ($user->phone ?? ''));

        return $phone !== '' ? $phone : null;
    }

    public function siteNameFor(User $user): string
    {
        if ($user->reseller_id !== null) {
            return $this->brandingResolver->forCustomer($user)['company_name'];
        }

        return Setting::getValue('company_name', Setting::getValue('site_name', config('app.name')));
    }
}
