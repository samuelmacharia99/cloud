<?php

namespace App\Services\PaymentGateway;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\CustomerCreditTopupService;
use App\Services\DomainPushService;
use App\Services\DomainRenewalPushService;
use App\Services\NotificationService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Support\Facades\Log;

class MpesaReconciliationService
{
    public function reconcilePendingStkPayments(int $minAgeMinutes = 3, int $maxAgeHours = 24): array
    {
        $stats = ['queried' => 0, 'completed' => 0, 'failed' => 0, 'still_pending' => 0, 'errors' => 0];

        Payment::query()
            ->where('payment_method', 'mpesa')
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<', now()->subMinutes($minAgeMinutes))
            ->where('created_at', '>', now()->subHours($maxAgeHours))
            ->orderBy('id')
            ->chunkById(50, function ($payments) use (&$stats) {
                foreach ($payments as $payment) {
                    $stats['queried']++;

                    try {
                        $outcome = $this->reconcilePendingPayment($payment);
                        $stats[$outcome]++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('M-Pesa reconciliation query failed', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    public function settleOrphanedCompletedPayments(): array
    {
        $stats = ['found' => 0, 'settled' => 0, 'errors' => 0];

        Payment::query()
            ->with(['invoice.user'])
            ->where('payment_method', 'mpesa')
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
                        $this->finalizeCompletedPayment($payment);
                        $invoice->refresh();

                        if ($invoice->isPaid()) {
                            $stats['settled']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('M-Pesa orphaned payment settlement failed', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $payment->invoice_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * @return 'completed'|'failed'|'still_pending'
     */
    public function reconcilePendingPayment(Payment $payment): string
    {
        $payment->refresh();

        if ($payment->status !== PaymentStatus::Pending) {
            return 'still_pending';
        }

        $gateway = PaymentGatewayFactory::makeMpesaForPayment($payment);
        $result = $gateway->verify((string) $payment->transaction_reference);

        if (($result['success'] ?? false) && ($result['status'] ?? null) === 'completed') {
            $payment->refresh();

            if ($payment->status !== PaymentStatus::Completed) {
                $payment->update([
                    'status' => PaymentStatus::Completed->value,
                    'paid_at' => now(),
                    'notes' => json_encode(array_merge(
                        json_decode((string) $payment->notes, true) ?: [],
                        ['reconciled_via' => 'stk_query', 'reconciled_at' => now()->toIso8601String()],
                    )),
                ]);
            }

            $this->finalizeCompletedPayment($payment->fresh(['invoice.user']));

            return 'completed';
        }

        if (($result['status'] ?? null) === 'failed') {
            if ($payment->status !== PaymentStatus::Failed) {
                $payment->update([
                    'status' => PaymentStatus::Failed->value,
                    'notes' => json_encode([
                        'result_desc' => $result['message'] ?? 'Payment failed',
                        'result_code' => $result['response_code'] ?? null,
                        'reconciled_via' => 'stk_query',
                    ]),
                ]);
            }

            return 'failed';
        }

        return 'still_pending';
    }

    public function finalizeCompletedPayment(Payment $payment): void
    {
        $payment->loadMissing('invoice.user');

        if ($payment->payment_purpose === 'wallet_topup') {
            app('wallet-service')->processTopupPayment($payment);

            return;
        }

        if ($payment->payment_purpose === 'credit_topup') {
            app(CustomerCreditTopupService::class)->processTopupPayment($payment);

            return;
        }

        $invoice = $payment->invoice;

        if (! $invoice) {
            return;
        }

        $user = $invoice->user;

        if ($user?->is_reseller) {
            $invoicePaymentService = app(ResellerInvoicePaymentService::class);
            $invoice = $invoice->fresh();

            if (! $invoicePaymentService->completeInvoiceIfFullyPaid($invoice, $payment)) {
                return;
            }

            $invoice = $invoice->fresh(['items', 'user']);

            if ($invoice->type === 'reseller_subscription' && $invoice->isPaid()) {
                app(ResellerPackageSubscriptionService::class)->activateFromPaidInvoice($invoice);
            }

            try {
                app(NotificationService::class)->notifyPaymentReceived($payment);
            } catch (\Throwable $e) {
                Log::error('M-Pesa reconciliation: reseller payment notification failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(DomainPushService::class)->handlePaidResellerInvoice($invoice);
                app(DomainPushService::class)->ensurePaidInvoiceDomainOrdersPushed($invoice->fresh(['items']));
            } catch (\Throwable $e) {
                Log::error('M-Pesa reconciliation: reseller domain push failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->processResellerDomainRenewals($invoice);

            try {
                app(InvoiceProvisioningService::class)->provisionPendingServicesForInvoice($invoice);
            } catch (\Throwable $e) {
                Log::error('M-Pesa reconciliation: reseller provisioning failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        app(InvoiceSettlementService::class)->settleFromPayment($payment);
    }

    private function processResellerDomainRenewals(Invoice $invoice): void
    {
        try {
            app(DomainRenewalPushService::class)->handlePaidInvoice($invoice->fresh(['items', 'user']));
        } catch (\Throwable $e) {
            Log::error('M-Pesa reconciliation: domain renewal push failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
