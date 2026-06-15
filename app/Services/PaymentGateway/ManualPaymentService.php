<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\NotificationService;

class ManualPaymentService implements PaymentGatewayInterface
{
    /**
     * Initiate a manual payment (creates pending payment record).
     * No external gateway call needed.
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            $userId = auth()->id();
            $user = auth()->user();

            \Log::info('Manual payment initiation started', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'user_id' => $userId,
                'user_name' => $user->name,
                'amount' => $invoice->getAmountRemaining(),
                'currency' => $customerData['currency'] ?? $invoice->displayCurrency(),
            ]);

            // Create payment record with pending status
            $payment = Payment::create([
                'user_id' => $userId,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->getAmountRemaining(),
                'currency' => $customerData['currency'] ?? $invoice->displayCurrency(),
                'payment_method' => 'manual',
                'transaction_reference' => 'manual-'.uniqid(),
                'status' => 'pending',
                'notes' => json_encode([
                    'payment_reference' => $customerData['payment_reference'] ?? null,
                    'bank_name' => $customerData['bank_name'] ?? null,
                    'account_name' => $customerData['account_name'] ?? null,
                    'notes' => $customerData['notes'] ?? null,
                    'submitted_at' => now()->toIso8601String(),
                ]),
            ]);

            \Log::info('Manual payment record created', [
                'payment_id' => $payment->id,
                'transaction_reference' => $payment->transaction_reference,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'amount' => $payment->amount,
                'status' => $payment->status,
            ]);

            // Send notification to admin about pending manual payment
            app(NotificationService::class)->notifyManualPaymentSubmitted($payment);

            \Log::info('Manual payment initiation completed successfully', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
            ]);

            return [
                'success' => true,
                'message' => 'Payment submission received. An admin will review and approve shortly.',
                'payment_id' => $payment->id,
                'redirect_url' => route('customer.payment.manual-submitted', ['payment' => $payment]),
            ];
        } catch (\Exception $e) {
            \Log::error('Manual payment initiation failed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to submit manual payment. Please try again.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback (not applicable for manual payments).
     */
    public function handleCallback(array $data): array
    {
        return [
            'success' => false,
            'message' => 'Manual payments do not use callbacks',
        ];
    }

    /**
     * Verify a manual payment (not applicable - admin approves instead).
     */
    public function verify(string $transactionReference): array
    {
        // Manual payments are verified by admin manually
        $payment = Payment::where('transaction_reference', $transactionReference)->first();

        if (! $payment) {
            return [
                'success' => false,
                'message' => 'Payment record not found.',
                'status' => 'failed',
            ];
        }

        return [
            'success' => true,
            'status' => $payment->status,
            'payment' => $payment,
        ];
    }

    /**
     * Get the payment method identifier.
     */
    public function getMethod(): string
    {
        return 'manual';
    }

    /**
     * Check if manual payment is enabled in admin settings.
     */
    public function isConfigured(): bool
    {
        return in_array(Setting::getValue('manual_enabled', '0'), ['1', 'true', true], true);
    }
}
