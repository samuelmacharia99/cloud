<?php

namespace App\Services\PaymentGateway;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayPalConnectService
{
    public static function partnerClientId(): ?string
    {
        $value = Setting::getValue('paypal_partner_client_id');

        return filled($value) ? (string) $value : config('paypal.partner.client_id');
    }

    public static function partnerClientSecret(): ?string
    {
        $value = Setting::getValue('paypal_partner_client_secret');

        return filled($value) ? (string) $value : config('paypal.partner.client_secret');
    }

    public static function partnerMerchantId(): ?string
    {
        $value = Setting::getValue('paypal_partner_merchant_id');

        return filled($value) ? (string) $value : config('paypal.partner.merchant_id');
    }

    public static function partnerBnCode(): ?string
    {
        $value = Setting::getValue('paypal_partner_bn_code');

        return filled($value) ? (string) $value : config('paypal.partner.bn_code');
    }

    public static function isPartnerConfigured(): bool
    {
        return filled(self::partnerClientId())
            && filled(self::partnerClientSecret())
            && filled(self::partnerMerchantId());
    }

    public function isConnected(): bool
    {
        return Setting::getValue('paypal_connection_mode') === 'partner'
            && filled(Setting::getValue('paypal_merchant_id'));
    }

    /**
     * @return array{connected: bool, merchant_id: ?string, email: ?string, connected_at: ?string, ready: bool, status_message: ?string}
     */
    public function connectionSummary(): array
    {
        $merchantId = Setting::getValue('paypal_merchant_id');
        $connected = $this->isConnected();

        return [
            'connected' => $connected,
            'merchant_id' => $merchantId ?: null,
            'email' => Setting::getValue('paypal_merchant_email') ?: null,
            'connected_at' => Setting::getValue('paypal_connected_at') ?: null,
            'ready' => $connected && Setting::getValue('paypal_onboarding_ready') === '1',
            'status_message' => Setting::getValue('paypal_onboarding_status') ?: null,
        ];
    }

