<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryDirectAdminMailPullJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2400;

    public int $tries = 1;

    public function __construct(public int $serviceId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('da-mail-pull-'.$this->serviceId))
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        DirectAdminToMailcowMigrationService $migrator,
        DirectAdminMailPullProgress $progress,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $service = Service::query()->find($this->serviceId);
        if (! $service) {
            return;
        }

        $progress->log($service, 'Worker started mail pull');

        try {
            $result = $migrator->retryMailContentPull($service);
            if (! ($result['success'] ?? false)) {
                $progress->fail($service, (string) ($result['message'] ?? 'Mail pull retry failed.'));
            }
        } catch (\Throwable $e) {
            $progress->fail($service, $e->getMessage());
            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('RetryDirectAdminMailPullJob failed', [
            'service_id' => $this->serviceId,
            'error' => $e?->getMessage(),
        ]);

        $service = Service::query()->find($this->serviceId);
        if (! $service) {
            return;
        }

        app(DirectAdminMailPullProgress::class)->fail(
            $service,
            $e?->getMessage() ?? 'Mail pull job failed'
        );
    }
}
