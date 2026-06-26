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
                            {--unsuspend : Unsuspend services that qualify after repair}
                            {--fix-links : Cancel open renewal invoices linked on service.invoice_id and relink to last paid renewal}';

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
        $fixLinks = (bool) $this->option('fix-links');

        if ($dryRun) {
            $this->warn('Dry run — pass --execute to apply changes.');
        }

        $repaired = 0;
        $cancelled = 0;
        $unsuspended = 0;
        $linked = 0;

        if ($fixLinks) {
            $mislinked = $repair->findMislinkedRenewalServices();
            $this->info("Found {$mislinked->count()} service(s) with open renewal invoice links to fix:");
            $this->newLine();

            foreach ($mislinked as $row) {
                $service = $row['service'];
                $anchor = $row['anchor_invoice'];
                $open = $row['open_invoice'];

                $this->line(sprintf(
                    'Service #%d (%s) — open %s (%s) → paid anchor %s',
                    $service->id,
                    $service->name,
                    $open->invoice_number,
                    $open->status->value,
                    $anchor->invoice_number,
                ));

                if (! $dryRun) {
                    $result = $repair->repairMislinkedService($service, $anchor, $open);
                    $linked++;
                    $cancelled += count($result['cancelled_invoice_ids']);
                }
            }

            $this->newLine();
        }

        $affected = $repair->findAffected();

        if ($affected->isEmpty() && ! $fixLinks) {
            $this->info('No services found with stale next_due_date after paid renewal invoices.');

            if ($unsuspend && ! $dryRun) {
                $unsuspended = $this->unsuspendEligibleServices($enforcement, $provisioning, $notifications);
                $this->info("Unsuspended {$unsuspended} service(s).");
            }

            return self::SUCCESS;
        }

        if ($affected->isNotEmpty()) {
            $this->info("Found {$affected->count()} service(s) to repair:");
            $this->newLine();
        }

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

        if ($unsuspend && ! $dryRun) {
            $unsuspended += $this->unsuspendEligibleServices($enforcement, $provisioning, $notifications);
        }

        if ($dryRun) {
            $parts = [];
            if ($affected->isNotEmpty()) {
                $parts[] = "repair {$affected->count()} service(s)";
            }
            if ($fixLinks) {
                $parts[] = 'fix mislinked renewal invoices';
            }
            $this->info('Would '.implode(' and ', $parts ?: ['make no changes']).($cancelDuplicates ? " and cancel {$cancelled} duplicate invoice(s)" : '').'.');
            $this->line('Run with --execute'.($cancelDuplicates ? '' : ' --cancel-duplicates').($fixLinks ? ' --fix-links' : '').($unsuspend ? ' --unsuspend' : '').' to apply.');
        } else {
            $this->info("Repaired {$repaired} service(s), fixed {$linked} invoice link(s), cancelled {$cancelled} duplicate invoice(s), unsuspended {$unsuspended} service(s).");
        }

        return self::SUCCESS;
    }

    private function unsuspendEligibleServices(
        ServiceOverdueEnforcementService $enforcement,
        ProvisioningService $provisioning,
        NotificationService $notifications,
    ): int {
        $count = 0;

        foreach ($enforcement->suspendedServicesWithPaidBillingInvoiceQuery()->get() as $service) {
            if (! $enforcement->canAutoUnsuspendForPaidInvoice($service)) {
                continue;
            }

            try {
                $enforcement->clearInvoiceSuspensionMeta($service);
                $provisioning->unsuspend($service->fresh());
                $notifications->notifyServiceUnsuspended($service->fresh());
                $count++;
                $this->line("Unsuspended service #{$service->id} ({$service->name})");
            } catch (\Throwable $e) {
                Log::error('Failed to unsuspend service after billing repair', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
