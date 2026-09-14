<?php

namespace App\Jobs;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerFileOperationProgress;
use App\Services\Provisioning\ContainerFileServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pack a file-manager selection into a zip in the node's scratch directory so
 * the browser can download it once.
 */
class BuildContainerArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public string $token,
        public int $serviceId,
        public int $deploymentId,
        public int $userId,
        public string $ip,
        public array $paths,
    ) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('fm-'.$this->deploymentId))->releaseAfter(30)->expireAfter($this->timeout + 60)];
    }

    public function handle(ContainerFileOperationProgress $progress, ContainerFileServiceFactory $factory): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $service = Service::query()->find($this->serviceId);
        $deployment = ContainerDeployment::query()->with('node', 'service.product.containerTemplate')->find($this->deploymentId);
        $user = User::query()->find($this->userId);
        if (! $service || ! $deployment || ! $user) {
            $progress->fail($this->token, 'The service or deployment no longer exists.');

            return;
        }

        $progress->update($this->token, 3, 'Connecting to the container host');

        try {
            $files = $factory->make($deployment);
            $result = $files->buildArchive(
                $service,
                $deployment,
                $this->paths,
                $user,
                $this->ip,
                fn (int $percent, string $label) => $progress->update($this->token, $percent, $label),
            );

            $progress->complete($this->token, 'Ready to download: '.$result['name'], [
                'remote_path' => $result['remote_path'],
                'name' => $result['name'],
                'bytes' => $result['bytes'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $progress->fail($this->token, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Container archive build failed', [
                'service_id' => $this->serviceId,
                'error' => $e->getMessage(),
            ]);
            $progress->fail($this->token, 'Packing failed: '.$e->getMessage());
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(ContainerFileOperationProgress::class)->fail($this->token, $e?->getMessage() ?? 'Archive job failed.');
    }
}
