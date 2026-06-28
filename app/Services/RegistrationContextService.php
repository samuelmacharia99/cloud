<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Determines registration context: platform (admin-owned) vs reseller-branded signup.
 */
class RegistrationContextService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    /**
     * Platform customers signing up on the admin URL must provide a phone number
     * so verification codes can be sent by SMS as well as email.
     */
    public function requiresPhoneCapture(?Request $request = null): bool
    {
        if (! config('registration.require_phone_for_platform_signup', true)) {
            return false;
        }

        if (session()->has('registration_reseller_id')) {
            return false;
        }

        $request ??= request();

        if ($request && $this->brandingResolver->resolveFromHost($request->getHost())) {
            return false;
        }

        return true;
    }

    public function platformRegistrationUrl(): string
    {
        return route('register', absolute: true);
    }
}
