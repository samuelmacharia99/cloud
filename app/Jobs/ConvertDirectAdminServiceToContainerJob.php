<?php

namespace App\Jobs;

use App\Enums\DaConvertBatchItemStatus;
use App\Models\DaConvertBatchItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        public bool $acknowledgeAddonSites = false,
        public ?int $emailProductId = null,
        public ?int $batchItemId = null,
    ) {}

    /**
     * One convert at a time per DirectAdmin node so tar/mysqldump does not take Apache down.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $nodeId = (int) (Service::query()->whereKey($this->serviceId)->value('node_id') ?? 0);

        return [
            (new WithoutOverlapping('da-convert-node-'.$nodeId))
                ->releaseAfter(90)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(DirectAdminToContainerConvertService $convert): void
    {
        // afterResponse + sync queue still shares the web PHP process; disable the 30s cap.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        if ($this->batchItemId) {
            DaConvertBatchItem::query()->whereKey($this->batchItemId)->update([
                'status' => DaConvertBatchItemStatus::Converting,
            ]);
        }

        try {
            $service = Service::with('node', 'product')->findOrFail($this->serviceId);
            $product = Product::with('containerTemplate')->findOrFail($this->productId);

            $emailProduct = $this->emailProductId
                ? Product::query()->find($this->emailProductId)
                : null;

            $convert->convertInPlace(
                $service,
                $product,
                $this->acknowledgeExtraMailboxes,
                $this->databaseName,
                $this->acknowledgeAddonSites,
                $emailProduct,
            );
        } catch (\Throwable $e) {
            // convertInPlace already records da_convert=failed; keep sync drivers from 500'ing the admin UI.
            $this->failed($e);
            report($e);
        } finally {
            if ($this->batchItemId) {
                app(DaConvertOfframpService::class)->finalizeItem($this->batchItemId);
            }
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
