<?php

namespace App\Jobs;

use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerMigrationProgress;
use App\Services\Provisioning\ContainerMigrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Moves one application host to another out of band.
 *
 * Copying a multi-gigabyte stack takes far longer than a web request may live, so
 * the operator watches the migration console instead of holding the connection
 * open. Never retried: a half-applied migration must be inspected, not repeated.
 */
class MigrateContainerServiceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $serviceId,
        public int $targetNodeId,
        public string $reason,
    ) {
        $this->timeout = max(1800, (int) config('containers.migration.job_timeout_seconds', 7200));
    }

    public function handle(ContainerMigrationService $migration, ContainerMigrationProgress $progress): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $service = Service::query()
            ->with(['containerDeployment.node', 'product.containerTemplate', 'user'])
            ->find($this->serviceId);

        if (! $service) {
            Log::error('Container migration job skipped: service is gone', ['service_id' => $this->serviceId]);

            return;
        }

        $target = Node::query()->find($this->targetNodeId);
        if (! $target) {
            $progress->fail($service, 'The target host no longer exists. Nothing was moved.');

            return;
        }

        try {
            $migration->migrate($service, $target, $this->reason);
        } catch (\Throwable $e) {
            // migrate() already wrote the failure and rollback outcome to the console;
            // this only covers a throw from before that reporting could run.
            $progress->failIfActive($service, $e->getMessage());

            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('MigrateContainerServiceJob failed', [
            'service_id' => $this->serviceId,
            'target_node_id' => $this->targetNodeId,
            'error' => $e?->getMessage(),
        ]);

        $service = Service::query()->find($this->serviceId);
        if (! $service) {
            return;
        }

        // A worker killed by a timeout or the OOM killer never reaches handle()'s
        // catch, which would otherwise leave the console spinning forever.
        app(ContainerMigrationProgress::class)->failIfActive(
            $service,
            $e?->getMessage() ?: 'The migration worker stopped unexpectedly. Check the container deployment events before retrying.',
        );
    }
}
