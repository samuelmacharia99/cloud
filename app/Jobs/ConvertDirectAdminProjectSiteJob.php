<?php

namespace App\Jobs;

use App\Jobs\Concerns\ReleasesOverlapLockOnFatal;
use App\Models\Service;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConvertDirectAdminProjectSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ReleasesOverlapLockOnFatal, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $serviceId) {}

    /**
     * One export at a time per DirectAdmin node, shared with the primary
     * convert job, so four sibling tars never run against Apache together.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $meta = Service::query()->whereKey($this->serviceId)->value('service_meta');
        $meta = is_array($meta) ? $meta : (is_string($meta) ? (json_decode($meta, true) ?: []) : []);
        $nodeId = (int) ($meta['da_legacy']['da_node_id'] ?? 0);

        return [
            (new WithoutOverlapping(ConvertDirectAdminServiceToContainerJob::nodeLockKey($nodeId)))
                ->releaseAfter(90)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(DirectAdminToContainerConvertService $convert): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $service = Service::query()->findOrFail($this->serviceId);
        $this->releaseOverlapLockOnFatal(ConvertDirectAdminServiceToContainerJob::nodeLockKey(
            (int) ($service->service_meta['da_legacy']['da_node_id'] ?? 0)
        ));

        try {
            $convert->convertProjectSite($service);
        } catch (\Throwable $e) {
            $this->markFailed($service, $e->getMessage());
            report($e);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $service = Service::query()->find($this->serviceId);
        if ($service) {
            $this->markFailed($service, $e?->getMessage() ?? 'Convert job failed');
        }
    }

    private function markFailed(Service $service, string $error): void
    {
        Log::error('ConvertDirectAdminProjectSiteJob failed', [
            'service_id' => $service->id,
            'error' => $error,
        ]);

        app(DaConvertProgress::class)->fail($service, $error, ['mode' => DaConvertProgress::MODE_SITE]);
        $service->update(['status' => 'failed']);
    }
}
