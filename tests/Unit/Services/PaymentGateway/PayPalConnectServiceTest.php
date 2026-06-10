<?php

namespace Tests\Unit\Services\PaymentGateway;

use App\Services\PaymentGateway\PayPalConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalConnectServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->primeSettingCache([
            'paypal_partner_client_id' => '',
            'paypal_partner_client_secret' => '',
            'paypal_partner_merchant_id' => '',
            'paypal_partner_bn_code' => '',
            'paypal_environment' => 'sandbox',
            'paypal_client_id' => '',
            'paypal_client_secret' => '',
            'paypal_connection_mode' => '',
            'paypal_merchant_id' => '',
        ]);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function primeSettingCache(array $values): void
    {
        foreach ($values as $key => $value) {
            Cache::forever("setting:value:{$key}", $value);
        }
    }

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

    public function test_is_partner_configured_uses_config_fallback(): void
    {
        config([
            'paypal.partner.client_id' => 'env-client',
            'paypal.partner.client_secret' => 'env-secret',
            'paypal.partner.merchant_id' => 'env-merchant',
        ]);

        $this->assertTrue(PayPalConnectService::isPartnerConfigured());
        $this->assertSame('env-client', PayPalConnectService::partnerClientId());
        $this->assertSame('env-merchant', PayPalConnectService::partnerMerchantId());
    }

    public function test_partner_credentials_prefer_settings_over_config(): void
    {
        config([
            'paypal.partner.client_id' => 'env-client',
            'paypal.partner.client_secret' => 'env-secret',
            'paypal.partner.merchant_id' => 'env-merchant',
        ]);

        $this->primeSettingCache([
            'paypal_partner_client_id' => 'ui-client',
            'paypal_partner_client_secret' => 'ui-secret',
            'paypal_partner_merchant_id' => 'ui-merchant',
        ]);

        $this->assertTrue(PayPalConnectService::isPartnerConfigured());
        $this->assertSame('ui-client', PayPalConnectService::partnerClientId());
        $this->assertSame('ui-secret', PayPalConnectService::partnerClientSecret());
        $this->assertSame('ui-merchant', PayPalConnectService::partnerMerchantId());
    }

    public function test_test_connection_verifies_partner_credentials(): void
    {
        config([
            'paypal.partner.client_id' => 'env-client',
            'paypal.partner.client_secret' => 'env-secret',
            'paypal.partner.merchant_id' => 'env-merchant',
        ]);

        Http::fake([
            'https://api.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'partner-token',
            ]),
        ]);

        $result = app(PayPalConnectService::class)->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('partner', $result['mode']);
        $this->assertStringContainsString('Partner API credentials verified', $result['message']);
    }

    public function test_test_connection_requires_credentials(): void
    {
        $result = app(PayPalConnectService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Save partner credentials', $result['message']);
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
