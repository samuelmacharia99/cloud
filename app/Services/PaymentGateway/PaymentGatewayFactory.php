<?php

namespace App\Services\PaymentGateway;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
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
            'bank_transfer' => new BankTransferPaymentService,
            default => throw new InvalidArgumentException("Unsupported payment method: {$method}"),
        };
    }

    public static function makeForInvoice(PaymentMethod|string $method, Invoice $invoice): PaymentGatewayInterface
    {
        $invoice->loadMissing('user');
        $settleToReseller = self::settlesToReseller($invoice->user);
        $reseller = $settleToReseller
            ? app(ResellerBrandingResolver::class)->resellerForCustomer($invoice->user)
            : null;

        return self::make($method, $reseller);
    }

    /**
     * Resolve the M-Pesa gateway that initiated a given payment (platform vs reseller).
     */
    public static function makeMpesaForPayment(Payment $payment): MpesaService
    {
        $payment->loadMissing('invoice.user', 'user');

        if ($payment->payment_purpose === 'wallet_topup') {
            return new MpesaService(null);
        }

        if ($payment->invoice) {
            return self::makeForInvoice('mpesa', $payment->invoice);
        }

        $user = $payment->user;
        if (self::settlesToReseller($user)) {
            return new MpesaService(app(ResellerBrandingResolver::class)->resellerForCustomer($user));
        }

        return new MpesaService(null);
    }

    public static function makeMpesaForUser(?User $user): MpesaService
    {
        if (self::settlesToReseller($user)) {
            return new MpesaService(app(ResellerBrandingResolver::class)->resellerForCustomer($user));
        }

        return new MpesaService(null);
    }

    /**
     * Get available payment gateways (platform defaults)
     */
    public static function getAvailableGateways(): array
    {
        return self::buildGatewayList(null, settleToReseller: false);
    }

    /**
     * Online gateways available for wallet top-up and similar KES flows.
     *
     * @return array<string, array{label: string, icon: string, color: string, description: string}>
     */
    public static function getAvailableGatewaysForUser(?User $user = null): array
    {
        $user ??= auth()->user();
        $settleToReseller = self::settlesToReseller($user);
        $reseller = $settleToReseller
            ? app(ResellerBrandingResolver::class)->resellerForCustomer($user)
            : null;
        $gateways = self::buildGatewayList($reseller, settleToReseller: $settleToReseller);

        return array_intersect_key($gateways, array_flip(['mpesa', 'stripe', 'paypal']));
    }

    public static function getAvailableGatewaysForInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('user');
        $settleToReseller = self::settlesToReseller($invoice->user);
        $reseller = $settleToReseller
            ? app(ResellerBrandingResolver::class)->resellerForCustomer($invoice->user)
            : null;

        return self::filterGatewaysForInvoice(
            self::buildGatewayList($reseller, settleToReseller: $settleToReseller),
            $invoice
        );
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
     * Reseller-customer invoices settle to the reseller; resellers/platform customers settle to Talksasa.
     */
    private static function settlesToReseller(?User $user): bool
    {
        return (bool) ($user && ! $user->is_reseller && $user->reseller_id);
    }

    /**
     * @return array<string, array{label: string, icon: string, color: string, description: string}>
     */
    private static function buildGatewayList(?User $reseller, bool $settleToReseller = false): array
    {
        $gateways = [];

        $mpesa = self::resolveMpesaService($reseller, $settleToReseller);
        if ($mpesa->isConfigured()) {
            $gateways['mpesa'] = [
                'label' => 'M-PESA',
                'icon' => 'phone',
                'color' => 'green',
                'description' => 'Pay directly from your M-PESA account',
            ];
        }

        // Stripe/PayPal always settle to the platform — never offer for reseller-customer invoices.
        if (! $settleToReseller) {
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

        $bank = new BankTransferPaymentService;
        if ($bank->isConfigured()) {
            $gateways['bank_transfer'] = [
                'label' => 'Bank Transfer',
                'icon' => 'building-2',
                'color' => 'slate',
                'description' => 'Pay via bank transfer and submit your reference',
            ];
        }

        return $gateways;
    }

    private static function resolveMpesaService(?User $reseller, bool $settleToReseller = false): MpesaService
    {
        if ($settleToReseller && $reseller) {
            return new MpesaService($reseller);
        }

        return new MpesaService(null);
    }

    /**
     * @param  array<string, array<string, string>>  $gateways
     * @return array<string, array<string, string>>
     */
    private static function filterGatewaysForInvoice(array $gateways, Invoice $invoice): array
    {
        $invoiceCurrency = $invoice->displayCurrency();

        if ($invoiceCurrency !== config('currency.base', 'KES')) {
            unset($gateways['mpesa']);
        }

        return $gateways;
    }
}
