<?php

namespace Tests\Unit\Services;

use App\Services\RegistrationGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class RegistrationGuardServiceTest extends TestCase
{
    public function test_expired_registration_token_returns_user_facing_error(): void
    {
        config(['registration.max_form_age_seconds' => 60, 'registration.min_submit_seconds' => 0]);

        $token = Crypt::encryptString((string) now()->subMinutes(5)->timestamp);

        $request = Request::create('/register', 'POST', [
            'registration_token' => $token,
        ]);

        $message = app(RegistrationGuardService::class)->submissionTimingError($request);

        $this->assertSame(
            'Your registration session expired. Please refresh the page and try again.',
            $message
        );
    }

    public function test_too_fast_submission_returns_wait_message(): void
    {
        config(['registration.min_submit_seconds' => 10, 'registration.max_form_age_seconds' => 7200]);

        $token = Crypt::encryptString((string) now()->timestamp);

        $request = Request::create('/register', 'POST', [
            'registration_token' => $token,
        ]);

        $message = app(RegistrationGuardService::class)->submissionTimingError($request);

        $this->assertSame(
            'Please wait a moment after the form loads, then try again.',
            $message
        );
    }

    public function test_valid_timing_returns_null(): void
    {
        config(['registration.min_submit_seconds' => 0, 'registration.max_form_age_seconds' => 7200]);

        $token = Crypt::encryptString((string) now()->subSeconds(5)->timestamp);

        $request = Request::create('/register', 'POST', [
            'registration_token' => $token,
        ]);

        $this->assertNull(app(RegistrationGuardService::class)->submissionTimingError($request));
    }

    public function test_build_display_name_uses_first_name_only_when_last_name_missing(): void
    {
        $guard = app(RegistrationGuardService::class);

        $this->assertSame('Jane', $guard->buildDisplayName('Jane'));
        $this->assertSame('Jane', $guard->buildDisplayName('Jane', ''));
        $this->assertSame('Jane Doe', $guard->buildDisplayName('Jane', 'Doe'));
    }
}
