<?php

namespace App\Services\PaymentGateway;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * Create payment gateway instance based on method
     */
    public static function make(PaymentMethod|string $method, ?User $reseller = null): PaymentGatewayInterface
    {
        $method = $method instanceof PaymentMethod ? $method->value : $method;

        return match ($method) {
            'mpesa' => new MpesaService($reseller),
            'stripe' => new StripeService,
            'paypal' => new PayPalService,
            'manual' => new ManualPaymentService,
            default => throw new InvalidArgumentException("Unsupported payment method: {$method}"),
        };
    }

    public static function makeForInvoice(PaymentMethod|string $method, Invoice $invoice): PaymentGatewayInterface
    {
        $invoice->loadMissing('user');
        $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($invoice->user);

        return self::make($method, $reseller);
    }

    /**
     * Get available payment gateways (platform defaults)
     */
    public static function getAvailableGateways(): array
    {
        return self::buildGatewayList(null);
    }

    public static function getAvailableGatewaysForInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('user');
        $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($invoice->user);

        return self::buildGatewayList($reseller);
    }

    /**
     * Check if specific gateway is available
     */
    public static function isAvailable(string $method): bool
    {
        $gateways = self::getAvailableGateways();

        return isset($gateways[$method]);
    }

    /**
     * @return array<string, array{label: string, icon: string, color: string, description: string}>
     */
    private static function buildGatewayList(?User $reseller): array
    {
        $gateways = [];

        $mpesa = new MpesaService($reseller);
        if ($mpesa->isConfigured()) {
            $gateways['mpesa'] = [
                'label' => 'M-PESA',
                'icon' => 'phone',
                'color' => 'green',
                'description' => 'Pay directly from your M-PESA account',
            ];
        }

        try {
            $stripe = new StripeService;
            if ($stripe->isConfigured()) {
                $gateways['stripe'] = [
                    'label' => 'Stripe',
                    'icon' => 'credit-card',
                    'color' => 'purple',
                    'description' => 'Pay with your credit or debit card',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe payment gateway unavailable', ['error' => $e->getMessage()]);
        }

        $paypal = new PayPalService;
        if ($paypal->isConfigured()) {
            $gateways['paypal'] = [
                'label' => 'PayPal',
                'icon' => 'globe',
                'color' => 'blue',
                'description' => 'Pay safely with your PayPal account',
            ];
        }

        $manual = new ManualPaymentService;
        if ($manual->isConfigured()) {
            $gateways['manual'] = [
                'label' => 'Manual Payment',
                'icon' => 'document-text',
                'color' => 'gray',
                'description' => 'Submit payment details for manual processing and approval',
            ];
        }

        return $gateways;
    }
}
