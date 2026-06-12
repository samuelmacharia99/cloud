<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\NotificationService;

class BankTransferPaymentService implements PaymentGatewayInterface
{
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            $amount = $invoice->getAmountRemaining();

            if ($amount <= 0) {
                return [
                    'success' => false,
                    'message' => 'This invoice has no remaining balance.',
                ];
            }

            $payment = Payment::create([
                'user_id' => auth()->id(),
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $customerData['currency'] ?? $invoice->displayCurrency(),
                'payment_method' => 'bank_transfer',
                'transaction_reference' => 'bank-'.uniqid(),
                'status' => 'pending',
                'notes' => json_encode([
                    'payment_reference' => $customerData['payment_reference'] ?? null,
                    'transfer_date' => $customerData['transfer_date'] ?? null,
                    'notes' => $customerData['notes'] ?? null,
                    'submitted_at' => now()->toIso8601String(),
                ]),
            ]);

            app(NotificationService::class)->notifyManualPaymentSubmitted($payment);

            return [
                'success' => true,
                'message' => 'Bank transfer details submitted. We will confirm once funds are received.',
                'payment_id' => $payment->id,
                'redirect_url' => route('customer.payment.bank-transfer-submitted', ['payment' => $payment]),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to submit bank transfer details.',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function handleCallback(array $data): array
    {
        return [
            'success' => false,
            'message' => 'Bank transfers do not use callbacks',
        ];
    }

    public function verify(string $transactionReference): array
    {
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

    public function getMethod(): string
    {
        return 'bank_transfer';
    }

    public function isConfigured(): bool
    {
        return in_array(Setting::getValue('bank_transfer_enabled', 'false'), ['1', 'true', true], true)
            && filled(Setting::getValue('bank_name'))
            && filled(Setting::getValue('bank_account_number'));
    }

    /**
     * @return array{bank_name: ?string, bank_account_name: ?string, bank_account_number: ?string}
     */
    public static function bankDetails(): array
    {
        return [
            'bank_name' => Setting::getValue('bank_name'),
            'bank_account_name' => Setting::getValue('bank_account_name'),
            'bank_account_number' => Setting::getValue('bank_account_number'),
        ];
    }
}
