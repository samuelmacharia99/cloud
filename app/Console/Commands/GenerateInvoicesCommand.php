<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Services\ContainerOverageBillingService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-invoices';

    protected $description = 'Generate renewal invoices for services (monthly: 10 days prior, other cycles: 30 days prior)';

    protected function handleCron(): string
    {
        $schedule = app(InvoiceGenerationScheduleService::class);

        $invoiceDueDays = (int) Setting::getValue('invoice_due_days', 14);
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $services = $schedule->servicesDueForRenewalInvoiceQuery()->get();

        $count = 0;
        foreach ($services as $service) {
            if (! $schedule->isServiceDueForRenewalInvoice($service)) {
                continue;
            }

            DB::transaction(function () use ($service, $prefix, $invoiceDueDays, &$count) {
                $year = now()->format('Y');
                $sequence = Invoice::whereYear('created_at', $year)->count() + 1;
                $number = $prefix.'-'.$year.'-'.str_pad($sequence, 5, '0', STR_PAD_LEFT);

                $price = $this->getPriceForCycle($service);
                $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
                $total = $price + $tax;

                $serviceDueDate = $service->next_due_date
                    ? Carbon::parse($service->next_due_date)->toDateString()
                    : now()->addDays($invoiceDueDays)->toDateString();

                $invoice = Invoice::create([
                    'user_id' => $service->user_id,
                    'invoice_number' => $number,
                    'status' => 'unpaid',
                    'due_date' => $serviceDueDate,
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                    'notes' => 'Auto-generated renewal invoice.',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_id' => $service->id,
                    'product_id' => $service->product_id,
                    'description' => $service->product->name.' — '.ucfirst($service->billing_cycle),
                    'quantity' => 1,
                    'unit_price' => $price,
                    'amount' => $price,
                ]);

                if ($service->product->overage_enabled && $service->containerDeployment) {
                    app(ContainerOverageBillingService::class)->addOverageItemsToInvoice(
                        $invoice,
                        $service,
                    );
                }

                // Link invoice only; next_due_date advances when payment is completed.
                $service->update(['invoice_id' => $invoice->id]);

                app(NotificationService::class)->notifyInvoiceGenerated($invoice);

                $count++;
            });
        }

        $monthly = $schedule->monthlyServiceAdvanceDays();
        $other = $schedule->nonMonthlyServiceAdvanceDays();

        return "Generated {$count} invoice(s) for {$services->count()} eligible service(s) "
            ."(monthly: {$monthly} days prior, other cycles: {$other} days prior).";
    }

    private function getPriceForCycle(Service $service): float
    {
        if ($service->custom_price !== null) {
            return (float) $service->custom_price;
        }

        return match ($service->billing_cycle) {
            'monthly' => (float) $service->product->monthly_price,
            'quarterly' => (float) ($service->product->monthly_price * 3),
            'semi-annual' => (float) ($service->product->monthly_price * 6),
            'annual' => (float) $service->product->yearly_price ?: ($service->product->monthly_price * 12),
            default => (float) $service->product->price,
        };
    }
}
