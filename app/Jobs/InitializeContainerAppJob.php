<?php

namespace App\Jobs;

use App\Models\ContainerAppInitialization;
use App\Services\Provisioning\LaravelAppInitializationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitializeContainerAppJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $initializationId) {}

    public function handle(LaravelAppInitializationService $service): void
    {
        $initialization = ContainerAppInitialization::find($this->initializationId);

        if (! $initialization || ! $initialization->isActive()) {
            return;
        }

        $service->run($initialization);
    }
}
