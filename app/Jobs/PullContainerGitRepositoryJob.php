<?php

namespace App\Jobs;

use App\Models\ContainerGitPull;
use App\Services\Provisioning\ContainerGitRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullContainerGitRepositoryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $gitPullId) {}

    public function handle(ContainerGitRepositoryService $service): void
    {
        $pull = ContainerGitPull::find($this->gitPullId);

        if (! $pull || ! $pull->isActive()) {
            return;
        }

        $service->runPull($pull);
    }
}
