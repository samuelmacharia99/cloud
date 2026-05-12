<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateResellerInvoicesCommand extends Command
{
    protected $signature = 'cron:generate-reseller-invoices';

    protected $description = 'Generate renewal invoices for reseller packages (10 days before expiry)';

    public function handle()
    {
        $advanceDays = 10;
        $upcomingDate = now()->addDays($advanceDays);

        $resellers = User::where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->whereNotNull('package_expires_at')
            ->whereBetween('package_expires_at', [now(), $upcomingDate])
            ->get();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($resellers as $reseller) {
            // Skip if reseller already has an unpaid reseller subscription invoice
            $existingUnpaid = Invoice::where('user_id', $reseller->id)
                ->where('type', 'reseller_subscription')
                ->where('status', 'unpaid')
                ->exists();

            if ($existingUnpaid) {
                $this->info("Skipping reseller #{$reseller->id} ({$reseller->email}): existing unpaid invoice");
                $skippedCount++;
                continue;
            }

            // Create invoice for package renewal
            $package = $reseller->resellerPackage;
            if (!$package) {
                $this->warn("Reseller #{$reseller->id} has no associated package");
                continue;
            }

            Invoice::create([
                'user_id'        => $reseller->id,
                'type'           => 'reseller_subscription',
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'status'         => 'unpaid',
                'due_date'       => $reseller->package_expires_at,
                'subtotal'       => $package->price,
                'tax'            => 0,
                'total'          => $package->price,
                'notes'          => "Reseller Package Renewal: {$package->name} ({$package->billing_cycle})",
            ]);

            $this->info("Created invoice for reseller #{$reseller->id} ({$reseller->email})");
            $createdCount++;
        }

        $this->line("");
        $this->info("Reseller invoice generation completed!");
        $this->info("Created: {$createdCount} | Skipped: {$skippedCount}");
    }
}
