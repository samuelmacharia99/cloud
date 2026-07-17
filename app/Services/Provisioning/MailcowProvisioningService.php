<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lifecycle for email_hosting services on Mailcow.
 */
class MailcowProvisioningService
{
    public function resolveNode(?Service $service = null): ?Node
    {
        if ($service?->node_id) {
            $service->loadMissing('node');
            if ($service->node?->type === 'mailcow' && $service->node->is_active) {
                return $service->node;
            }
        }

        return Node::query()
            ->where('type', 'mailcow')
            ->where('is_active', true)
            ->orderByDesc('status')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int}
     */
    public function limitsForProduct(?Product $product): array
    {
        $limits = is_array($product?->resource_limits) ? $product->resource_limits : [];

        return [
            'mailboxes' => max(1, (int) ($limits['mailboxes'] ?? config('mailcow.default_mailboxes', 10))),
            'aliases' => max(0, (int) ($limits['aliases'] ?? config('mailcow.default_aliases', 20))),
            'quota_mb' => max(100, (int) ($limits['quota_mb'] ?? config('mailcow.default_quota_mb', 51200))),
            'mailbox_quota_mb' => max(100, (int) ($limits['mailbox_quota_mb'] ?? config('mailcow.default_mailbox_quota_mb', 5120))),
        ];
    }

    public function domainForService(Service $service): string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        $domain = strtolower(trim((string) (
            $meta['mailcow_domain']
            ?? $meta['domain']
            ?? $service->external_reference
            ?? ''
        )));

        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        $domain = explode('/', $domain)[0] ?? $domain;

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new \InvalidArgumentException(
                'Email hosting requires a domain (service_meta.mailcow_domain or domain).'
            );
        }

        return $domain;
    }

    public function provision(Service $service): void
    {
        $node = $this->resolveNode($service);
        if (! $node) {
            throw new \RuntimeException('No active Mailcow node is available. Add one under Admin → Nodes.');
        }

        $mailcow = MailcowService::forNode($node);
        if (! $mailcow->isConfigured()) {
            throw new \RuntimeException('Mailcow node is missing API URL or API token.');
        }

        $domain = $this->domainForService($service);
        $limits = $this->limitsForProduct($service->product);

        $existing = $mailcow->getDomain($domain);
        $domainExists = $existing['success'] && ! empty($existing['data']) && ! $this->isEmptyDomainPayload($existing['data']);

        if (! $domainExists) {
            $created = $mailcow->addDomain([
                'domain' => $domain,
                'description' => 'Talksasa service #'.$service->id,
                'aliases' => (string) $limits['aliases'],
                'mailboxes' => (string) $limits['mailboxes'],
                'defquota' => (string) $limits['mailbox_quota_mb'],
                'maxquota' => (string) $limits['mailbox_quota_mb'],
                'quota' => (string) $limits['quota_mb'],
                'active' => '1',
                'rl_value' => '10',
                'rl_frame' => 's',
                'restart_sogo' => '1',
            ]);

            if (! $created['success']) {
                throw new \RuntimeException('Mailcow domain create failed: '.$created['message']);
            }
        } else {
            $mailcow->editDomain($domain, [
                'active' => '1',
                'mailboxes' => (string) $limits['mailboxes'],
                'aliases' => (string) $limits['aliases'],
                'quota' => (string) $limits['quota_mb'],
                'maxquota' => (string) $limits['mailbox_quota_mb'],
                'defquota' => (string) $limits['mailbox_quota_mb'],
            ]);
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['mailcow_domain'] = $domain;
        $meta['mailcow_node_id'] = $node->id;
        $meta['mailbox_limit'] = $limits['mailboxes'];
        $meta['alias_limit'] = $limits['aliases'];
        $meta['quota_mb'] = $limits['quota_mb'];
        $meta['mailbox_quota_mb'] = $limits['mailbox_quota_mb'];
        $meta['mailcow_provisioned_at'] = now()->toIso8601String();

        $service->update([
            'node_id' => $node->id,
            'external_reference' => $domain,
            'provisioning_driver_key' => 'mailcow',
            'status' => ServiceStatus::Active,
            'service_meta' => $meta,
        ]);

        try {
            app(MailDnsService::class)->applyRecommendedRecords($service->fresh(['node', 'product', 'user']));
        } catch (\Throwable $e) {
            Log::info('Mailcow DNS auto-apply skipped or partial', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function suspend(Service $service): void
    {
        $this->setDomainActive($service, false);
    }

    public function unsuspend(Service $service): void
    {
        $this->setDomainActive($service, true);
    }

    public function terminate(Service $service): void
    {
        $node = $this->resolveNode($service);
        if (! $node) {
            Log::warning('Mailcow terminate: no node for service '.$service->id);

            return;
        }

        $domain = $this->domainForService($service);
        $mailcow = MailcowService::forNode($node);
        $result = $mailcow->deleteDomain($domain);

        if (! $result['success']) {
            // Domain may already be gone
            Log::warning('Mailcow domain delete failed', [
                'service_id' => $service->id,
                'domain' => $domain,
                'message' => $result['message'],
            ]);
        }
    }

    private function setDomainActive(Service $service, bool $active): void
    {
        $node = $this->resolveNode($service);
        if (! $node) {
            throw new \RuntimeException('No Mailcow node assigned to this service.');
        }

        $domain = $this->domainForService($service);
        $result = MailcowService::forNode($node)->editDomain($domain, [
            'active' => $active ? '1' : '0',
        ]);

        if (! $result['success']) {
            throw new \RuntimeException('Mailcow domain update failed: '.$result['message']);
        }
    }

    /**
     * @param  mixed  $data
     */
    private function isEmptyDomainPayload($data): bool
    {
        if ($data === null || $data === [] || $data === '' || $data === false) {
            return true;
        }

        if (is_array($data) && isset($data['type']) && strtolower((string) $data['type']) === 'danger') {
            return true;
        }

        return false;
    }

    public function clientForService(Service $service): MailcowService
    {
        $node = $this->resolveNode($service);
        if (! $node) {
            throw new \RuntimeException('No Mailcow node available for this service.');
        }

        $client = MailcowService::forNode($node);
        if (! $client->isConfigured()) {
            throw new \RuntimeException('Mailcow API is not configured on the assigned node.');
        }

        return $client;
    }

    /**
     * @return array{imap_host: string, imap_port: int, smtp_host: string, smtp_port: int, smtp_ssl_port: int, webmail_url: string}
     */
    public function connectionSettings(Service $service): array
    {
        $client = $this->clientForService($service);
        $host = $client->mailHostname();

        return [
            'imap_host' => $host,
            'imap_port' => (int) config('mailcow.imap_port', 993),
            'smtp_host' => $host,
            'smtp_port' => (int) config('mailcow.smtp_port', 587),
            'smtp_ssl_port' => (int) config('mailcow.smtp_ssl_port', 465),
            'webmail_url' => $client->webmailUrl(),
        ];
    }

    public function generateMailboxPassword(int $length = 16): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }
}
