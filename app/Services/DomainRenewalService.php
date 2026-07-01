<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DomainRenewalService
{
    public function wholesaleRenewalAmount(Domain $domain, int $years): float
    {
        $extension = $domain->domainExtension;

        if (! $extension) {
            throw new \Exception('Domain extension not configured.');
        }

        $pricing = $extension->getWholesalePricing($years);

        if (! $pricing) {
            throw new \Exception("No wholesale renewal pricing for {$domain->extension} ({$years} year(s)).");
        }

        return (float) ($pricing->renewal_price ?? $pricing->price);
    }

    public function initiateResellerRenewal(Domain $domain, User $reseller, int $years = 1): DomainRenewalOrder
    {
        $amount = $this->wholesaleRenewalAmount($domain, $years);

        return DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $reseller->id,
            'years' => $years,
            'amount' => $amount,
            'status' => 'pending',
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function linkRenewalToInvoice(DomainRenewalOrder $renewalOrder, Invoice $invoice): void
    {
        $renewalOrder->update([
            'invoice_id' => $invoice->id,
            'status' => 'invoiced',
            'invoiced_at' => now(),
        ]);
    }

    public function initiateRenewal(Domain $domain, User $customer, int $years = 1): DomainRenewalOrder
    {
        $extension = $domain->domainExtension;
        if (! $extension) {
            throw new \Exception('Domain extension not found');
        }

        $pricing = $extension->getPricingForUser($customer, $years);
        if (! $pricing) {
            throw new \Exception('No pricing available for this domain extension');
        }

        $renewalOrder = DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $customer->id,
            'years' => $years,
            'amount' => $pricing->renewal_price ?? $pricing->price,
            'status' => 'pending',
            'expires_at' => now()->addDays(10),
        ]);

        return $renewalOrder;
    }

    public function createInvoice(DomainRenewalOrder $renewalOrder): Invoice
    {
        return DB::transaction(function () use ($renewalOrder) {
            $domain = $renewalOrder->domain;
            $customer = $renewalOrder->user;

            $tax = TaxService::calculateForUser((float) $renewalOrder->amount, $customer);

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => 'INV-'.strtoupper(uniqid()),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
                'subtotal' => $tax['subtotal'],
                'tax' => $tax['tax'],
                'total' => $tax['total'],
                'notes' => "Domain renewal for {$domain->name}{$domain->extension} ({$renewalOrder->years} year".($renewalOrder->years > 1 ? 's' : '').')',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'domain_id' => $domain->id,
                'description' => "Renew {$domain->name}{$domain->extension} for {$renewalOrder->years} year".($renewalOrder->years > 1 ? 's' : ''),
                'quantity' => 1,
                'unit_price' => $renewalOrder->amount,
                'amount' => $renewalOrder->amount,
            ]);

            $renewalOrder->update([
                'invoice_id' => $invoice->id,
                'status' => 'invoiced',
                'invoiced_at' => now(),
            ]);

            return $invoice;
        });
    }

    public function pushRenewalToAdmin(DomainRenewalOrder $renewalOrder): void
    {
        if (in_array($renewalOrder->status, ['pushed', 'completed'], true)) {
            return;
        }

        $adminOrder = null;
        $adminInvoice = null;
        $shouldNotify = false;

        DB::transaction(function () use ($renewalOrder, &$adminOrder, &$adminInvoice, &$shouldNotify) {
            $locked = DomainRenewalOrder::query()
                ->whereKey($renewalOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || in_array($locked->status, ['pushed', 'completed'], true)) {
                return;
            }

            if ($locked->admin_order_id) {
                $locked->update([
                    'status' => 'pushed',
                    'pushed_at' => $locked->pushed_at ?? now(),
                ]);

                return;
            }

            $locked->loadMissing('invoice');
            $domain = $locked->domain;
            $customer = $locked->user;
            $customerInvoicePaid = $locked->invoice?->isPaid() ?? false;

            $tax = TaxService::calculateForUser((float) $locked->amount, $customer);

            $adminOrder = Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ORD-'.strtoupper(uniqid()),
                'status' => 'pending',
                'payment_status' => $customerInvoicePaid ? 'paid' : 'unpaid',
                'subtotal' => $tax['subtotal'],
                'tax' => $tax['tax'],
                'total' => $tax['total'],
                'notes' => "Domain renewal for {$domain->name}{$domain->extension}",
            ]);

            $adminInvoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => 'ADM-INV-'.strtoupper(uniqid()),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
                'subtotal' => $tax['subtotal'],
                'tax' => $tax['tax'],
                'total' => $tax['total'],
                'notes' => "Admin renewal order for {$domain->name}{$domain->extension}",
            ]);

            InvoiceItem::create([
                'invoice_id' => $adminInvoice->id,
                'domain_id' => $domain->id,
                'description' => "Renew {$domain->name}{$domain->extension} for {$locked->years} year".($locked->years > 1 ? 's' : ''),
                'quantity' => 1,
                'unit_price' => $locked->amount,
                'amount' => $locked->amount,
            ]);

            $adminOrder->update(['invoice_id' => $adminInvoice->id]);

            $locked->update([
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminInvoice->id,
                'status' => 'pushed',
                'pushed_at' => now(),
                'paid_at' => $customerInvoicePaid ? ($locked->invoice?->paid_date ?? now()) : null,
            ]);

            $shouldNotify = true;
        });

        try {
            app(RegistrarFulfillmentService::class)
                ->fulfillRenewal($renewalOrder->fresh(['domain.domainExtension']));
        } catch (\Throwable $e) {
            report($e);
        }

        if ($shouldNotify && $adminOrder && $adminInvoice) {
            try {
                app(NotificationService::class)->notifyAdminDomainRenewalPushed($renewalOrder->fresh(), $adminOrder, $adminInvoice);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function completeRenewalManually(
        DomainRenewalOrder $renewalOrder,
        int $years,
        string $adminNotes = '',
        bool $sendNotification = true,
    ): Domain {
        if (! in_array($renewalOrder->status, ['pushed', 'failed'], true)) {
            throw new \InvalidArgumentException('Only pushed or failed renewals can be completed manually.');
        }

        $domain = $this->completeRenewal($renewalOrder, $adminNotes, $years);

        if ($sendNotification) {
            app(NotificationService::class)->notifyDomainRenewalCompleted(
                $renewalOrder->fresh(['domain', 'user']),
                $domain->fresh(),
                $years,
            );
        }

        return $domain->fresh();
    }

    public function completeRenewal(
        DomainRenewalOrder $renewalOrder,
        string $adminNotes = '',
        ?int $years = null,
    ): Domain {
        $years = max(1, $years ?? (int) $renewalOrder->years);

        return DB::transaction(function () use ($renewalOrder, $adminNotes, $years) {
            $renewalOrder->loadMissing('domain', 'adminOrder', 'adminInvoice');
            $domain = $renewalOrder->domain;

            if (! $domain) {
                throw new \RuntimeException('Domain not found for this renewal order.');
            }

            $domain = $this->applyRenewalExpiry($domain, $years);

            if ($adminNotes !== '') {
                $notes = $domain->notes ?? [];
                if (! is_array($notes)) {
                    $notes = [];
                }
                $notes[] = [
                    'date' => now()->toDateTimeString(),
                    'message' => "Domain renewed for {$years} year(s). {$adminNotes}",
                ];
                $domain->update(['notes' => $notes]);
            }

            $renewalOrder->update([
                'years' => $years,
                'status' => 'completed',
                'completed_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            if ($renewalOrder->adminInvoice) {
                $renewalOrder->adminInvoice->update(['status' => 'paid']);
            }

            if ($renewalOrder->adminOrder && ! $renewalOrder->adminOrder->isPaid()) {
                $renewalOrder->adminOrder->update([
                    'status' => 'paid',
                    'payment_status' => 'paid',
                ]);
            }

            return $domain->fresh();
        });
    }

    public function applyRenewalExpiry(Domain $domain, int $years): Domain
    {
        $base = ($domain->expires_at && $domain->expires_at->isFuture())
            ? $domain->expires_at->copy()
            : now();

        $newExpiry = $base->addYears($years);
        $schedule = app(InvoiceGenerationScheduleService::class);
        $domain->expires_at = $newExpiry;

        $updates = [
            'expires_at' => $newExpiry,
            'next_invoice_date' => $schedule->domainNextInvoiceDate($domain),
        ];

        if ($domain->status === 'expired') {
            $updates['status'] = 'active';
        }

        $domain->update($updates);

        return $domain->fresh();
    }

    /**
     * Reseller-managed renewals notify the reseller only — never the reseller's end customer.
     */
    public function renewalNotificationRecipient(DomainRenewalOrder $renewalOrder): ?User
    {
        $renewalOrder->loadMissing('domain.user', 'user');
        $domain = $renewalOrder->domain;
        $user = $renewalOrder->user;

        if ($domain?->reseller_id) {
            $reseller = User::query()->find($domain->reseller_id);
            if ($reseller?->is_reseller) {
                return $reseller;
            }
        }

        if ($user?->is_reseller) {
            return $user;
        }

        if ($user?->reseller_id) {
            return User::query()->find($user->reseller_id);
        }

        if ($user && $user->isCustomer()) {
            return $user;
        }

        return null;
    }

    public function projectedExpiryAfterRenewal(Domain $domain, int $years): Carbon
    {
        $base = ($domain->expires_at && $domain->expires_at->isFuture())
            ? $domain->expires_at->copy()
            : now();

        return $base->addYears($years);
    }

    public function failRenewal(DomainRenewalOrder $renewalOrder, string $reason): void
    {
        DB::transaction(function () use ($renewalOrder, $reason) {
            $renewalOrder->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);
        });
    }

    public function expireRenewal(DomainRenewalOrder $renewalOrder): void
    {
        DB::transaction(function () use ($renewalOrder) {
            $renewalOrder->update([
                'status' => 'expired',
            ]);

            if ($renewalOrder->invoice) {
                $renewalOrder->invoice->update(['status' => 'cancelled']);
            }
        });
    }
}
