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
            $userId = auth()->id();
            $user = auth()->user();

            \Log::info('Manual payment initiation started', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'user_id' => $userId,
                'user_name' => $user->name,
                'amount' => $invoice->total,
                'currency' => $customerData['currency'] ?? 'KES',
            ]);

            // Create payment record with pending status
            $payment = Payment::create([
                'user_id'              => $userId,
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

            \Log::info('Manual payment record created', [
                'payment_id' => $payment->id,
                'transaction_reference' => $payment->transaction_reference,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'amount' => $payment->amount,
                'status' => $payment->status,
            ]);

            // Send notification to admin about pending manual payment
            $adminEmail = \App\Models\Setting::getValue('admin_email', config('mail.from.address'));
            if ($adminEmail) {
                try {
                    \Log::debug('Sending manual payment notification email', [
                        'payment_id' => $payment->id,
                        'admin_email' => $adminEmail,
                    ]);

                    \Illuminate\Support\Facades\Mail::send('emails.manual-payment-submitted', [
                        'payment'   => $payment,
                        'invoice'   => $invoice,
                        'customer'  => $user,
                    ], function ($m) use ($adminEmail, $invoice) {
                        $m->to($adminEmail)
                            ->subject("Manual Payment Submission - Invoice {$invoice->invoice_number}");
                    });

                    \Log::info('Manual payment notification email sent successfully', [
                        'payment_id' => $payment->id,
                        'admin_email' => $adminEmail,
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Failed to send manual payment notification email', [
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'admin_email' => $adminEmail,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the payment submission just because email failed
                }
            } else {
                \Log::warning('No admin email configured for manual payment notification', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                ]);
            }

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
                'error'   => $e->getMessage(),
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
