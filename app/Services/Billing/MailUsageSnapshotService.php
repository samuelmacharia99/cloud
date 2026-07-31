<?php

namespace App\Services\Billing;

use App\Models\MailUsageSnapshot;
use App\Models\Service;
use App\Services\Provisioning\MailcowProvisioningService;
use Illuminate\Support\Facades\Log;

class MailUsageSnapshotService
{
    public function __construct(
        private MailcowProvisioningService $mailcow,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('usage_billing.mail_snapshots_enabled', true);
    }

    /**
     * Snapshot mailbox/alias counts for one email hosting service.
     */
    public function snapshotService(Service $service): ?MailUsageSnapshot
    {
        if (! $this->isEnabled() || ! $service->isEmailHosting()) {
            return null;
        }

        try {
            $client = $this->mailcow->clientForService($service);
            $domain = $this->mailcow->domainForService($service);
            $mailboxes = $client->listMailboxes($domain);
            $aliases = $client->listAliases($domain);

            $mailboxCount = $mailboxes['success'] ? count($mailboxes['data'] ?? []) : 0;
            $aliasCount = $aliases['success'] ? count($aliases['data'] ?? []) : 0;

            $quotaUsed = null;
            $quotaLimit = null;
            if ($mailboxes['success']) {
                $quotaUsed = 0;
                foreach ($mailboxes['data'] ?? [] as $box) {
                    $quotaUsed += (int) ($box['quota_used'] ?? $box['quota'] ?? 0);
                }
            }

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $quotaLimit = isset($meta['quota_mb']) ? (int) $meta['quota_mb'] : null;

            return MailUsageSnapshot::create([
                'service_id' => $service->id,
                'mailbox_count' => $mailboxCount,
                'alias_count' => $aliasCount,
                'quota_used_mb' => $quotaUsed,
                'quota_limit_mb' => $quotaLimit,
                'sampled_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Mail usage snapshot failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return int Number of services snapshotted
     */
    public function snapshotAllActive(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $count = 0;
        Service::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('provisioning_driver_key', 'mailcow')
                    ->orWhereHas('product', fn ($p) => $p->where('type', 'email_hosting'));
            })
            ->with(['product', 'node'])
            ->chunkById(50, function ($services) use (&$count) {
                foreach ($services as $service) {
                    if ($this->snapshotService($service)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
