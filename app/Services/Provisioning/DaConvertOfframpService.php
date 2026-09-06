<?php

namespace App\Services\Provisioning;

use App\Enums\DaConvertBatchItemStatus;
use App\Enums\DaConvertBatchStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerHostedAccountLinkService;
use App\Services\ResellerScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DaConvertOfframpService
{
    public const QUEUE = 'da-convert';

    public function __construct(
        private DirectAdminToContainerConvertService $convert,
        private ResellerScopeService $scope,
        private ContainerDomainBindingService $binding,
        private DomainCloudflareDnsService $cloudflare,
        private NginxProxyService $nginx,
        private DaAccountSnapshotService $snapshots,
        private DaResellerPackageImportService $packages,
        private DirectAdminMailPullProgress $mailPull,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerHostedAccountLinkService $linker,
        private MailcowProvisioningService $mailcow,
    ) {}

    /**
     * @return Collection<int, Service>
     */
    public function eligibleServices(User $reseller): Collection
    {
        return $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product', 'node', 'latestDaAccountSnapshot', 'containerDeployment'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Service $service): bool => $service->isSharedHosting())
            ->values();
    }

    /**
     * DirectAdmin users still to convert: platform-linked services plus live DA
     * accounts that were never imported. Hide accounts already on a container
     * with Talksasa Cloudflare nameservers active.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eligibleAccounts(User $reseller): Collection
    {
        $managed = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product', 'node', 'latestDaAccountSnapshot', 'containerDeployment'])
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($managed as $service) {
            if (! $service->isSharedHosting()) {
                continue;
            }

            $username = $this->serviceDaUsername($service);
            $domain = $this->serviceHostname($service);
            if ($this->shouldHideFromOfframp($reseller, $username, $domain, $managed)) {
                continue;
            }

            $key = $username !== '' ? 'da:'.$username : 'service:'.$service->id;
            $rows[$key] = $this->accountFromService($service, $key, $username, $domain);
        }

        foreach ($this->liveDirectAdminEntries($reseller) as $entry) {
            $username = strtolower(trim((string) ($entry['username'] ?? '')));
            if ($username === '') {
                continue;
            }
            $domain = strtolower(trim((string) ($entry['domain'] ?? '')));
            $key = 'da:'.$username;
            if (isset($rows[$key])) {
                $rows[$key]['package'] = $rows[$key]['package'] ?: ($entry['package'] ?? null);
                $rows[$key]['domain'] = $rows[$key]['domain'] ?: ($domain !== '' ? $domain : null);
                $rows[$key]['suspended_on_da'] = (bool) ($entry['suspended'] ?? false);

                continue;
            }
            if ($this->shouldHideFromOfframp($reseller, $username, $domain !== '' ? $domain : null, $managed)) {
                continue;
            }

            $rows[$key] = $this->accountFromDaEntry($reseller, $entry, $key);
        }

        return collect(array_values($rows));
    }

    /**
     * @param  list<int>  $serviceIds
     * @param  list<string>  $accountKeys
     */
    public function queueBatch(
        User $reseller,
        User $admin,
        array $serviceIds,
        Product $product,
        ?Product $emailProduct,
        bool $acknowledgeMailPull,
        bool $acknowledgeAddonSites,
        array $accountKeys = [],
    ): DaConvertBatch {
        if (! $reseller->is_reseller) {
            throw new InvalidArgumentException('Only a reseller book can be converted in batch.');
        }

        if ($product && ($product->type !== 'container_hosting' || ! $product->is_active)) {
            throw new InvalidArgumentException('Select an active Application Hosting product as the fallback container size.');
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', $accountKeys))));
        if ($keys === []) {
            $keys = array_map(
                fn (int $id): string => 'service:'.$id,
                array_values(array_unique(array_map('intval', $serviceIds)))
            );
        }

        $services = $this->materializeSelectedAccounts($reseller, $keys);

        if ($services->isEmpty()) {
            throw new InvalidArgumentException('Select at least one DirectAdmin account that belongs to this reseller.');
        }

        $prepared = [];
        foreach ($services as $service) {
            $prepared[] = $this->prepareItem($service, $acknowledgeMailPull, $acknowledgeAddonSites);
        }

        foreach ($prepared as $index => $row) {
            if ($row['status'] !== DaConvertBatchItemStatus::Queued) {
                continue;
            }

            try {
                $this->snapshots->captureOrFail($row['service']);
            } catch (\Throwable $e) {
                $prepared[$index]['status'] = DaConvertBatchItemStatus::Blocked;
                $prepared[$index]['error'] = $e->getMessage();
                $prepared[$index]['blockers'] = array_values(array_filter([
                    ...($row['blockers'] ?? []),
                    $e->getMessage(),
                ]));
            }
        }

        foreach ($prepared as $index => $row) {
            if ($row['status'] !== DaConvertBatchItemStatus::Queued) {
                continue;
            }

            $mapped = $this->packages->resolveForService($reseller, $row['service'], $product);
            $prepared[$index]['listing'] = $mapped['listing'];
            $prepared[$index]['engine'] = $mapped['engine'];
            $prepared[$index]['retail'] = $mapped['retail'];
            $prepared[$index]['da_package'] = $mapped['da_package'];

            if (! $mapped['engine']) {
                $prepared[$index]['status'] = DaConvertBatchItemStatus::Blocked;
                $prepared[$index]['error'] = 'Import this reseller’s DirectAdmin packages first, or choose a fallback Application Hosting size.';
                $prepared[$index]['blockers'][] = $prepared[$index]['error'];
            }
        }

        $toQueue = array_values(array_filter(
            $prepared,
            fn (array $row): bool => $row['status'] === DaConvertBatchItemStatus::Queued
        ));

        foreach ($toQueue as $row) {
            $stack = (string) ($row['detected_stack'] ?: 'php');
            $sites = 1 + (int) ($row['addon_site_count'] ?? 0);
            $share = $sites > 1 ? round(1 / $sites, 4) : 1.0;
            $engine = $row['engine'] ?? $product;
            $this->convert->assertHostCapacityForConvert($row['service'], $engine, $stack, $share);
        }

        $batch = DB::transaction(function () use (
            $reseller,
            $admin,
            $product,
            $emailProduct,
            $acknowledgeMailPull,
            $acknowledgeAddonSites,
            $prepared,
        ): DaConvertBatch {
            $hasQueued = collect($prepared)->contains(
                fn (array $row): bool => $row['status'] === DaConvertBatchItemStatus::Queued
            );

            $batch = DaConvertBatch::query()->create([
                'reseller_user_id' => $reseller->id,
                'admin_user_id' => $admin->id,
                'product_id' => $product->id,
                'email_product_id' => $emailProduct?->id,
                'acknowledge_mail_pull' => $acknowledgeMailPull,
                'acknowledge_addon_sites' => $acknowledgeAddonSites,
                'status' => $hasQueued ? DaConvertBatchStatus::Queued : DaConvertBatchStatus::Failed,
                'error' => $hasQueued ? null : 'No selected accounts were ready to convert.',
            ]);

            foreach ($prepared as $row) {
                $service = $row['service'];
                $engine = $row['engine'] ?? $product;
                $listing = $row['listing'] ?? null;
                DaConvertBatchItem::query()->create([
                    'da_convert_batch_id' => $batch->id,
                    'service_id' => $service->id,
                    'reseller_product_id' => $listing?->id,
                    'product_id' => $engine?->id,
                    'status' => $row['status'],
                    'detected_stack' => $row['detected_stack'],
                    'mailbox_count' => $row['mailbox_count'],
                    'has_addon_sites' => $row['has_addon_sites'],
                    'blockers' => $row['blockers'],
                    'error' => $row['error'],
                    'hostname' => $row['hostname'],
                ]);

                if ($row['status'] === DaConvertBatchItemStatus::Queued && $engine) {
                    $this->markServiceQueued($service, $engine, $row['detected_stack'], $listing, $row['retail'] ?? null);
                }
            }

            return $batch->fresh('items') ?? $batch;
        });

        foreach ($batch->items as $item) {
            if ($item->status !== DaConvertBatchItemStatus::Queued) {
                continue;
            }

            ConvertDirectAdminServiceToContainerJob::dispatch(
                (int) $item->service_id,
                (int) ($item->product_id ?: $product->id),
                $acknowledgeMailPull,
                null,
                $acknowledgeAddonSites,
                $emailProduct?->id,
                (int) $item->id,
            )->onQueue(self::QUEUE);
        }

        if ($batch->items->contains(fn (DaConvertBatchItem $item): bool => $item->status === DaConvertBatchItemStatus::Queued)) {
            $batch->update(['status' => DaConvertBatchStatus::Converting]);
        }

        AdminActivityService::log(
            'reseller.da_offramp_batch',
            'Queued DirectAdmin off-ramp batch #'.$batch->id.' for reseller '.$reseller->name,
            $reseller,
            [
                'batch_id' => $batch->id,
                'service_ids' => $batch->items->pluck('service_id')->all(),
                'queued' => $batch->items->where('status', DaConvertBatchItemStatus::Queued)->count(),
                'blocked' => $batch->items->where('status', DaConvertBatchItemStatus::Blocked)->count(),
                'needs_ack' => $batch->items->where('status', DaConvertBatchItemStatus::NeedsAck)->count(),
            ],
        );

        return $batch->fresh('items.service') ?? $batch;
    }

    public function finalizeItem(int $batchItemId): void
    {
        $item = DaConvertBatchItem::query()->with('service', 'batch')->find($batchItemId);
        if (! $item) {
            return;
        }

        $service = $item->service;
        $convertStatus = is_array($service?->service_meta['da_convert'] ?? null)
            ? (string) ($service->service_meta['da_convert']['status'] ?? '')
            : '';

        if ($convertStatus === 'failed') {
            $item->update([
                'status' => DaConvertBatchItemStatus::Failed,
                'error' => (string) ($service->service_meta['da_convert']['error'] ?? 'Convert failed.'),
            ]);
        } elseif ($convertStatus === 'completed') {
            $item->update(['status' => DaConvertBatchItemStatus::WaitingDns]);
            $this->refreshCutoverStatus($item->fresh() ?? $item);
        }

        $this->refreshBatchStatus($item->batch);
    }

    public function refreshCutoverStatus(DaConvertBatchItem $item): DaConvertBatchItem
    {
        $service = $item->service?->fresh(['containerDeployment.node', 'containerDeployment.domains', 'user']);
        if (! $service) {
            return $item;
        }

        $hostname = $this->binding->resolvePrimaryHostname($service)
            ?: (string) ($item->hostname ?: '');
        $nodeIp = (string) ($service->containerDeployment?->node?->ip_address ?? '');
        $managed = $hostname !== ''
            && $this->cloudflare->resolvePlatformDomainForHostname((int) $service->user_id, $hostname) !== null;
        $dnsOk = $hostname !== '' && $nodeIp !== '' && $this->nginx->checkDns($hostname, $nodeIp);
        $sslOk = (bool) $service->containerDeployment?->domains
            ?->contains(fn ($domain): bool => $domain->ssl_enabled && strtolower((string) $domain->domain) === strtolower($hostname));

        $notes = [
            'hostname' => $hostname,
            'www' => $hostname !== '' && ! str_starts_with($hostname, 'www.') ? 'www.'.$hostname : $hostname,
            'target_ip' => $nodeIp,
            'dns_managed' => $managed,
            'instruction' => $managed
                ? 'Talksasa Cloudflare can publish the A record. Use Cut web DNS to upsert and re-check.'
                : 'Point the A record for this hostname (and www) to '.$nodeIp.' at the current DNS host.',
        ];

        $next = $item->status;
        if (in_array($item->status, [
            DaConvertBatchItemStatus::Converted,
            DaConvertBatchItemStatus::WaitingDns,
            DaConvertBatchItemStatus::WaitingMx,
        ], true)) {
            $next = $dnsOk
                ? DaConvertBatchItemStatus::WaitingMx
                : DaConvertBatchItemStatus::WaitingDns;
            if ($dnsOk && (int) $item->mailbox_count === 0) {
                $next = DaConvertBatchItemStatus::Done;
            }
        }

        $item->update([
            'hostname' => $hostname !== '' ? $hostname : $item->hostname,
            'target_ip' => $nodeIp !== '' ? $nodeIp : $item->target_ip,
            'dns_managed' => $managed,
            'dns_ok' => $dnsOk,
            'ssl_ok' => $sslOk,
            'cutover_notes' => $notes,
            'status' => $next,
        ]);

        return $item->fresh() ?? $item;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{updated: int, skipped: int}
     */
    public function cutWebDns(DaConvertBatch $batch, array $itemIds): array
    {
        $wanted = array_values(array_unique(array_map('intval', $itemIds)));
        $updated = 0;
        $skipped = 0;

        $items = $batch->items()
            ->with('service.containerDeployment.node', 'service.user')
            ->whereIn('id', $wanted)
            ->get();

        foreach ($items as $item) {
            $service = $item->service;
            if (! $service || ! $service->containerDeployment) {
                $skipped++;

                continue;
            }

            $hostname = $this->binding->resolvePrimaryHostname($service) ?: (string) $item->hostname;
            if ($hostname === '') {
                $skipped++;

                continue;
            }

            try {
                $this->binding->bindHostnamePair($service, $hostname);
            } catch (\Throwable $e) {
                $item->update(['error' => $e->getMessage()]);
                $skipped++;

                continue;
            }

            $this->refreshCutoverStatus($item->fresh() ?? $item);
            $updated++;
        }

        $this->refreshBatchStatus($batch->fresh() ?? $batch);

        AdminActivityService::log(
            'reseller.da_offramp_cut_dns',
            'Cut web DNS for DirectAdmin off-ramp batch #'.$batch->id,
            $batch->reseller,
            ['batch_id' => $batch->id, 'item_ids' => $wanted, 'updated' => $updated, 'skipped' => $skipped],
        );

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    public function refreshBatchStatus(?DaConvertBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        $batch->load('items');
        $items = $batch->items;
        if ($items->isEmpty()) {
            return;
        }

        $active = $items->contains(
            fn (DaConvertBatchItem $item): bool => $item->status?->isActiveConvert() ?? false
        );
        if ($active) {
            $batch->update(['status' => DaConvertBatchStatus::Converting]);

            return;
        }

        $ready = $items->contains(
            fn (DaConvertBatchItem $item): bool => in_array($item->status, [
                DaConvertBatchItemStatus::WaitingDns,
                DaConvertBatchItemStatus::WaitingMx,
                DaConvertBatchItemStatus::Converted,
            ], true)
        );
        $allTerminal = $items->every(
            fn (DaConvertBatchItem $item): bool => in_array($item->status, [
                DaConvertBatchItemStatus::Done,
                DaConvertBatchItemStatus::Blocked,
                DaConvertBatchItemStatus::NeedsAck,
                DaConvertBatchItemStatus::Failed,
            ], true)
        );
        $anyQueuedWork = $items->contains(
            fn (DaConvertBatchItem $item): bool => $item->status === DaConvertBatchItemStatus::Queued
                || $item->status === DaConvertBatchItemStatus::Converting
                || $item->status === DaConvertBatchItemStatus::WaitingDns
                || $item->status === DaConvertBatchItemStatus::WaitingMx
                || $item->status === DaConvertBatchItemStatus::Converted
        );

        if ($ready) {
            $batch->update(['status' => DaConvertBatchStatus::ReadyForCutover]);

            return;
        }

        if ($allTerminal && ! $anyQueuedWork) {
            $failedOnly = $items->every(
                fn (DaConvertBatchItem $item): bool => in_array($item->status, [
                    DaConvertBatchItemStatus::Failed,
                    DaConvertBatchItemStatus::Blocked,
                    DaConvertBatchItemStatus::NeedsAck,
                ], true)
            );
            $batch->update([
                'status' => $failedOnly ? DaConvertBatchStatus::Failed : DaConvertBatchStatus::Completed,
            ]);
        }
    }

    /**
     * @return array{
     *     service: Service,
     *     status: DaConvertBatchItemStatus,
     *     detected_stack: ?string,
     *     mailbox_count: int,
     *     has_addon_sites: bool,
     *     addon_site_count: int,
     *     blockers: list<string>,
     *     error: ?string,
     *     hostname: ?string
     * }
     */
    private function prepareItem(Service $service, bool $acknowledgeMailPull, bool $acknowledgeAddonSites): array
    {
        $base = [
            'service' => $service,
            'detected_stack' => null,
            'mailbox_count' => 0,
            'has_addon_sites' => false,
            'addon_site_count' => 0,
            'blockers' => [],
            'error' => null,
            'hostname' => $service->attachedDomainName() ?: $service->name,
        ];

        if ($this->isAlreadyBusy($service)) {
            return [
                ...$base,
                'status' => DaConvertBatchItemStatus::Blocked,
                'error' => 'This account is already converting or has already left DirectAdmin.',
                'blockers' => ['Already converting or converted.'],
            ];
        }

        try {
            $preflight = $this->convert->preflight($service);
        } catch (\Throwable $e) {
            return [
                ...$base,
                'status' => DaConvertBatchItemStatus::Blocked,
                'error' => $e->getMessage(),
                'blockers' => [$e->getMessage()],
            ];
        }

        $blockers = array_values(array_filter($preflight['blockers'] ?? []));
        $mailboxCount = (int) ($preflight['mailbox_count'] ?? 0);
        $hasAddons = (bool) ($preflight['has_addon_sites'] ?? false);
        $stack = (string) ($preflight['detected_stack'] ?? '');
        $hostname = (string) ($preflight['inventory']['domain'] ?? $base['hostname']);

        $base['detected_stack'] = $stack !== '' ? $stack : null;
        $base['mailbox_count'] = $mailboxCount;
        $base['has_addon_sites'] = $hasAddons;
        $base['addon_site_count'] = (int) ($preflight['inventory']['addon_site_count'] ?? 0);
        $base['hostname'] = $hostname !== '' ? $hostname : $base['hostname'];

        if ($blockers !== []) {
            return [
                ...$base,
                'status' => DaConvertBatchItemStatus::Blocked,
                'blockers' => $blockers,
                'error' => implode(' ', $blockers),
            ];
        }

        if ($mailboxCount > 0 && ! $acknowledgeMailPull) {
            return [
                ...$base,
                'status' => DaConvertBatchItemStatus::NeedsAck,
                'error' => 'Acknowledge that mail is pulled to Mailcow before converting this account.',
            ];
        }

        if ($hasAddons && ! $acknowledgeAddonSites) {
            return [
                ...$base,
                'status' => DaConvertBatchItemStatus::NeedsAck,
                'error' => 'Acknowledge that extra live sites launch as sibling containers.',
            ];
        }

        return [
            ...$base,
            'status' => DaConvertBatchItemStatus::Queued,
        ];
    }

    private function isAlreadyBusy(Service $service): bool
    {
        $status = (string) ($service->service_meta['da_convert']['status'] ?? '');

        if (in_array($status, ['queued', 'running', 'completed'], true)) {
            return true;
        }

        return $service->provisioningDriver() === 'container';
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, Service>
     */
    public function materializeSelectedAccounts(User $reseller, array $keys): Collection
    {
        $eligible = $this->eligibleAccounts($reseller)->keyBy('key');
        $services = collect();

        foreach ($keys as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            if (preg_match('/^service:(\d+)$/', $key, $match)) {
                $service = $this->eligibleServices($reseller)->first(
                    fn (Service $row): bool => (int) $row->id === (int) $match[1]
                );
                if ($service) {
                    $services->push($service);
                }

                continue;
            }

            if (! preg_match('/^da:([a-z0-9._-]+)$/i', $key, $match)) {
                continue;
            }

            $account = $eligible->get($key);
            if (is_array($account) && $account['service'] instanceof Service) {
                $services->push($account['service']);

                continue;
            }

            $username = strtolower($match[1]);
            $linked = $this->linker->linkForOfframp($reseller, $username);
            if ($linked['created_customer'] ?? false) {
                $domain = strtolower((string) ($linked['service']->service_meta['domain'] ?? $account['domain'] ?? ''));
                if ($domain !== '') {
                    try {
                        $this->mailcow->ensureInfoMailbox($domain, null);
                    } catch (\Throwable $e) {
                        Log::info('Off-ramp info@ inbox was not created yet', [
                            'username' => $username,
                            'domain' => $domain,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $this->assignOperatorInboxEmail($linked['customer'], $domain);
                }
            }
            $services->push($linked['service']);
        }

        return $services->unique('id')->values();
    }

    public function customerNameFromDomain(string $domain, string $username = ''): string
    {
        return $this->linker->customerNameFromDomain($domain, $username);
    }

    public function shouldHideFromOfframp(
        User $reseller,
        string $username,
        ?string $hostname,
        ?Collection $managed = null,
    ): bool {
        $matches = $this->matchingManagedServices($reseller, $username, $hostname, $managed);
        if ($matches->isEmpty()) {
            return false;
        }

        $leftDa = $matches->contains(function (Service $service): bool {
            if ($service->provisioningDriver() === 'container') {
                return true;
            }
            $status = (string) ($service->service_meta['da_convert']['status'] ?? '');

            return in_array($status, ['queued', 'running', 'completed'], true);
        });

        if (! $leftDa) {
            return false;
        }

        $host = strtolower(trim((string) $hostname));
        if ($host === '') {
            $host = strtolower((string) ($matches->first()?->attachedDomainName() ?: $matches->first()?->name ?: ''));
        }

        if ($host !== '' && $this->hostnameHasActiveCloudflareNs($reseller, $host)) {
            return true;
        }

        return $matches->contains(
            fn (Service $service): bool => $service->provisioningDriver() === 'container'
                && $service->containerDeployment !== null
        );
    }

    public function hostnameHasActiveCloudflareNs(User $reseller, string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return false;
        }

        $domains = Domain::query()
            ->where(function ($query) use ($reseller) {
                $query->where('user_id', $reseller->id)
                    ->orWhereHas('user', fn ($user) => $user->where('reseller_id', $reseller->id));
            })
            ->get();

        $domain = $domains->first(function (Domain $row) use ($hostname): bool {
            $fqdn = strtolower($row->fqdn());

            return $fqdn === $hostname || str_ends_with($hostname, '.'.$fqdn);
        });

        if (! $domain || ! $domain->cloudflare_dns_enabled || blank($domain->cloudflare_zone_id)) {
            return false;
        }

        $ns = strtolower(implode(' ', array_filter([
            $domain->nameserver_1,
            $domain->nameserver_2,
            $domain->nameserver_3,
            $domain->nameserver_4,
        ])));

        return $this->cloudflare->usesCloudflareDns($domain)
            || str_contains($ns, 'cloudflare.com')
            || str_contains($ns, 'ns.talksasa.');
    }

    /**
     * @return Collection<int, Service>
     */
    private function matchingManagedServices(
        User $reseller,
        string $username,
        ?string $hostname,
        ?Collection $managed = null,
    ): Collection {
        $managed ??= $this->scope->managedServicesQuery($reseller)
            ->with(['containerDeployment', 'user'])
            ->get();

        $username = strtolower(trim($username));
        $hostname = strtolower(trim((string) $hostname));

        return $managed->filter(function (Service $service) use ($username, $hostname): bool {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $serviceUser = strtolower(trim((string) ($meta['username'] ?? $service->external_reference ?? '')));
            $serviceHost = strtolower(trim((string) ($meta['domain'] ?? $service->attachedDomainName() ?? $service->name ?? '')));

            if ($username !== '' && $serviceUser === $username) {
                return true;
            }

            return $hostname !== '' && $serviceHost === $hostname;
        })->values();
    }

    /**
     * @return list<array{username: string, domain: ?string, package: ?string, email: ?string, name: ?string, suspended: bool}>
     */
    private function liveDirectAdminEntries(User $reseller): array
    {
        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            return [];
        }

        $da = $this->resellerDirectAdmin->directAdmin($reseller);
        if (! $da) {
            return [];
        }

        $usernames = $da->listUsersOwnedByReseller((string) $reseller->directadmin_username) ?? [];
        $entries = [];
        foreach ($usernames as $username) {
            $username = strtolower(trim((string) $username));
            if ($username === '') {
                continue;
            }
            $entry = $da->getAccountDirectoryEntry($username);
            $entries[] = $entry ?? [
                'username' => $username,
                'domain' => null,
                'package' => null,
                'email' => null,
                'name' => null,
                'suspended' => false,
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountFromService(Service $service, string $key, string $username, ?string $domain): array
    {
        $profile = ($domain && str_contains($domain, '.'))
            ? $this->linker->offrampCustomerProfile($domain, $username)
            : ['name' => $service->user?->name, 'email' => $service->user?->email];

        return [
            'key' => $key,
            'service' => $service,
            'da_username' => $username !== '' ? $username : null,
            'domain' => $domain,
            'package' => $this->packages->serviceDaPackageName($service) ?: null,
            'customer' => $service->user,
            'on_platform' => true,
            'will_create_customer' => false,
            'proposed_name' => $profile['name'] ?? $service->user?->name,
            'proposed_email' => $profile['email'] ?? $service->user?->email,
            'node' => $service->node,
            'snapshot' => $service->latestDaAccountSnapshot,
            'convert_status' => $service->service_meta['da_convert']['status'] ?? 'on DirectAdmin',
            'suspended_on_da' => false,
        ];
    }

    /**
     * @param  array{username: string, domain: ?string, package: ?string, email: ?string, name: ?string, suspended: bool}  $entry
     * @return array<string, mixed>
     */
    private function accountFromDaEntry(User $reseller, array $entry, string $key): array
    {
        $username = strtolower((string) ($entry['username'] ?? ''));
        $domain = strtolower(trim((string) ($entry['domain'] ?? '')));
        $domain = $domain !== '' ? $domain : null;
        $profile = $domain
            ? $this->linker->offrampCustomerProfile($domain, $username)
            : ['name' => $this->linker->customerNameFromDomain($username.'.example', $username), 'email' => null];

        return [
            'key' => $key,
            'service' => null,
            'da_username' => $username,
            'domain' => $domain,
            'package' => $entry['package'] ?? null,
            'customer' => null,
            'on_platform' => false,
            'will_create_customer' => true,
            'proposed_name' => $profile['name'],
            'proposed_email' => $profile['email'],
            'node' => $this->resellerDirectAdmin->resolveNode($reseller),
            'snapshot' => null,
            'convert_status' => 'not on platform',
            'suspended_on_da' => (bool) ($entry['suspended'] ?? false),
        ];
    }

    private function serviceDaUsername(Service $service): string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        return strtolower(trim((string) ($meta['username'] ?? $service->external_reference ?? '')));
    }

    private function serviceHostname(Service $service): ?string
    {
        $host = $service->attachedDomainName()
            ?: (is_string($service->service_meta['domain'] ?? null) ? $service->service_meta['domain'] : null)
            ?: $service->name;

        $host = strtolower(trim((string) $host));

        return $host !== '' ? $host : null;
    }

    private function assignOperatorInboxEmail(User $customer, string $domain): void
    {
        $email = $this->linker->infoInboxEmail($domain);
        $taken = User::query()
            ->whereKeyNot($customer->id)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->exists();
        if ($taken) {
            return;
        }

        $settings = is_array($customer->settings) ? $customer->settings : [];
        $settings['operator_inbox'] = $email;
        $customer->update([
            'email' => $email,
            'settings' => $settings,
        ]);
    }

    private function markServiceQueued(
        Service $service,
        Product $product,
        ?string $stack,
        ?ResellerProduct $listing = null,
        ?float $retail = null,
    ): void {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ($listing) {
            $meta['reseller_product_id'] = $listing->id;
        }
        $meta['da_convert'] = [
            'status' => 'queued',
            'mode' => 'convert_in_place',
            'queued_at' => now()->toIso8601String(),
            'target_product_id' => $product->id,
            'target_product_name' => $product->name,
            'reseller_product_id' => $listing?->id,
            'reseller_product_name' => $listing?->name,
            'renewal_due_date' => optional($service->next_due_date)->toDateString(),
            'stack' => $stack,
            'quiet' => true,
            'no_invoice' => true,
            'keep_reseller_price' => true,
        ];

        $updates = ['service_meta' => $meta];
        if ($listing) {
            $updates['reseller_product_id'] = $listing->id;
        }
        if ($service->custom_price === null && $retail !== null && $retail > 0) {
            $updates['custom_price'] = $retail;
        }

        $service->update($updates);
    }

    /**
     * Live operator payload for the off-ramp terminal: convert steps + mail pull.
     *
     * @return array{
     *     is_active: bool,
     *     active_count: int,
     *     items: list<array<string, mixed>>,
     *     current: ?array<string, mixed>
     * }
     */
    public function operatorProgress(User $reseller): array
    {
        $batches = DaConvertBatch::query()
            ->where('reseller_user_id', $reseller->id)
            ->with(['items.service.user'])
            ->latest()
            ->limit(4)
            ->get();

        $items = [];
        foreach ($batches as $batch) {
            foreach ($batch->items as $item) {
                $service = $item->service?->fresh();
                if (! $service) {
                    continue;
                }

                $view = $this->mailPull->operatorView($service);
                $itemActive = $item->status?->isActiveConvert() || (bool) $view['is_active'];
                $convertStatus = (string) ($service->service_meta['da_convert']['status'] ?? '');
                $keepQuiet = $itemActive
                    || in_array($convertStatus, ['queued', 'running', 'completed', 'failed'], true)
                    || in_array($item->status, [
                        DaConvertBatchItemStatus::Queued,
                        DaConvertBatchItemStatus::Converting,
                        DaConvertBatchItemStatus::Failed,
                    ], true);

                if (! $keepQuiet && $batch->id !== $batches->first()?->id) {
                    continue;
                }

                $items[] = [
                    'batch_id' => $batch->id,
                    'item_id' => $item->id,
                    'service_id' => $service->id,
                    'hostname' => $item->hostname ?: $service->name,
                    'customer' => $service->user?->name,
                    'item_status' => $item->status?->value,
                    'item_label' => $item->status?->label(),
                    'service_url' => route('admin.services.show', $service),
                    'wizard_url' => route('admin.services.migrate-to-container', $service),
                    ...$view,
                    'is_active' => $itemActive,
                ];
            }
        }

        $active = collect($items)->firstWhere('is_active', true) ?? ($items[0] ?? null);

        return [
            'is_active' => collect($items)->contains(fn (array $row): bool => (bool) ($row['is_active'] ?? false)),
            'active_count' => collect($items)->where('is_active', true)->count(),
            'items' => $items,
            'current' => $active,
        ];
    }
}
