<?php

namespace Tests\Unit\Services\PaymentGateway;

use App\Services\PaymentGateway\PayPalConnectService;
use Illuminate\Http\Request;
use Tests\TestCase;

class PayPalConnectServiceTest extends TestCase
{
    public function test_build_auth_assertion_uses_unsigned_jwt_format(): void
    {
        $service = new PayPalConnectService;

        $assertion = $service->buildAuthAssertion('partner-client-id', 'MERCHANT123');

        $this->assertStringEndsWith('.', $assertion);
        $parts = explode('.', $assertion);
        $this->assertCount(3, $parts);
        $this->assertSame('', $parts[2]);

        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('partner-client-id', $payload['iss']);
        $this->assertSame('MERCHANT123', $payload['payer_id']);
    }

    public function test_is_partner_configured_requires_partner_env_values(): void
    {
        config([
            'paypal.partner.client_id' => null,
            'paypal.partner.client_secret' => null,
            'paypal.partner.merchant_id' => null,
        ]);
        $this->assertFalse(PayPalConnectService::isPartnerConfigured());

        config([
            'paypal.partner.client_id' => 'id',
            'paypal.partner.client_secret' => 'secret',
            'paypal.partner.merchant_id' => 'merchant',
        ]);
        $this->assertTrue(PayPalConnectService::isPartnerConfigured());
    }

    public function test_handle_connect_callback_rejects_missing_merchant_id(): void
    {
        config([
            'paypal.partner.client_id' => 'partner-id',
            'paypal.partner.client_secret' => 'partner-secret',
            'paypal.partner.merchant_id' => 'partner-merchant',
        ]);

        $result = app(PayPalConnectService::class)->handleConnectCallback(
            Request::create('/callback', 'GET'),
            'sandbox'
        );

        $this->assertFalse($result['success']);
    }
}
