<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
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
        $warned = 0;
        $notifications = app(NotificationService::class);

        foreach ($this->diskUsage->resellersWithPackages() as $reseller) {
            try {
                $usage = $this->diskUsage->collectCurrentUsage($reseller);
                $this->diskUsage->recordDailySnapshot($reseller);
                $count++;

                if ($this->diskUsage->isOverPool($reseller, $usage)) {
                    $settings = $reseller->settings ?? [];
                    $lastWarned = $settings['disk_pool_warning_sent_at'] ?? null;
                    $shouldWarn = ! $lastWarned || now()->parse($lastWarned)->lt(now()->subDays(7));

                    if ($shouldWarn) {
                        $notifications->notifyResellerDiskPoolWarning(
                            $reseller,
                            $usage['total_used_gb'],
                            (float) $this->diskUsage->diskPoolGb($reseller),
                        );
                        $settings['disk_pool_warning_sent_at'] = now()->toIso8601String();
                        $reseller->update(['settings' => $settings]);
                        $warned++;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("Reseller {$reseller->id}: {$e->getMessage()}");
            }
        }

        return "Recorded disk usage snapshots for {$count} reseller(s); sent {$warned} disk pool warning(s).";
    }
}
