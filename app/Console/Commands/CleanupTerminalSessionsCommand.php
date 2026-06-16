<?php

namespace App\Console\Commands;

use App\Services\Terminal\ContainerTerminalService;

class CleanupTerminalSessionsCommand extends BaseCronCommand
{
    protected $signature = 'terminal:cleanup';

    protected $description = 'Clean up expired container terminal sessions';

    protected function handleCron(): string
    {
        app(ContainerTerminalService::class)->cleanupExpiredSessions();

        return 'Terminal session cleanup completed successfully';
    }
}
