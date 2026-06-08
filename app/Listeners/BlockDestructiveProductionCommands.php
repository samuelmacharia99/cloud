<?php

namespace App\Listeners;

use App\Support\ProductionCommandGuard;
use Illuminate\Console\Events\CommandStarting;

class BlockDestructiveProductionCommands
{
    public function handle(CommandStarting $event): void
    {
        if (! ProductionCommandGuard::isProduction()) {
            return;
        }

        $commandName = $event->command ?? '';

        if ($commandName === '') {
            return;
        }

        ProductionCommandGuard::assertCommandAllowed($commandName, $event->input->getOptions());
    }
}
