<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DomainRenewalService
{
    public function initiateRenewal(Domain $domain, User $customer, int $years = 1): DomainRenewalOrder
    {
        $extension = $domain->domainExtension;
        if (!$extension) {
            throw new \Exception('Domain extension not found');
        }

        $pricing = $extension->getRetailPricing($years);
        if (!$pricing) {
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

            $taxRate = (float) \App\Models\Setting::getValue('tax_rate', 0);
            $taxEnabled = \App\Models\Setting::getValue('tax_enabled', 'false') === 'true';
            $tax = $taxEnabled ? round($renewalOrder->amount * $taxRate / 100, 2) : 0;
            $total = $renewalOrder->amount + $tax;

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
                'subtotal' => $renewalOrder->amount,
                'tax' => $tax,
                'total' => $total,
                'notes' => "Domain renewal for {$domain->name}{$domain->extension} ({$renewalOrder->years} year" . ($renewalOrder->years > 1 ? 's' : '') . ")",
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => "Renew {$domain->name}{$domain->extension} for {$renewalOrder->years} year" . ($renewalOrder->years > 1 ? 's' : ''),
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
        DB::transaction(function () use ($renewalOrder) {
            $domain = $renewalOrder->domain;
            $customer = $renewalOrder->user;

            $taxRate = (float) \App\Models\Setting::getValue('tax_rate', 0);
            $taxEnabled = \App\Models\Setting::getValue('tax_enabled', 'false') === 'true';
            $tax = $taxEnabled ? round($renewalOrder->amount * $taxRate / 100, 2) : 0;
            $total = $renewalOrder->amount + $tax;

            $adminOrder = Order::create([
                'user_id' => $customer->id,
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'status' => 'pending',
                'total' => $total,
                'notes' => "Domain renewal for {$domain->name}{$domain->extension}",
            ]);

            $adminInvoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => 'ADM-INV-' . strtoupper(uniqid()),
                'status' => 'unpaid',
                'subtotal' => $renewalOrder->amount,
                'tax' => $tax,
                'total' => $total,
                'notes' => "Admin renewal order for {$domain->name}{$domain->extension}",
            ]);

            InvoiceItem::create([
                'invoice_id' => $adminInvoice->id,
                'description' => "Renew {$domain->name}{$domain->extension} for {$renewalOrder->years} year" . ($renewalOrder->years > 1 ? 's' : ''),
                'quantity' => 1,
                'unit_price' => $renewalOrder->amount,
                'amount' => $renewalOrder->amount,
            ]);

            $renewalOrder->update([
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminInvoice->id,
                'status' => 'pushed',
                'pushed_at' => now(),
            ]);
        });
    }

    public function completeRenewal(DomainRenewalOrder $renewalOrder, string $adminNotes = ''): void
    {
        DB::transaction(function () use ($renewalOrder, $adminNotes) {
            $domain = $renewalOrder->domain;

            $domain->update([
                'expires_at' => $domain->expires_at?->addYears($renewalOrder->years) ?? now()->addYears($renewalOrder->years),
            ]);

            if ($adminNotes) {
                $notes = $domain->notes ?? [];
                if (!is_array($notes)) {
                    $notes = [];
                }
                $notes[] = [
                    'date' => now()->toDateTimeString(),
                    'message' => "Domain renewed for {$renewalOrder->years} year(s). {$adminNotes}",
                ];
                $domain->update(['notes' => $notes]);
            }

            $renewalOrder->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($renewalOrder->adminInvoice) {
                $renewalOrder->adminInvoice->update(['status' => 'paid']);
            }
        });
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
