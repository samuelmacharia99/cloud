<?php

namespace App\Services\PaymentGateway;

use App\Enums\PaymentMethod;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * Create payment gateway instance based on method
     */
    public static function make(PaymentMethod|string $method): PaymentGatewayInterface
    {
        $method = $method instanceof PaymentMethod ? $method->value : $method;

        return match ($method) {
            'mpesa' => new MpesaService(),
            'stripe' => new StripeService(),
            'paypal' => new PayPalService(),
            default => throw new InvalidArgumentException("Unsupported payment method: {$method}"),
        };
    }

    /**
     * Get available payment gateways
     */
    public static function getAvailableGateways(): array
    {
        $gateways = [];

        $mpesa = new MpesaService();
        if ($mpesa->isConfigured()) {
            $gateways['mpesa'] = [
                'label' => 'M-PESA',
                'icon' => 'phone',
                'color' => 'green',
                'description' => 'Pay directly from your M-PESA account',
            ];
        }

        $stripe = new StripeService();
        if ($stripe->isConfigured()) {
            $gateways['stripe'] = [
                'label' => 'Stripe',
                'icon' => 'credit-card',
                'color' => 'purple',
                'description' => 'Pay with your credit or debit card',
            ];
        }

        $paypal = new PayPalService();
        if ($paypal->isConfigured()) {
            $gateways['paypal'] = [
                'label' => 'PayPal',
                'icon' => 'globe',
                'color' => 'blue',
                'description' => 'Pay safely with your PayPal account',
            ];
        }

        return $gateways;
    }

    /**
     * Check if specific gateway is available
     */
    public static function isAvailable(string $method): bool
    {
        $gateways = self::getAvailableGateways();
        return isset($gateways[$method]);
    }
}
