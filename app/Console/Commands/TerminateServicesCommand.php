<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Setting;

class TerminateServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:terminate-services';
    protected $description = 'Terminates services that have been suspended past the terminate_after_days threshold';

    protected function handleCron(): string
    {
        $terminateDays = (int) Setting::getValue('terminate_after_days', 30);

        $services = Service::where('status', 'suspended')
            ->where('suspend_date', '<=', now()->subDays($terminateDays))
            ->get();

        $count = 0;
        foreach ($services as $service) {
            $service->update([
                'status' => 'terminated',
                'terminate_date' => now(),
            ]);
            $count++;
        }

        return "Terminated {$count} service(s) after {$terminateDays}-day suspension window.";
    }
}