    /**
     * @return array{success: bool, action_url?: string, message?: string}
     */
    public function createConnectReferral(string $environment): array
    {
        if (! self::isPartnerConfigured()) {
            return [
                'success' => false,
                'message' => 'PayPal partner credentials are not configured on this server.',
            ];
        }

        $token = $this->getPartnerAccessToken($environment);
        if (! $token) {
            return [
                'success' => false,
                'message' => 'Could not authenticate with PayPal partner API.',
            ];
        }

        $trackingId = 'talksasa-'.Str::uuid()->toString();
        $returnUrl = route('admin.settings.paypal.connect.callback', [
            'environment' => $environment,
        ]);

        $payload = [
            'tracking_id' => $trackingId,
            'partner_config_override' => [
                'return_url' => $returnUrl,
                'return_url_description' => 'Return to '.config('app.name'),
            ],
            'operations' => [
                [
                    'operation' => 'API_INTEGRATION',
                    'api_integration_preference' => [
                        'rest_api_integration' => [
                            'integration_method' => 'PAYPAL',
                            'integration_type' => 'THIRD_PARTY',
                            'third_party_details' => [
                                'features' => ['PAYMENT', 'REFUND'],
                            ],
                        ],
                    ],
                ],
            ],
            'products' => ['EXPRESS_CHECKOUT'],
            'legal_consents' => [
                [
                    'type' => 'SHARE_DATA_CONSENT',
                    'granted' => true,
                ],
            ],
        ];

        $response = Http::withHeaders($this->partnerHeaders($token))
            ->post($this->apiBaseUrl($environment).'/v2/customer/partner-referrals', $payload);

        if (! $response->successful()) {
            Log::error('PayPal partner referral failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            $message = $response->json('message') ?? 'PayPal could not start account linking.';

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $actionUrl = collect($response->json('links', []))
            ->firstWhere('rel', 'action_url')['href'] ?? null;

        if (! $actionUrl) {
            return [
                'success' => false,
                'message' => 'PayPal did not return a connect URL.',
            ];
        }

        Setting::setValue('paypal_connect_tracking_id', $trackingId);
        Setting::setValue('paypal_environment', $environment);

        return [
            'success' => true,
            'action_url' => $actionUrl,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function handleConnectCallback(Request $request, string $environment): array
    {
        if (! self::isPartnerConfigured()) {
            return [
                'success' => false,
                'message' => 'PayPal partner credentials are not configured.',
            ];
        }

        $merchantId = (string) $request->query('merchantIdInPayPal', '');
        $permissionsGranted = filter_var($request->query('permissionsGranted'), FILTER_VALIDATE_BOOLEAN);
        $consentStatus = filter_var($request->query('consentStatus'), FILTER_VALIDATE_BOOLEAN);

        if ($merchantId === '') {
            return [
                'success' => false,
                'message' => 'PayPal did not return a merchant account ID. Complete the linking flow on PayPal and try again.',
            ];
        }

        if (! $permissionsGranted || ! $consentStatus) {
            return [
                'success' => false,
                'message' => 'PayPal account permissions were not granted. Please connect again and approve all requested permissions.',
            ];
        }

        $integration = $this->fetchSellerIntegration($environment, $merchantId);
        if ($integration === null) {
            return [
                'success' => false,
                'message' => 'Linked PayPal account could not be verified. Try again in a few minutes.',
            ];
        }

        $merchantClientId = $this->extractMerchantClientId($integration);

        Setting::setValue('paypal_connection_mode', 'partner');
        Setting::setValue('paypal_merchant_id', $merchantId);
        Setting::setValue('paypal_merchant_email', (string) ($integration['primary_email'] ?? ''));
        Setting::setValue('paypal_connected_at', now()->toIso8601String());
        Setting::setValue('paypal_environment', $environment);

        if ($merchantClientId) {
            Setting::setValue('paypal_client_id', $merchantClientId);
        }

        $ready = ($integration['payments_receivable'] ?? false) === true
            && ($integration['primary_email_confirmed'] ?? false) === true;

        Setting::setValue('paypal_onboarding_ready', $ready ? '1' : '0');
        Setting::setValue(
            'paypal_onboarding_status',
            $ready
                ? 'PayPal account linked and ready to accept payments.'
                : 'PayPal account linked. Confirm your PayPal email or finish business setup on PayPal to receive payments.'
        );

        if ($ready) {
            Setting::setValue('paypal_enabled', '1');
        }

        return [
            'success' => true,
            'message' => Setting::getValue('paypal_onboarding_status'),
        ];
    }

    public function disconnect(): void
    {
        foreach ([
            'paypal_connection_mode',
            'paypal_merchant_id',
            'paypal_merchant_email',
            'paypal_connected_at',
            'paypal_connect_tracking_id',
            'paypal_onboarding_ready',
            'paypal_onboarding_status',
        ] as $key) {
            Setting::setValue($key, '');
        }
    }

    public function refreshConnectionStatus(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }

        $environment = Setting::getValue('paypal_environment', 'sandbox');
        $merchantId = Setting::getValue('paypal_merchant_id');
        $integration = $this->fetchSellerIntegration($environment, $merchantId);

        if ($integration === null) {
            return null;
        }

        $ready = ($integration['payments_receivable'] ?? false) === true
            && ($integration['primary_email_confirmed'] ?? false) === true;

        Setting::setValue('paypal_merchant_email', (string) ($integration['primary_email'] ?? Setting::getValue('paypal_merchant_email')));
        Setting::setValue('paypal_onboarding_ready', $ready ? '1' : '0');
        Setting::setValue(
            'paypal_onboarding_status',
            $ready
                ? 'PayPal account linked and ready to accept payments.'
                : 'PayPal account linked. Confirm your PayPal email or finish business setup on PayPal to receive payments.'
        );

        $merchantClientId = $this->extractMerchantClientId($integration);
        if ($merchantClientId) {
            Setting::setValue('paypal_client_id', $merchantClientId);
        }

        return $integration;
    }

    public function buildAuthAssertion(string $partnerClientId, string $merchantPayerId): string
    {
        $header = base64_encode(json_encode(['alg' => 'none'], JSON_UNESCAPED_SLASHES));
        $payload = base64_encode(json_encode([
            'iss' => $partnerClientId,
            'payer_id' => $merchantPayerId,
        ], JSON_UNESCAPED_SLASHES));

        return $header.'.'.$payload.'.';
    }

    public function getPartnerAccessToken(?string $environment = null): ?string
    {
        $environment ??= Setting::getValue('paypal_environment', 'sandbox');

        try {
            $response = Http::withBasicAuth(
                (string) self::partnerClientId(),
                (string) self::partnerClientSecret(),
            )
                ->asForm()
                ->post($this->apiBaseUrl($environment).'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::error('PayPal partner token failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PayPal partner token exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSellerIntegration(string $environment, string $sellerMerchantId): ?array
    {
        $token = $this->getPartnerAccessToken($environment);
        if (! $token) {
            return null;
        }

        $partnerMerchantId = (string) self::partnerMerchantId();
        $url = $this->apiBaseUrl($environment)
            .'/v1/customer/partners/'.$partnerMerchantId
            .'/merchant-integrations/'.$sellerMerchantId;

        $response = Http::withHeaders($this->partnerHeaders($token))->get($url);

        if (! $response->successful()) {
            Log::warning('PayPal seller integration lookup failed', [
                'seller_merchant_id' => $sellerMerchantId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $integration
     */
    private function extractMerchantClientId(array $integration): ?string
    {
        foreach ($integration['oauth_integrations'] ?? [] as $oauthIntegration) {
            foreach ($oauthIntegration['oauth_third_party'] ?? [] as $thirdParty) {
                $clientId = $thirdParty['merchant_client_id'] ?? null;
                if (filled($clientId)) {
                    return (string) $clientId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function partnerHeaders(string $accessToken): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$accessToken,
            'Content-Type' => 'application/json',
        ];

        $bnCode = self::partnerBnCode();
        if (filled($bnCode)) {
            $headers['PayPal-Partner-Attribution-Id'] = (string) $bnCode;
        }

        return $headers;
    }

    private function apiBaseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.paypal.com'
            : 'https://api.sandbox.paypal.com';
    }

    /**
     * @return array{success: bool, message: string, environment?: string, mode?: string}
     */
    public function testConnection(): array
    {
        $environment = Setting::getValue('paypal_environment', 'sandbox');
        $environmentLabel = $environment === 'production' ? 'production' : 'sandbox';

        if (self::isPartnerConfigured()) {
            $token = $this->getPartnerAccessToken($environment);
            if (! $token) {
                return [
                    'success' => false,
                    'message' => 'Partner API authentication failed. Check Client ID, Secret, and environment.',
                    'environment' => $environmentLabel,
                    'mode' => 'partner',
                ];
            }

            if ($this->isConnected()) {
                $integration = $this->refreshConnectionStatus();
                if ($integration === null) {
                    return [
                        'success' => false,
                        'message' => 'Partner credentials work, but the linked merchant account could not be verified.',
                        'environment' => $environmentLabel,
                        'mode' => 'partner_connected',
                    ];
                }

                $ready = ($integration['payments_receivable'] ?? false) === true
                    && ($integration['primary_email_confirmed'] ?? false) === true;

                return [
                    'success' => true,
                    'message' => $ready
                        ? 'Partner API and linked merchant account are ready to accept payments.'
                        : 'Partner API verified. Finish merchant setup on PayPal to receive payments.',
                    'environment' => $environmentLabel,
                    'mode' => 'partner_connected',
                ];
            }

            return [
                'success' => true,
                'message' => 'Partner API credentials verified. Connect a business account to receive payments.',
                'environment' => $environmentLabel,
                'mode' => 'partner',
            ];
        }

        $clientId = Setting::getValue('paypal_client_id');
        $clientSecret = Setting::getValue('paypal_client_secret');

        if (! filled($clientId) || ! filled($clientSecret)) {
            return [
                'success' => false,
                'message' => 'Save partner credentials or manual Client ID and Secret, then try again.',
            ];
        }

        try {
            $response = Http::withBasicAuth((string) $clientId, (string) $clientSecret)
                ->asForm()
                ->post($this->apiBaseUrl($environment).'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Merchant API authentication failed. Check Client ID, Secret, and environment.',
                    'environment' => $environmentLabel,
                    'mode' => 'manual',
                ];
            }

            return [
                'success' => true,
                'message' => 'Merchant API credentials verified.',
                'environment' => $environmentLabel,
                'mode' => 'manual',
            ];
        } catch (\Throwable $e) {
            Log::error('PayPal connection test failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Connection test failed: '.$e->getMessage(),
                'environment' => $environmentLabel,
            ];
        }
    }
}
