<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConvertDirectAdminServiceToContainerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2400;

    public function __construct(
        public int $serviceId,
        public int $productId,
        public bool $acknowledgeExtraMailboxes = false,
        public ?string $databaseName = null,
    ) {}

    public function handle(DirectAdminToContainerConvertService $convert): void
    {
        // afterResponse + sync queue still shares the web PHP process; disable the 30s cap.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        try {
            $service = Service::with('node', 'product')->findOrFail($this->serviceId);
            $product = Product::with('containerTemplate')->findOrFail($this->productId);

            $convert->convertInPlace(
                $service,
                $product,
                $this->acknowledgeExtraMailboxes,
                $this->databaseName,
            );
        } catch (\Throwable $e) {
            // convertInPlace already records da_convert=failed; keep sync drivers from 500'ing the admin UI.
            $this->failed($e);
            report($e);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('ConvertDirectAdminServiceToContainerJob failed', [
            'service_id' => $this->serviceId,
            'error' => $e?->getMessage(),
        ]);

        $service = Service::find($this->serviceId);
        if (! $service) {
            return;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['da_convert'] = array_merge($meta['da_convert'] ?? [], [
            'status' => 'failed',
            'error' => $e?->getMessage() ?? 'Convert job failed',
            'failed_at' => now()->toIso8601String(),
        ]);
        $service->update(['service_meta' => $meta]);
    }
}
