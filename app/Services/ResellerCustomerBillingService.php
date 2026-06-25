<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Provisioning\InvoiceProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResellerCustomerBillingService
{
    public function __construct(
        private ResellerScopeService $scope,
        private InvoiceProvisioningService $provisioning,
        private NotificationService $notifications,
        private ResellerMarginService $margins,
    ) {}

    public function ensureManagedCustomer(User $reseller, User $customer): void
    {
        if (! $this->scope->ownsCustomer($reseller, $customer)) {
            abort(404);
        }
    }

    public function ensureManagedInvoice(User $reseller, Invoice $invoice): void
    {
        $customer = $invoice->user;
        if (! $customer instanceof User) {
            abort(404);
        }

        $this->ensureManagedCustomer($reseller, $customer);
    }

    /**
     * @param  array{amount: float, payment_method: string, transaction_reference?: ?string, paid_at?: ?string, notes?: ?string}  $data
     */
    public function recordPayment(User $reseller, Invoice $invoice, array $data): Payment
    {
        $this->ensureManagedInvoice($reseller, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            throw new \InvalidArgumentException('This invoice cannot accept payments.');
        }

        return DB::transaction(function () use ($reseller, $invoice, $data) {
            $payment = Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'currency' => 'KES',
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'status' => PaymentStatus::Completed->value,
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->applyPaymentSideEffects($reseller, $invoice, $payment);

            return $payment;
        });
    }

    public function markAsPaid(User $reseller, Invoice $invoice): Payment
    {
        $remaining = $invoice->getAmountRemaining();

        if ($remaining <= 0) {
            throw new \InvalidArgumentException('This invoice has no remaining balance.');
        }

        return $this->recordPayment($reseller, $invoice, [
            'amount' => $remaining,
            'payment_method' => 'manual',
            'transaction_reference' => $this->resellerMarkPaidReference($reseller, $invoice),
            'notes' => 'Marked as paid from reseller portal',
        ]);
    }

    private function resellerMarkPaidReference(User $reseller, Invoice $invoice): string
    {
        return sprintf(
            'RSL-%d-INV-%d-%s',
            $reseller->id,
            $invoice->id,
            Str::upper(Str::random(8))
        );
    }

    public function cancelInvoice(User $reseller, Invoice $invoice): Invoice
    {
        $this->ensureManagedInvoice($reseller, $invoice);

        if ($invoice->status === InvoiceStatus::Paid) {
            throw new \InvalidArgumentException('Paid invoices cannot be cancelled.');
        }

        $invoice->update(['status' => InvoiceStatus::Cancelled->value]);

        return $invoice->fresh();
    }

    public function resendInvoice(User $reseller, Invoice $invoice): void
    {
        $this->ensureManagedInvoice($reseller, $invoice);

        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new \InvalidArgumentException('Cancelled invoices cannot be sent.');
        }

        $this->notifications->notifyInvoiceGenerated($invoice->fresh(['user', 'items']));
    }

    /**
     * @param  array{status: string, due_date?: ?string, notes?: ?string, tax_rate?: float, items: array<int, array<string, mixed>>}  $data
     */
    public function createCustomerInvoice(User $reseller, User $customer, array $data): Invoice
    {
        $this->ensureManagedCustomer($reseller, $customer);

        return DB::transaction(function () use ($customer, $data) {
            return $this->persistInvoice($customer, $data, notify: $data['status'] !== InvoiceStatus::Draft->value);
        });
    }

    /**
     * @param  array{status?: string, due_date?: ?string, notes?: ?string, tax_rate?: float, items: array<int, array<string, mixed>>}  $data
     */
    public function updateCustomerInvoice(User $reseller, Invoice $invoice, array $data): Invoice
    {
        $this->ensureManagedInvoice($reseller, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            throw new \InvalidArgumentException('This invoice cannot be edited.');
        }

        if ($invoice->payments()->where('status', PaymentStatus::Completed->value)->exists()) {
            throw new \InvalidArgumentException('Invoices with payments cannot be edited.');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $invoice->items()->delete();

            return $this->persistInvoice($invoice->user, array_merge($data, [
                'status' => $data['status'] ?? $invoice->status->value ?? $invoice->status,
            ]), existing: $invoice, notify: false);
        });
    }

    public function customerOutstandingTotal(User $reseller): float
    {
        $total = 0.0;

        $this->scope->managedInvoicesQuery($reseller)
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->with('payments')
            ->chunkById(100, function ($invoices) use (&$total) {
                foreach ($invoices as $invoice) {
                    $total += $invoice->getAmountRemaining();
                }
            });

        return round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistInvoice(User $customer, array $data, ?Invoice $existing = null, bool $notify = true): Invoice
    {
        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            $subtotal += (float) $item['quantity'] * (float) $item['unit_price'];
        }

        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = round($subtotal + $taxAmount, 2);

        if ($existing) {
            $existing->update([
                'status' => $data['status'],
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'total' => $total,
                'due_date' => $data['due_date'] ?? $existing->due_date,
                'notes' => $data['notes'] ?? $existing->notes,
            ]);
            $invoice = $existing;
        } else {
            $prefix = Setting::getValue('invoice_prefix', 'INV');
            $year = now()->format('Y');
            $count = Invoice::whereYear('created_at', $year)->count() + 1;
            $invoiceNumber = "{$prefix}-{$year}-".str_pad((string) $count, 5, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => $invoiceNumber,
                'status' => $data['status'],
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'total' => $total,
                'due_date' => $data['due_date'] ?? now()->addDays(7),
                'notes' => $data['notes'] ?? null,
            ]);
        }

        foreach ($data['items'] as $item) {
            $amount = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $item['product_id'] ?? null,
                'service_id' => $item['service_id'] ?? null,
                'domain_id' => $item['domain_id'] ?? null,
                'product_type' => $item['product_type'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => $amount,
                'custom_options' => $item['custom_options'] ?? null,
            ]);
        }

        if ($notify && ($invoice->status !== InvoiceStatus::Draft)) {
            try {
                $this->notifications->notifyInvoiceGenerated($invoice->fresh(['user', 'items']));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $invoice->fresh(['items']);
    }

    private function applyPaymentSideEffects(User $reseller, Invoice $invoice, Payment $payment): void
    {
        $invoice->refresh();

        if ($invoice->getAmountRemaining() > 0) {
            return;
        }

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        try {
            $this->margins->recordFromPayment($reseller, $payment);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
