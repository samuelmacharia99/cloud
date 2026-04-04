<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class GenerateInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-invoices';
    protected $description = 'Generate renewal invoices for services due today or past due';

    protected function handleCron(): string
    {
        $invoiceDueDays = (int) Setting::getValue('invoice_due_days', 14);
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $taxEnabled = Setting::getValue('tax_enabled', 'false') === 'true';

        $services = Service::with(['product', 'user'])
            ->where('status', 'active')
            ->where('next_due_date', '<=', now())
            ->whereDoesntHave('invoice', function ($q) {
                $q->whereIn('status', ['draft', 'unpaid'])
                  ->where('created_at', '>=', now()->subDays(7));
            })
            ->get();

        $count = 0;
        foreach ($services as $service) {
            DB::transaction(function () use ($service, $prefix, $invoiceDueDays, $taxRate, $taxEnabled, &$count) {
                $year = now()->format('Y');
                $sequence = Invoice::whereYear('created_at', $year)->count() + 1;
                $number = $prefix . '-' . $year . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

                $price = $this->getPriceForCycle($service);
                $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
                $total = $price + $tax;
                $dueDate = now()->addDays($invoiceDueDays)->toDateString();

                $invoice = Invoice::create([
                    'user_id' => $service->user_id,
                    'invoice_number' => $number,
                    'status' => 'unpaid',
                    'due_date' => $dueDate,
                    'subtotal' => $price,
                    'tax' => $tax,
                    'total' => $total,
                    'notes' => 'Auto-generated renewal invoice.',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_id' => $service->id,
                    'product_id' => $service->product_id,
                    'description' => $service->product->name . ' — ' . ucfirst($service->billing_cycle),
                    'quantity' => 1,
                    'unit_price' => $price,
                    'amount' => $price,
                ]);

                $service->update([
                    'invoice_id' => $invoice->id,
                    'next_due_date' => $this->advanceDueDate($service),
                ]);

                // Send invoice generated notification
                app(NotificationService::class)->notifyInvoiceGenerated($invoice);

                $count++;
            });
        }

        return "Generated {$count} invoice(s) for {$services->count()} eligible service(s).";
    }

    private function getPriceForCycle(Service $service): float
    {
        return match($service->billing_cycle) {
            'monthly' => (float) $service->product->monthly_price,
            'quarterly' => (float) ($service->product->monthly_price * 3),
            'semi-annual' => (float) ($service->product->monthly_price * 6),
            'annual' => (float) $service->product->yearly_price ?: ($service->product->monthly_price * 12),
            default => (float) $service->product->price,
        };
    }

    private function advanceDueDate(Service $service)
    {
        return match($service->billing_cycle) {
            'monthly' => now()->parse($service->next_due_date)->addMonth(),
            'quarterly' => now()->parse($service->next_due_date)->addMonths(3),
            'semi-annual' => now()->parse($service->next_due_date)->addMonths(6),
            'annual' => now()->parse($service->next_due_date)->addYear(),
            default => now()->parse($service->next_due_date)->addMonth(),
        };
    }
}
