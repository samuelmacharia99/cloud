<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ResellerSslService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionResellerSslJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $resellerId,
        public string $mode = 'auto',
    ) {}

    public function handle(ResellerSslService $sslService): void
    {
        $reseller = User::find($this->resellerId);

        if (! $reseller?->is_reseller) {
            return;
        }

        $result = match ($this->mode) {
            'renew' => array_merge(['action' => 'renew'], $sslService->renewCertificate($reseller)),
            'issue' => array_merge(['action' => 'issue'], $sslService->issueCertificate($reseller)),
            default => $sslService->processAutomatically($reseller),
        };

        Log::info('Reseller SSL background job finished', [
            'reseller_id' => $this->resellerId,
            'action' => $result['action'] ?? 'skipped',
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? null,
        ]);
    }
}
