<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\ContainerMetric;
use App\Models\Service;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\NotificationService;
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
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $taxEnabled = Setting::getValue('tax_enabled', 'false') === 'true';

        $services = $schedule->servicesDueForRenewalInvoiceQuery()->get();

        $count = 0;
        foreach ($services as $service) {
            if (! $schedule->isServiceDueForRenewalInvoice($service)) {
                continue;
            }

            DB::transaction(function () use ($service, $prefix, $invoiceDueDays, $taxRate, $taxEnabled, &$count) {
                $year = now()->format('Y');
                $sequence = Invoice::whereYear('created_at', $year)->count() + 1;
                $number = $prefix.'-'.$year.'-'.str_pad($sequence, 5, '0', STR_PAD_LEFT);

                $price = $this->getPriceForCycle($service);
                $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
                $total = $price + $tax;

                $serviceDueDate = $service->next_due_date
                    ? \Carbon\Carbon::parse($service->next_due_date)->toDateString()
                    : now()->addDays($invoiceDueDays)->toDateString();

                $invoice = Invoice::create([
                    'user_id' => $service->user_id,
                    'invoice_number' => $number,
                    'status' => 'unpaid',
                    'due_date' => $serviceDueDate,
                    'subtotal' => $price,
                    'tax' => $tax,
                    'total' => $total,
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
                    $this->addOverageItems($invoice, $service, $taxEnabled, $taxRate);
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

    /**
     * Add overage invoice items for container deployments
     */
    private function addOverageItems(Invoice $invoice, Service $service, bool $taxEnabled, float $taxRate): void
    {
        $deployment = $service->containerDeployment;
        $template = $service->product->containerTemplate;
        $product = $service->product;

        if (! $deployment || ! $template) {
            return;
        }

        $from = $service->last_invoice_date ?? $service->created_at;
        $to = now();
        $billingHours = (float) $from->diffInHours($to);

        if ($billingHours <= 0) {
            return;
        }

        $avgCpuPercent = ContainerMetric::averageCpuPercent($deployment, $from, $to);
        $avgMemoryMb = ContainerMetric::averageMemoryMb($deployment, $from, $to);

        $avgCpuCores = $avgCpuPercent / 100;
        $avgMemoryGb = $avgMemoryMb / 1024;

        $includedCores = $template->required_cpu_cores;
        $includedGb = $template->required_ram_mb / 1024;

        $cpuOverageHours = max(0, $avgCpuCores - $includedCores) * $billingHours;
        $memoryOverageGbHours = max(0, $avgMemoryGb - $includedGb) * $billingHours;

        $cpuOverageAmount = $cpuOverageHours * $product->cpu_overage_rate;
        $memoryOverageAmount = $memoryOverageGbHours * $product->ram_overage_rate;

        if ($cpuOverageAmount > 0) {
            $cpuTax = $taxEnabled ? round($cpuOverageAmount * $taxRate / 100, 2) : 0;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => "CPU Overage — {$cpuOverageHours} core-hours @ KES {$product->cpu_overage_rate}/hour",
                'quantity' => $cpuOverageHours,
                'unit_price' => $product->cpu_overage_rate,
                'amount' => $cpuOverageAmount,
            ]);

            $invoice->increment('subtotal', $cpuOverageAmount);
            $invoice->increment('tax', $cpuTax);
            $invoice->increment('total', $cpuOverageAmount + $cpuTax);
        }

        if ($memoryOverageAmount > 0) {
            $memTax = $taxEnabled ? round($memoryOverageAmount * $taxRate / 100, 2) : 0;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => "RAM Overage — {$memoryOverageGbHours} GB-hours @ KES {$product->ram_overage_rate}/GB-hour",
                'quantity' => $memoryOverageGbHours,
                'unit_price' => $product->ram_overage_rate,
                'amount' => $memoryOverageAmount,
            ]);

            $invoice->increment('subtotal', $memoryOverageAmount);
            $invoice->increment('tax', $memTax);
            $invoice->increment('total', $memoryOverageAmount + $memTax);
        }
    }
}
