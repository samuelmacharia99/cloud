<?php

namespace App\Console\Commands;

use App\Services\ResellerDiskUsageService;

class CollectResellerDiskUsageCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-reseller-disk-usage';

    protected $description = 'Record daily disk usage snapshots for resellers (DirectAdmin + containers)';

    public function __construct(
        private ResellerDiskUsageService $diskUsage,
    ) {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $count = 0;

        foreach ($this->diskUsage->resellersWithPackages() as $reseller) {
            try {
                $this->diskUsage->recordDailySnapshot($reseller);
                $count++;
            } catch (\Throwable $e) {
                $this->warn("Reseller {$reseller->id}: {$e->getMessage()}");
            }
        }

        return "Recorded disk usage snapshots for {$count} reseller(s).";
    }
}
