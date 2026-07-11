<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MigrateDirectAdminWordPressToContainerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public int $sourceServiceId,
        public int $targetServiceId,
        public ?string $databaseName = null,
    ) {}

    public function handle(DirectAdminToContainerMigrationService $migrator): void
    {
        $source = Service::with('node', 'product')->findOrFail($this->sourceServiceId);
        $target = Service::with('containerDeployment.node', 'product.containerTemplate')->findOrFail($this->targetServiceId);

        $migrator->migrateWordPress($source, $target, $this->databaseName);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('MigrateDirectAdminWordPressToContainerJob failed', [
            'source' => $this->sourceServiceId,
            'target' => $this->targetServiceId,
            'error' => $e?->getMessage(),
        ]);

        $target = Service::find($this->targetServiceId);
        if (! $target) {
            return;
        }

        $meta = is_array($target->service_meta) ? $target->service_meta : [];
        $meta['da_migration'] = array_merge($meta['da_migration'] ?? [], [
            'status' => 'failed',
            'error' => $e?->getMessage() ?? 'Migration job failed',
            'failed_at' => now()->toIso8601String(),
        ]);
        $target->update(['service_meta' => $meta]);
    }
}
