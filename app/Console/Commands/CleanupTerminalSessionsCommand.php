<?php

namespace App\Console\Commands;

use App\Services\Terminal\ContainerTerminalService;
use Illuminate\Console\Command;

class CleanupTerminalSessionsCommand extends Command
{
    protected $signature = 'terminal:cleanup';

    protected $description = 'Clean up expired container terminal sessions';

    public function handle()
    {
        $service = new ContainerTerminalService();
        $service->cleanupExpiredSessions();

        $this->info('Terminal session cleanup completed successfully');
        return 0;
    }
}
