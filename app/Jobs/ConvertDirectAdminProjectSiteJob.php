<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConvertDirectAdminProjectSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public int $serviceId) {}

    public function handle(DirectAdminToContainerConvertService $convert): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $service = Service::query()->findOrFail($this->serviceId);

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

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['da_convert'] = array_merge($meta['da_convert'] ?? [], [
            'status' => 'failed',
            'error' => $error,
            'failed_at' => now()->toIso8601String(),
        ]);
        $service->update([
            'service_meta' => $meta,
            'status' => 'failed',
        ]);
    }
}
