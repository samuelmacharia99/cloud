<?php

namespace App\Console\Commands;

use App\Models\CustomerProject;
use App\Services\Customer\ProjectConsumptionService;

class RefreshProjectConsumptionCommand extends BaseCronCommand
{
    protected $signature = 'cron:refresh-project-consumption';

    protected $description = 'Snapshot project CPU, RAM, disk, and transfer against the included plan every 6 hours';

    public function __construct(private ProjectConsumptionService $consumption)
    {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $count = 0;

        CustomerProject::query()
            ->orderBy('id')
            ->chunkById(50, function ($projects) use (&$count) {
                foreach ($projects as $project) {
                    $this->consumption->refresh($project);
                    $count++;
                }
            });

        return "Refreshed consumption for {$count} projects.";
    }
}
