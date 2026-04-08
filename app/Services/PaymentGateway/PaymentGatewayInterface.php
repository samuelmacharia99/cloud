<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Initiate payment and return redirect URL or initialization data
     */
    public function initiate(Invoice $invoice, array $customerData = []): array;

    /**
     * Verify payment status and return verification result
     */
    public function verify(string $transactionReference): array;

    /**
     * Get payment method name
     */
    public function getMethod(): string;

    /**
     * Check if gateway is properly configured
     */
    public function isConfigured(): bool;
}
