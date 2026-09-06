<?php

namespace App\Services\Provisioning;

use App\Enums\DaConvertBatchItemStatus;
use App\Enums\DaConvertBatchStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\ResellerScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * @return Collection<int, Service>
     */
    public function eligibleServices(User $reseller): Collection
    {
        return $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product', 'node', 'latestDaAccountSnapshot'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Service $service): bool => $service->isSharedHosting())
            ->values();
    }

    /**
     * @param  list<int>  $serviceIds
     */
    public function queueBatch(
        User $reseller,
        User $admin,
        array $serviceIds,
        Product $product,
        ?Product $emailProduct,
        bool $acknowledgeMailPull,
        bool $acknowledgeAddonSites,
    ): DaConvertBatch {
        if (! $reseller->is_reseller) {
            throw new InvalidArgumentException('Only a reseller book can be converted in batch.');
        }

        if ($product && ($product->type !== 'container_hosting' || ! $product->is_active)) {
            throw new InvalidArgumentException('Select an active Application Hosting product as the fallback container size.');
        }

        $wanted = array_values(array_unique(array_map('intval', $serviceIds)));
        $services = $this->eligibleServices($reseller)
            ->filter(fn (Service $service): bool => in_array((int) $service->id, $wanted, true))
            ->values();

        if ($services->isEmpty()) {
            throw new InvalidArgumentException('Select at least one DirectAdmin service that belongs to this reseller.');
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
