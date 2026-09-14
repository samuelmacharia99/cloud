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
 * Extract an uploaded archive inside a container's app directory, reporting
 * progress under the operation token the file manager polls.
 */
class ExtractContainerArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $token,
        public int $serviceId,
        public int $deploymentId,
        public int $userId,
        public string $ip,
        public string $archivePath,
        public string $destination,
        public bool $deleteArchive = false,
    ) {}

    /**
     * One archive operation per deployment at a time.
     *
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
            $result = $files->extractArchive(
                $service,
                $deployment,
                $this->archivePath,
                $this->destination,
                $this->deleteArchive,
                $user,
                $this->ip,
                fn (int $percent, string $label) => $progress->update($this->token, $percent, $label),
            );

            $progress->complete($this->token, sprintf(
                'Extracted %d file(s) (%s) into %s.',
                $result['count'],
                $this->formatBytes($result['bytes']),
                $result['destination'],
            ), $result);
        } catch (\InvalidArgumentException $e) {
            $progress->fail($this->token, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Container archive extraction failed', [
                'service_id' => $this->serviceId,
                'archive' => $this->archivePath,
                'error' => $e->getMessage(),
            ]);
            $progress->fail($this->token, 'Extraction failed: '.$e->getMessage());
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(ContainerFileOperationProgress::class)->fail($this->token, $e?->getMessage() ?? 'Extraction job failed.');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = (int) min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}
