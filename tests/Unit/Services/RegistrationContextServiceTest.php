<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\RegistrationContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RegistrationContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_phone_for_platform_register_url(): void
    {
        session()->forget('registration_reseller_id');

        $this->assertTrue(app(RegistrationContextService::class)->requiresPhoneCapture());
    }

    public function test_does_not_require_phone_when_reseller_invite_in_session(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        session(['registration_reseller_id' => $reseller->id]);

        $this->assertFalse(app(RegistrationContextService::class)->requiresPhoneCapture());
    }

    public function test_does_not_require_phone_on_reseller_custom_domain(): void
    {
        session()->forget('registration_reseller_id');

        User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Billing Co',
                    'custom_domain' => 'billing.example.test',
                ],
            ],
        ]);

        $request = Request::create('https://billing.example.test/register', 'GET');

        $this->assertFalse(app(RegistrationContextService::class)->requiresPhoneCapture($request));
    }

    public function test_platform_registration_url_points_to_register_route(): void
    {
        $url = app(RegistrationContextService::class)->platformRegistrationUrl();

        $this->assertStringContainsString('/register', $url);
    }
}
