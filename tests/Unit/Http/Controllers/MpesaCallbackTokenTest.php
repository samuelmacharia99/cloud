<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\PaymentWebhookController;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class MpesaCallbackTokenTest extends TestCase
{
    use RefreshDatabase;

    private function invokeIsValid(Request $request): bool
    {
        $method = new ReflectionMethod(PaymentWebhookController::class, 'isValidMpesaCallback');
        $method->setAccessible(true);

        return $method->invoke(new PaymentWebhookController, $request);
    }

    public function test_rejects_empty_token_when_app_environment_is_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Setting::setValue('mpesa_environment', 'sandbox');
        Setting::setValue('mpesa_callback_token', '');

        $this->assertFalse($this->invokeIsValid(Request::create('/webhooks/c2b', 'POST')));
    }

    public function test_rejects_empty_token_when_mpesa_environment_is_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        Setting::setValue('mpesa_environment', 'production');
        Setting::setValue('mpesa_callback_token', '');

        $this->assertFalse($this->invokeIsValid(Request::create('/webhooks/c2b', 'POST')));
    }

    public function test_rejects_empty_token_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        Setting::setValue('mpesa_environment', 'sandbox');
        Setting::setValue('mpesa_callback_token', '');

        $this->assertFalse($this->invokeIsValid(Request::create('/webhooks/c2b', 'POST')));
    }

    public function test_validates_query_token_when_configured(): void
    {
        Setting::setValue('mpesa_callback_token', 'secret-token');

        $valid = Request::create('/webhooks/c2b?token=secret-token', 'POST');
        $invalid = Request::create('/webhooks/c2b?token=wrong', 'POST');

        $this->assertTrue($this->invokeIsValid($valid));
        $this->assertFalse($this->invokeIsValid($invalid));
    }
}
