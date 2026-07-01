<?php

namespace App\Console\Commands;

use App\Models\Service;

class SyncServiceNamesFromProductsCommand extends BaseCronCommand
{
    protected $signature = 'services:sync-names-from-products {--dry-run : Show mismatches without updating}';

    protected $description = 'Align service display names with their linked product names after plan changes';

    protected function handleCron(): string
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Service::query()
            ->with('product:id,name')
            ->whereNotNull('product_id')
            ->chunkById(200, function ($services) use ($dryRun, &$updated) {
                foreach ($services as $service) {
                    $productName = $service->product?->name;

                    if (! $productName || $service->name === $productName) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Service #{$service->id}: \"{$service->name}\" → \"{$productName}\"");

                        continue;
                    }

                    $service->update(['name' => $productName]);
                    $updated++;
                }
            });

        if ($dryRun) {
            return 'Dry run complete — mismatches listed above.';
        }

        return $updated > 0
            ? "Updated {$updated} service name(s) to match their products."
            : 'All service names already match their products.';
    }
}
