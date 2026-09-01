<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionContainerServiceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Image pull + health + Ollama model download can exceed 30 minutes.
     */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $serviceId) {}

    public static function dispatchForService(int $serviceId, bool $deferUntilResponse = false): void
    {
        $dispatch = self::dispatch($serviceId);
        if ($deferUntilResponse && config('queue.default') === 'sync') {
            $dispatch->afterResponse();
        }
    }

    public function handle(ProvisioningService $provisioning): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $service = Service::query()->find($this->serviceId);
        if (! $service) {
            return;
        }

        $status = $service->status instanceof ServiceStatus
            ? $service->status
            : ServiceStatus::tryFrom((string) $service->status);

        if ($status === ServiceStatus::Active) {
            return;
        }

        if (! in_array($status, [ServiceStatus::Pending, ServiceStatus::Provisioning, ServiceStatus::Failed], true)) {
            return;
        }

        if ($status !== ServiceStatus::Provisioning) {
            $service->update(['status' => ServiceStatus::Provisioning]);
            $service = $service->fresh();
        }

        $provisioning->provision($service);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('ProvisionContainerServiceJob failed', [
            'service_id' => $this->serviceId,
            'error' => $e?->getMessage(),
        ]);
    }
}
