<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\DomainActivationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
     * @return array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int, msgs_per_day: int}
     */
    public function limitsForProduct(?Product $product): array
    {
        $limits = is_array($product?->resource_limits) ? $product->resource_limits : [];

        return [
            'mailboxes' => max(1, (int) ($limits['mailboxes'] ?? config('mailcow.default_mailboxes', 10))),
            'aliases' => max(0, (int) ($limits['aliases'] ?? config('mailcow.default_aliases', 20))),
            'quota_mb' => max(100, (int) ($limits['quota_mb'] ?? config('mailcow.default_quota_mb', 51200))),
            'mailbox_quota_mb' => max(100, (int) ($limits['mailbox_quota_mb'] ?? config('mailcow.default_mailbox_quota_mb', 5120))),
            'msgs_per_day' => max(1, (int) ($limits['msgs_per_day'] ?? config('mailcow.default_msgs_per_day', 500))),
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
            throw new InvalidArgumentException(
                'Email hosting requires a domain (service_meta.mailcow_domain or domain).'
            );
        }

        return $domain;
    }

    /**
     * Move this email plan to a different Mailcow domain.
     * Existing inboxes cannot be renamed, so the current domain must be empty.
     */
    public function changeMailDomain(Service $service, string $newFqdn): string
    {
        $newFqdn = app(DirectAdminDomainValidator::class)->assertValid($newFqdn);

        $current = null;
        try {
            $current = $this->domainForService($service);
        } catch (InvalidArgumentException) {
            $current = null;
        }

        if ($current !== null && $current === $newFqdn) {
            throw new InvalidArgumentException('That is already the mail domain for this service.');
        }

        $this->assertMailDomainAvailable($service, $newFqdn);

        $client = $this->clientForService($service);

        if ($current !== null) {
            $this->assertMailDomainIsEmpty($client, $current);
        }

        $limits = $this->limitsForProduct($service->product);
        $this->ensureDomainOnMailcow($client, $service, $newFqdn, $limits);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ($current !== null) {
            $meta['previous_mailcow_domain'] = $current;
        }
        $meta['mailcow_domain'] = $newFqdn;
        $meta['domain'] = $newFqdn;
        $meta['mailcow_domain_changed_at'] = now()->toIso8601String();

        $additional = $meta['additional_mail_domains'] ?? [];
        if (is_array($additional)) {
            $meta['additional_mail_domains'] = array_values(array_filter(
                $additional,
                fn ($item): bool => strtolower(trim((string) $item)) !== $newFqdn
            ));
        }

        $owned = $this->ownedDomainRecord($service, $newFqdn);
        if ($owned) {
            $meta['domain_id'] = $owned->id;
            $meta['cloudflare_dns'] = (bool) $owned->cloudflare_dns_enabled;
        } else {
            unset($meta['domain_id']);
        }

        $service->update([
            'external_reference' => $newFqdn,
            'service_meta' => $meta,
        ]);

        if ($current !== null && $current !== $newFqdn) {
            $deleted = $client->deleteDomain($current);
            if (! $deleted['success']) {
                Log::warning('Mailcow old domain delete failed after mail domain change', [
                    'service_id' => $service->id,
                    'old_domain' => $current,
                    'new_domain' => $newFqdn,
                    'message' => $deleted['message'] ?? null,
                ]);
            }
        }

        $fresh = $service->fresh(['node', 'product', 'user']);

        if (! empty($meta['domain_id']) && empty($meta['transfer_pending'])) {
            try {
                app(DomainActivationService::class)->activateFromService($fresh);
                $fresh = $service->fresh(['node', 'product', 'user']);
            } catch (\Throwable $e) {
                Log::info('Mailcow linked domain activation skipped after domain change', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(MailDnsService::class)->applyRecommendedRecords($fresh);
        } catch (\Throwable $e) {
            Log::info('Mailcow DNS auto-apply skipped after domain change', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Mailcow mail domain changed', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'from' => $current,
            'to' => $newFqdn,
        ]);

        return $newFqdn;
    }

    /**
     * What a wipe-and-replace would destroy on the current mail domain.
     *
     * @return array{domain: ?string, mailboxes: int, aliases: int, readable: bool, message: ?string}
     */
    public function currentMailDomainContents(Service $service): array
    {
        try {
            $domain = $this->domainForService($service);
        } catch (InvalidArgumentException) {
            return ['domain' => null, 'mailboxes' => 0, 'aliases' => 0, 'readable' => true, 'message' => null];
        }

        try {
            $client = $this->clientForService($service);
            $mailboxes = $client->listMailboxes($domain);
            $aliases = $client->listAliases($domain);

            if (! ($mailboxes['success'] ?? false) || ! ($aliases['success'] ?? false)) {
                return [
                    'domain' => $domain,
                    'mailboxes' => 0,
                    'aliases' => 0,
                    'readable' => false,
                    'message' => (string) ($mailboxes['message'] ?? $aliases['message'] ?? 'Mailcow did not answer.'),
                ];
            }

            return [
                'domain' => $domain,
                'mailboxes' => count($mailboxes['data'] ?? []),
                'aliases' => count($aliases['data'] ?? []),
                'readable' => true,
                'message' => null,
            ];
        } catch (\Throwable $e) {
            return ['domain' => $domain, 'mailboxes' => 0, 'aliases' => 0, 'readable' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Move an email plan to a new mail domain and destroy the old one with
     * everything in it. Mailcow cannot rename an inbox, so the mail on the old
     * domain cannot come along and there is no copy of it afterwards: only an
     * operator who has confirmed the loss may run this. The new domain is left
     * with the standard info@ inbox a fresh email service gets.
     *
     * @return array{domain: string, previous_domain: ?string, deleted_mailboxes: int, deleted_aliases: int, info_mailbox: ?string, info_password: ?string, warnings: list<string>}
     */
    public function replaceMailDomain(Service $service, string $newFqdn, User $actor): array
    {
        if (! $service->isEmailHosting()) {
            throw new InvalidArgumentException('This service is not an email hosting plan.');
        }

        $newFqdn = app(DirectAdminDomainValidator::class)->assertValid($newFqdn);
        $contents = $this->currentMailDomainContents($service);
        $previous = $contents['domain'];

        if ($previous !== null && $previous === $newFqdn) {
            throw new InvalidArgumentException('That is already the mail domain for this service.');
        }

        $this->assertMailDomainAvailable($service, $newFqdn);

        $client = $this->clientForService($service);
        $limits = $this->limitsForProduct($service->product);
        $this->ensureDomainOnMailcow($client, $service, $newFqdn, $limits);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ($previous !== null) {
            $meta['previous_mailcow_domain'] = $previous;
        }
        $meta['mailcow_domain'] = $newFqdn;
        $meta['domain'] = $newFqdn;
        $meta['mailcow_domain_changed_at'] = now()->toIso8601String();
        $history = is_array($meta['mailcow_domain_replacements'] ?? null) ? $meta['mailcow_domain_replacements'] : [];
        $history[] = [
            'at' => now()->toIso8601String(),
            'by_user_id' => $actor->id,
            'from' => $previous,
            'to' => $newFqdn,
            'destroyed_mailboxes' => $contents['mailboxes'],
            'destroyed_aliases' => $contents['aliases'],
        ];
        $meta['mailcow_domain_replacements'] = array_slice($history, -10);
        unset($meta['operator_inbox']);

        $additional = $meta['additional_mail_domains'] ?? [];
        if (is_array($additional)) {
            $meta['additional_mail_domains'] = array_values(array_filter(
                $additional,
                fn ($item): bool => strtolower(trim((string) $item)) !== $newFqdn
            ));
        }

        $owned = $this->ownedDomainRecord($service, $newFqdn);
        if ($owned) {
            $meta['domain_id'] = $owned->id;
            $meta['cloudflare_dns'] = (bool) $owned->cloudflare_dns_enabled;
        } else {
            unset($meta['domain_id']);
        }

        $service->update([
            'external_reference' => $newFqdn,
            'service_meta' => $meta,
        ]);

        $warnings = [];
        if ($previous !== null && $previous !== $newFqdn) {
            $deleted = $client->deleteDomain($previous);
            if (! ($deleted['success'] ?? false)) {
                $warnings[] = 'The old domain '.$previous.' could not be deleted on Mailcow ('
                    .mb_substr((string) ($deleted['message'] ?? 'no message'), 0, 160).'); remove it there by hand.';
                Log::warning('Mailcow old domain delete failed after admin replacement', [
                    'service_id' => $service->id,
                    'old_domain' => $previous,
                    'new_domain' => $newFqdn,
                ]);
            }
        }

        $fresh = $service->fresh(['node', 'product', 'user']);

        $info = $this->ensureInfoMailbox($newFqdn, $fresh);
        if (! ($info['success'] ?? false)) {
            $warnings[] = 'The info@ inbox could not be created: '.mb_substr((string) ($info['message'] ?? ''), 0, 160);
        }

        if (! empty($meta['domain_id']) && empty($meta['transfer_pending'])) {
            try {
                app(DomainActivationService::class)->activateFromService($fresh);
                $fresh = $service->fresh(['node', 'product', 'user']);
            } catch (\Throwable $e) {
                Log::info('Mailcow linked domain activation skipped after replacement', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(MailDnsService::class)->applyRecommendedRecords($fresh);
        } catch (\Throwable $e) {
            $warnings[] = 'Mail DNS records were not applied automatically; publish MX, SPF, DKIM and DMARC for '.$newFqdn.'.';
            Log::info('Mailcow DNS auto-apply skipped after replacement', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        AdminActivityService::log(
            'service.mail_domain_replaced',
            sprintf(
                'Replaced the mail domain on service #%d: %s → %s, destroying %d mailbox(es) and %d alias(es)',
                $service->id,
                $previous ?? 'unset',
                $newFqdn,
                $contents['mailboxes'],
                $contents['aliases']
            ),
            $service,
            [
                'actor_user_id' => $actor->id,
                'from' => $previous,
                'to' => $newFqdn,
                'destroyed_mailboxes' => $contents['mailboxes'],
                'destroyed_aliases' => $contents['aliases'],
            ]
        );

        Log::info('Mailcow mail domain replaced by an operator', [
            'service_id' => $service->id,
            'actor_user_id' => $actor->id,
            'from' => $previous,
            'to' => $newFqdn,
        ]);

        return [
            'domain' => $newFqdn,
            'previous_domain' => $previous,
            'deleted_mailboxes' => $contents['mailboxes'],
            'deleted_aliases' => $contents['aliases'],
            'info_mailbox' => ($info['success'] ?? false) ? (string) $info['email'] : null,
            'info_password' => $info['password'] ?? null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{id: int, fqdn: string}>
     */
    public function selectableDomainsForService(Service $service): array
    {
        $current = null;
        try {
            $current = $this->domainForService($service);
        } catch (InvalidArgumentException) {
            $current = null;
        }

        return Domain::query()
            ->where('user_id', $service->user_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => (int) $domain->id,
                'fqdn' => strtolower($domain->fqdn()),
            ])
            ->filter(fn (array $row): bool => $row['fqdn'] !== '' && $row['fqdn'] !== $current)
            ->unique('fqdn')
            ->values()
            ->all();
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
        $this->ensureDomainOnMailcow($mailcow, $service, $domain, $limits);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['mailcow_domain'] = $domain;
        $meta['mailcow_node_id'] = $node->id;
        $meta['mailbox_limit'] = $limits['mailboxes'];
        $meta['alias_limit'] = $limits['aliases'];
        $meta['quota_mb'] = $limits['quota_mb'];
        $meta['mailbox_quota_mb'] = $limits['mailbox_quota_mb'];
        $meta['msgs_per_day'] = $limits['msgs_per_day'];
        $meta['mailcow_provisioned_at'] = now()->toIso8601String();

        $service->update([
            'node_id' => $node->id,
            'external_reference' => $domain,
            'provisioning_driver_key' => 'mailcow',
            'status' => ServiceStatus::Active,
            'service_meta' => $meta,
        ]);

        $fresh = $service->fresh(['node', 'product', 'user']);

        if (! empty($meta['domain_id']) && empty($meta['transfer_pending'])) {
            try {
                app(DomainActivationService::class)->activateFromService($fresh);
                $fresh = $service->fresh(['node', 'product', 'user']);
            } catch (\Throwable $e) {
                Log::info('Mailcow linked domain activation skipped or partial', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(MailDnsService::class)->applyRecommendedRecords($fresh);
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

    /**
     * Push the product's daily send limit to all active Mailcow domains on this plan.
     *
     * @return array{updated: int, failed: int}
     */
    public function syncSendLimitsForProduct(Product $product): array
    {
        $limits = $this->limitsForProduct($product);
        $updated = 0;
        $failed = 0;

        $services = Service::query()
            ->where('product_id', $product->id)
            ->where('provisioning_driver_key', 'mailcow')
            ->whereIn('status', [
                ServiceStatus::Active->value,
                ServiceStatus::Suspended->value,
            ])
            ->get();

        foreach ($services as $service) {
            try {
                $domain = $this->domainForService($service);
                $client = $this->clientForService($service);
                $result = $client->editDomainRatelimit($domain, $limits['msgs_per_day'], 'd');

                if (! $result['success']) {
                    $failed++;
                    Log::warning('Mailcow send-limit sync failed', [
                        'service_id' => $service->id,
                        'domain' => $domain,
                        'message' => $result['message'] ?? null,
                    ]);

                    continue;
                }

                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                $meta['msgs_per_day'] = $limits['msgs_per_day'];
                $service->update(['service_meta' => $meta]);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Mailcow send-limit sync exception', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
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
     * @param  array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int, msgs_per_day: int}  $limits
     */
    private function ensureDomainOnMailcow(MailcowService $mailcow, Service $service, string $domain, array $limits): void
    {
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
                'rl_value' => (string) $limits['msgs_per_day'],
                'rl_frame' => 'd',
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

        $rl = $mailcow->editDomainRatelimit($domain, $limits['msgs_per_day'], 'd');
        if (! $rl['success']) {
            Log::warning('Mailcow domain send limit (msgs/day) update failed', [
                'service_id' => $service->id,
                'domain' => $domain,
                'msgs_per_day' => $limits['msgs_per_day'],
                'message' => $rl['message'] ?? null,
            ]);
        }
    }

    private function assertMailDomainAvailable(Service $service, string $fqdn): void
    {
        $conflict = Service::query()
            ->where('id', '!=', $service->id)
            ->where(function ($query) use ($fqdn): void {
                $query->where('external_reference', $fqdn)
                    ->orWhere('service_meta->mailcow_domain', $fqdn)
                    ->orWhere('service_meta->domain', $fqdn);
            })
            ->get()
            ->first(fn (Service $other): bool => $other->isEmailHosting());

        if ($conflict) {
            throw new \RuntimeException('Another email service already uses '.$fqdn.'.');
        }

        $parts = app(DirectAdminDomainValidator::class)->splitFqdn($fqdn);
        $foreign = Domain::query()
            ->where('user_id', '!=', $service->user_id)
            ->whereRaw('LOWER(name) = ? AND LOWER(extension) = ?', [$parts['name'], $parts['extension']])
            ->exists();

        if ($foreign) {
            throw new \RuntimeException('That domain is already on another Talksasa account.');
        }
    }

    private function assertMailDomainIsEmpty(MailcowService $client, string $domain): void
    {
        $mailboxes = $client->listMailboxes($domain);
        if (! ($mailboxes['success'] ?? false)) {
            throw new \RuntimeException('Could not list mailboxes on '.$domain.': '.($mailboxes['message'] ?? 'Mailcow error'));
        }
        if (count($mailboxes['data'] ?? []) > 0) {
            throw new \RuntimeException(
                'Delete all mailboxes on '.$domain.' before changing the mail domain. Mailcow cannot move existing inboxes to a new address.'
            );
        }

        $aliases = $client->listAliases($domain);
        if (! ($aliases['success'] ?? false)) {
            throw new \RuntimeException('Could not list aliases on '.$domain.': '.($aliases['message'] ?? 'Mailcow error'));
        }
        if (count($aliases['data'] ?? []) > 0) {
            throw new \RuntimeException(
                'Delete all aliases on '.$domain.' before changing the mail domain.'
            );
        }
    }

    private function ownedDomainRecord(Service $service, string $fqdn): ?Domain
    {
        $parts = app(DirectAdminDomainValidator::class)->splitFqdn($fqdn);

        return Domain::query()
            ->where('user_id', $service->user_id)
            ->whereRaw('LOWER(name) = ? AND LOWER(extension) = ?', [$parts['name'], $parts['extension']])
            ->first();
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

    /**
     * Create info@{domain} so platform mail has somewhere to land after MX cutover.
     *
     * @return array{success: bool, created: bool, email: string, message: string}
     */
    public function ensureInfoMailbox(string $domain, ?Service $emailService = null): array
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
        $email = 'info@'.$domain;
        if ($domain === '' || ! str_contains($domain, '.')) {
            return ['success' => false, 'created' => false, 'email' => $email, 'message' => 'Domain is missing.'];
        }

        $node = $this->resolveNode($emailService);
        if (! $node) {
            return ['success' => false, 'created' => false, 'email' => $email, 'message' => 'No active Mailcow node is available.'];
        }

        $mailcow = MailcowService::forNode($node);
        if (! $mailcow->isConfigured()) {
            return ['success' => false, 'created' => false, 'email' => $email, 'message' => 'Mailcow is not configured.'];
        }

        $limits = $this->limitsForProduct($emailService?->product);
        try {
            if ($emailService) {
                $this->ensureDomainOnMailcow($mailcow, $emailService, $domain, $limits);
            } else {
                $this->ensureBareMailcowDomain($mailcow, $domain, $limits);
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'created' => false, 'email' => $email, 'message' => $e->getMessage()];
        }

        $listed = $mailcow->listMailboxes($domain);
        $already = collect($listed['data'] ?? [])->contains(function ($row) use ($email): bool {
            $candidate = is_array($row)
                ? strtolower((string) ($row['username'] ?? $row['email'] ?? ''))
                : strtolower((string) $row);

            return $candidate === $email;
        });
        if ($already) {
            return ['success' => true, 'created' => false, 'email' => $email, 'message' => 'Inbox already exists.'];
        }

        $password = $this->generateMailboxPassword();
        $add = $mailcow->addMailbox([
            'local_part' => 'info',
            'domain' => $domain,
            'name' => 'info',
            'password' => $password,
            'password2' => $password,
            'quota' => (string) $limits['mailbox_quota_mb'],
            'active' => '1',
            'force_pw_update' => '1',
        ]);

        if (! ($add['success'] ?? false)) {
            $already = str_contains(strtolower((string) ($add['message'] ?? '')), 'exists')
                || str_contains(strtolower((string) ($add['message'] ?? '')), 'already');

            return [
                'success' => $already,
                'created' => false,
                'email' => $email,
                'message' => (string) ($add['message'] ?? 'Could not create info@ mailbox.'),
            ];
        }

        if ($emailService) {
            $meta = is_array($emailService->service_meta) ? $emailService->service_meta : [];
            $meta['operator_inbox'] = $email;
            $emailService->update(['service_meta' => $meta]);
        }

        return ['success' => true, 'created' => true, 'email' => $email, 'password' => $password, 'message' => 'Created '.$email];
    }

    /**
     * @param  array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int, msgs_per_day: int}  $limits
     */
    private function ensureBareMailcowDomain(MailcowService $mailcow, string $domain, array $limits): void
    {
        $existing = $mailcow->getDomain($domain);
        $domainExists = ($existing['success'] ?? false)
            && ! empty($existing['data'])
            && ! $this->isEmptyDomainPayload($existing['data'] ?? []);

        if ($domainExists) {
            return;
        }

        $created = $mailcow->addDomain([
            'domain' => $domain,
            'description' => 'Talksasa off-ramp operator inbox',
            'aliases' => (string) $limits['aliases'],
            'mailboxes' => (string) max($limits['mailboxes'], 2),
            'defquota' => (string) $limits['mailbox_quota_mb'],
            'maxquota' => (string) $limits['mailbox_quota_mb'],
            'quota' => (string) $limits['quota_mb'],
            'active' => '1',
            'rl_value' => (string) $limits['msgs_per_day'],
            'rl_frame' => 'd',
            'restart_sogo' => '1',
        ]);

        if (! ($created['success'] ?? false)) {
            throw new \RuntimeException('Mailcow domain create failed: '.$created['message']);
        }
    }
}
