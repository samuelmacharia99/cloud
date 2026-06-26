<?php

namespace App\Console\Commands;

use App\Services\Billing\ServiceBillingDateRepairService;
use App\Services\NotificationService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RepairServiceBillingDatesCommand extends Command
{
    protected $signature = 'billing:repair-service-dates
                            {--execute : Apply fixes (default is dry-run report only)}
                            {--cancel-duplicates : Cancel duplicate open renewal invoices for the same period}
                            {--unsuspend : Unsuspend services that qualify after repair}';

    protected $description = 'Backfill next_due_date for services whose paid renewal invoices did not advance billing';

    public function handle(
        ServiceBillingDateRepairService $repair,
        ServiceOverdueEnforcementService $enforcement,
        ProvisioningService $provisioning,
        NotificationService $notifications,
    ): int {
        $dryRun = ! $this->option('execute');
        $cancelDuplicates = (bool) $this->option('cancel-duplicates');
        $unsuspend = (bool) $this->option('unsuspend');

        if ($dryRun) {
            $this->warn('Dry run — pass --execute to apply changes.');
        }

        $affected = $repair->findAffected();

        if ($affected->isEmpty()) {
            $this->info('No services found with stale next_due_date after paid renewal invoices.');

            return self::SUCCESS;
        }

        $this->info("Found {$affected->count()} service(s) to repair:");
        $this->newLine();

        $repaired = 0;
        $cancelled = 0;
        $unsuspended = 0;

        foreach ($affected as $row) {
            $service = $row['service'];
            $anchor = $row['anchor_invoice'];
            $duplicates = $row['duplicate_invoices'];

            $this->line(sprintf(
                'Service #%d (%s) — user #%d — invoice %s paid — next_due_date %s → %s',
                $service->id,
                $service->name,
                $service->user_id,
                $anchor->invoice_number,
                $row['current_next_due_date'],
                $row['expected_next_due_date'],
            ));

            foreach ($duplicates as $duplicate) {
                $this->line("  ↳ duplicate open renewal: {$duplicate->invoice_number} ({$duplicate->status->value}, due {$duplicate->due_date?->toDateString()})");
            }

            $result = $repair->repair($service, $anchor, $cancelDuplicates, $dryRun);

            if (! $dryRun && $result['updated']) {
                $repaired++;
                $cancelled += count($result['cancelled_invoice_ids']);

                Log::info('Service billing date repaired', [
                    'service_id' => $service->id,
                    'anchor_invoice_id' => $anchor->id,
                    'expected_next_due_date' => $row['expected_next_due_date'],
                    'cancelled_invoice_ids' => $result['cancelled_invoice_ids'],
                ]);

                if ($unsuspend) {
                    $service = $service->fresh();

                    if ($enforcement->canAutoUnsuspendForPaidInvoice($service)) {
                        $enforcement->clearInvoiceSuspensionMeta($service);
                        $provisioning->unsuspend($service->fresh());
                        $notifications->notifyServiceUnsuspended($service->fresh());
                        $unsuspended++;
                        $this->line('  ↳ unsuspended');
                    }
                }
            } elseif ($dryRun && $cancelDuplicates) {
                $cancelled += count($result['cancelled_invoice_ids']);
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("Would repair {$affected->count()} service(s)".($cancelDuplicates ? " and cancel {$cancelled} duplicate invoice(s)" : '').'.');
            $this->line('Run with --execute'.($cancelDuplicates ? '' : ' --cancel-duplicates').($unsuspend ? ' --unsuspend' : '').' to apply.');
        } else {
            $this->info("Repaired {$repaired} service(s), cancelled {$cancelled} duplicate invoice(s), unsuspended {$unsuspended} service(s).");
        }

        return self::SUCCESS;
    }
}
