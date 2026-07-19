<?php

namespace App\Console\Commands;

use App\Services\Provisioning\NginxProxyService;
use Illuminate\Console\Command;

class RefreshContainerNginxUploadLimitsCommand extends Command
{
    protected $signature = 'containers:refresh-nginx-upload-limits';

    protected $description = 'Rewrite container nginx vhosts missing client_max_body_size (fixes WordPress 413 uploads)';

    public function handle(NginxProxyService $nginx): int
    {
        $this->info('Refreshing container nginx upload limits...');

        $summary = $nginx->ensureUploadLimitsForAllDomains();

        $this->line("Checked: {$summary['checked']}");
        $this->line("Updated: {$summary['updated']}");
        $this->line("Failed: {$summary['failed']}");

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
