<?php

namespace App\Services\Billing;

use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\CreditService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Facades\Log;

class InvoiceSettlementService
{
    public function __construct(
        private ProvisioningService $provisioningService,
        private InvoiceProvisioningService $invoiceProvisioning,
        private CustomerHostingUpgradeService $hostingUpgrades,
    ) {}

    /**
     * Apply account credits to an open invoice (oldest-expiring first).
     */
    public function applyAvailableCredits(Invoice $invoice): float
    {
        $invoice->refresh();

        if ($invoice->status->value === 'paid' || $invoice->isFullyPaid()) {
            return 0;
        }

        return CreditService::autoApplyCredits($invoice->fresh(['user']));
    }

    /**
     * Complete an invoice paid entirely via account credits (no gateway payment).
     */
    public function settleFromCredits(Invoice $invoice): bool
    {
        $invoice->refresh();

        if ($invoice->status->value === 'paid') {
            return true;
        }

        $this->applyAvailableCredits($invoice);
        $invoice->refresh();

        if (! $invoice->isFullyPaid()) {
            return false;
        }

        $invoice->update(['status' => 'paid']);

        $this->finalizePaidInvoice($invoice);

        return true;
    }

    /**
     * Finalize invoice after a completed gateway payment.
     */
    public function settleFromPayment(Payment $payment): void
    {
        $payment->loadMissing('invoice', 'user');
        $invoice = $payment->invoice;

        if (! $invoice) {
            return;
        }

        if ($payment->isOverpayment()) {
            $payment->createCreditFromOverpayment();

            Log::info('Overpayment credited to customer account', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'overpayment_amount' => $payment->getOverpaymentAmount(),
            ]);
        }

        $invoice->update(['status' => 'paid']);

        try {
            app(NotificationService::class)->notifyPaymentReceived($payment);
        } catch (\Throwable $e) {
            Log::error('Failed to send payment received notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->finalizePaidInvoice($invoice);
    }

    private function finalizePaidInvoice(Invoice $invoice): void
    {
        $invoice->refresh();

        $this->invoiceProvisioning->provisionPendingServicesForInvoice($invoice);
        $this->unsuspendLinkedServices($invoice);
        $this->advanceRenewalBillingDates($invoice);
        $this->hostingUpgrades->applyPaidUpgradesForInvoice($invoice);
        $this->processResellerDomainOrders($invoice);
        $this->processDomainRenewals($invoice);
    }

    private function unsuspendLinkedServices(Invoice $invoice): void
    {
        try {
            $enforcement = app(ServiceOverdueEnforcementService::class);
            $notificationService = app(NotificationService::class);

            foreach ($enforcement->suspendedServicesForPaidInvoice($invoice) as $service) {
                if (! $enforcement->canAutoUnsuspendForPaidInvoice($service)) {
                    continue;
                }

                try {
                    $enforcement->clearInvoiceSuspensionMeta($service);
                    $this->provisioningService->unsuspend($service->fresh());
                    $notificationService->notifyServiceUnsuspended($service->fresh());
                } catch (\Throwable $e) {
                    Log::error('Failed to unsuspend service after invoice settlement', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to unsuspend services after invoice settlement', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function advanceRenewalBillingDates(Invoice $invoice): void
    {
        try {
            $services = Service::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', ['active', 'suspended'])
                ->get();

            foreach ($services as $service) {
                $newDueDate = match ($service->billing_cycle) {
                    'monthly' => now()->addMonth(),
                    'quarterly' => now()->addMonths(3),
                    'semi-annual' => now()->addMonths(6),
                    'annual' => now()->addYear(),
                    default => now()->addMonth(),
                };

                $service->update(['next_due_date' => $newDueDate]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to advance service billing dates', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processResellerDomainOrders(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        $hasDomainOrder = $invoice->items->contains(function ($item) {
            return $item->product_type === 'Domain'
                && isset($item->custom_options['domain_order_id']);
        });

        if (! $hasDomainOrder) {
            return;
        }

        try {
            app('domain-push-service')->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));
        } catch (\Throwable $e) {
            Log::error('Failed to process reseller domain orders after settlement', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processDomainRenewals(Invoice $invoice): void
    {
        try {
            $renewalOrders = DomainRenewalOrder::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'invoiced')
                ->get();

            $renewalService = app(DomainRenewalService::class);

            foreach ($renewalOrders as $order) {
                $renewalService->pushRenewalToAdmin($order);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to process domain renewals after settlement', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
