<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;

class ManualPaymentService implements PaymentGatewayInterface
{
    /**
     * Initiate a manual payment (creates pending payment record).
     * No external gateway call needed.
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            // Create payment record with pending status
            $payment = Payment::create([
                'user_id'              => auth()->id(),
                'invoice_id'           => $invoice->id,
                'amount'               => $invoice->total,
                'currency'             => $customerData['currency'] ?? 'KES',
                'payment_method'       => 'manual',
                'transaction_reference' => 'manual-' . uniqid(),
                'status'               => 'pending',
                'notes'                => json_encode([
                    'payment_reference' => $customerData['payment_reference'] ?? null,
                    'bank_name'         => $customerData['bank_name'] ?? null,
                    'account_name'      => $customerData['account_name'] ?? null,
                    'notes'             => $customerData['notes'] ?? null,
                    'submitted_at'      => now()->toIso8601String(),
                ]),
            ]);

            // Send notification to admin about pending manual payment
            $adminEmail = \App\Models\Setting::getValue('admin_email', config('mail.from.address'));
            if ($adminEmail) {
                try {
                    \Illuminate\Support\Facades\Mail::send('emails.manual-payment-submitted', [
                        'payment'   => $payment,
                        'invoice'   => $invoice,
                        'customer'  => auth()->user(),
                    ], function ($m) use ($adminEmail, $invoice) {
                        $m->to($adminEmail)
                            ->subject("Manual Payment Submission - Invoice {$invoice->invoice_number}");
                    });
                } catch (\Exception $e) {
                    \Log::warning('Failed to send manual payment notification email', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the payment submission just because email failed
                }
            }

            return [
                'success' => true,
                'message' => 'Payment submission received. An admin will review and approve shortly.',
                'payment_id' => $payment->id,
                'redirect_url' => route('customer.payment.manual-submitted', ['payment' => $payment]),
            ];
        } catch (\Exception $e) {
            \Log::error('Manual payment initiation failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to submit manual payment. Please try again.',
                'error'   => $e->getMessage(),
            ];
        }
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
                'status'  => 'failed',
            ];
        }

        return [
            'success' => true,
            'status'  => $payment->status,
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
     * Check if manual payment is configured (always available).
     */
    public function isConfigured(): bool
    {
        return true;
    }
}
