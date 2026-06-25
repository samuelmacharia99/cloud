<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Services\CreditService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Collection;
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
            $this->syncOrderPaymentStatus($invoice->fresh(['order']));

            return true;
        }

        $this->applyAvailableCredits($invoice);
        $invoice->refresh();

        if (! $invoice->isFullyPaid()) {
            return false;
        }

        $wasAlreadyPaid = $invoice->isPaid();
        $this->markInvoiceAsPaid($invoice);

        if (! $wasAlreadyPaid) {
            try {
                app(NotificationService::class)->notifyPaymentReceived($invoice->fresh());
            } catch (\Throwable $e) {
                Log::error('Failed to send payment received notification after credit settlement', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->finalizePaidInvoice($invoice->fresh());
        }

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

        $invoice->refresh();

        if (! $invoice->isFullyPaid()) {
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

        $wasAlreadyPaid = $invoice->isPaid();
        $this->markInvoiceAsPaid($invoice);

        if (! $wasAlreadyPaid) {
            try {
                app(NotificationService::class)->notifyPaymentReceived($payment);
            } catch (\Throwable $e) {
                Log::error('Failed to send payment received notification', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->finalizePaidInvoice($invoice->fresh());
        }
    }

    /**
     * Mark an invoice (and linked checkout order) as paid.
     */
    public function markInvoiceAsPaid(Invoice $invoice): void
    {
        $invoice->refresh();

        $updates = [];

        if (! $invoice->isPaid()) {
            $updates['status'] = InvoiceStatus::Paid;
        }

        if (! $invoice->paid_date) {
            $updates['paid_date'] = now();
        }

        if ($updates !== []) {
            $invoice->update($updates);
        }

        $this->syncOrderPaymentStatus($invoice->fresh(['order']));
    }

    private function syncOrderPaymentStatus(Invoice $invoice): void
    {
        $order = $invoice->order;

        if (! $order instanceof Order) {
            return;
        }

        if ($order->payment_status === 'paid' && $order->status === 'paid') {
            return;
        }

        $order->update([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);
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
        if (! $this->shouldAdvanceRenewalBillingDates($invoice)) {
            return;
        }

        try {
            foreach ($this->renewalServicesForInvoice($invoice) as $service) {
                $newDueDate = $service->calculateNextDueDateAfterRenewal($invoice->paid_date);

                $service->update(['next_due_date' => $newDueDate]);

                Log::info('Service billing period advanced after invoice payment', [
                    'service_id' => $service->id,
                    'invoice_id' => $invoice->id,
                    'billing_cycle' => $service->billing_cycle,
                    'next_due_date' => $newDueDate->toDateString(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to advance service billing dates', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldAdvanceRenewalBillingDates(Invoice $invoice): bool
    {
        if ($invoice->type === 'reseller_subscription') {
            return false;
        }

        $invoice->loadMissing('order', 'items');

        if ($invoice->order instanceof Order) {
            return false;
        }

        return ! $invoice->items->contains(function ($item) {
            $options = is_array($item->custom_options) ? $item->custom_options : [];

            return ! empty($options['hosting_upgrade']) || ! empty($options['hosting_plan_change']);
        });
    }

    /**
     * @return Collection<int, Service>
     */
    private function renewalServicesForInvoice(Invoice $invoice): Collection
    {
        $invoice->loadMissing('items');

        $serviceIds = $invoice->items
            ->whereNotNull('service_id')
            ->pluck('service_id');

        return Service::query()
            ->whereIn('status', ['active', 'suspended'])
            ->where(function ($query) use ($invoice, $serviceIds) {
                $query->where('invoice_id', $invoice->id);

                if ($serviceIds->isNotEmpty()) {
                    $query->orWhereIn('id', $serviceIds);
                }
            })
            ->get()
            ->unique('id')
            ->values();
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
