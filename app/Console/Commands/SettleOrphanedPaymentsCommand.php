<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\CustomerCreditTopupService;
use App\Services\PaymentGateway\MpesaReconciliationService;
use Illuminate\Support\Facades\Log;

class SettleOrphanedPaymentsCommand extends BaseCronCommand
{
    protected $signature = 'cron:settle-orphaned-payments';

    protected $description = 'Settle completed Stripe/PayPal/M-Pesa payments whose invoices are still unpaid';

    protected function handleCron(): string
    {
        $stats = ['found' => 0, 'settled' => 0, 'errors' => 0];

        // M-Pesa already has dedicated orphan settlement (wallet/reseller paths).
        $mpesa = app(MpesaReconciliationService::class)->settleOrphanedCompletedPayments();
        $stats['found'] += $mpesa['found'];
        $stats['settled'] += $mpesa['settled'];
        $stats['errors'] += $mpesa['errors'];

        Payment::query()
            ->with(['invoice.user'])
            ->whereIn('payment_method', ['stripe', 'paypal'])
            ->where('status', PaymentStatus::Completed->value)
            ->whereNotNull('invoice_id')
            ->where('created_at', '>', now()->subDays(7))
            ->orderBy('id')
            ->chunkById(50, function ($payments) use (&$stats) {
                foreach ($payments as $payment) {
                    $invoice = $payment->invoice;

                    if (! $invoice || $invoice->isPaid()) {
                        continue;
                    }

                    $stats['found']++;

                    try {
                        if ($payment->payment_purpose === 'credit_topup') {
                            app(CustomerCreditTopupService::class)->processTopupPayment($payment);
                        } elseif ($payment->payment_purpose === 'wallet_topup') {
                            app('wallet-service')->processTopupPayment($payment);
                        } else {
                            app(InvoiceSettlementService::class)->settleFromPayment($payment);
                        }

                        $invoice->refresh();
                        if ($invoice->isPaid() || in_array($payment->payment_purpose, ['credit_topup', 'wallet_topup'], true)) {
                            $stats['settled']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('Orphaned payment settlement failed', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $payment->invoice_id,
                            'payment_method' => $payment->payment_method,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return sprintf(
            'Orphaned settlements: %d found, %d settled, %d errors.',
            $stats['found'],
            $stats['settled'],
            $stats['errors'],
        );
    }
}
