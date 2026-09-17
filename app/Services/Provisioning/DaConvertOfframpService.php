<?php

namespace App\Services\Provisioning;

use App\Enums\DaConvertBatchItemStatus;
use App\Enums\DaConvertBatchStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\ResellerComputeUsageService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerHostedAccountLinkService;
use App\Services\ResellerProvisionProductResolver;
use App\Services\ResellerScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DaConvertOfframpService
{
    public const QUEUE = 'da-convert';

    public const LIVE_DA_USER_LIST_TTL = 180;

    public const LIVE_DA_ENTRY_TTL = 300;

    /** @var array<int, Collection<int, Domain>> */
    private array $resellerDomainIndex = [];

    /** @var array<int, DaConvertBatchItem|null>|null */
    private ?array $latestItemsByServiceId = null;

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
    public function eligibleAccounts(User $reseller, bool $refreshLiveDa = false): Collection
    {
        $managed = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product', 'node', 'latestDaAccountSnapshot', 'containerDeployment'])
            ->orderBy('id')
            ->get();

        $rows = [];
        $skipLiveConfig = [];

        foreach ($managed as $service) {
            $username = $this->serviceDaUsername($service);
            if ($username !== '') {
                $skipLiveConfig[$username] = true;
            }

            if (! $service->isSharedHosting()) {
                continue;
            }

            $domain = $this->serviceHostname($service);
            if ($this->shouldHideFromOfframp($reseller, $username, $domain, $managed)) {
                continue;
            }

            $key = $username !== '' ? 'da:'.$username : 'service:'.$service->id;
            $rows[$key] = $this->accountFromService($service, $key, $username, $domain);
        }

        foreach ($this->liveDirectAdminEntries($reseller, array_keys($skipLiveConfig), $refreshLiveDa) as $entry) {
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
    /**
     * Queue converts for a reseller's DirectAdmin accounts. The actor is the
     * admin running the platform board or the reseller running their own.
     * A fallback size is optional when every account maps to a plan; $plans
     * pins an account key to one of the reseller's own container listings.
     *
     * @param  list<int>  $serviceIds
     * @param  list<string>  $accountKeys
     * @param  array<string, int>  $plans  account key => reseller_product_id
     * @param  ResellerProduct|null  $fallbackListing  the reseller plan behind $product, so its specs and price apply
     */
    public function queueBatch(
        User $reseller,
        User $actor,
        array $serviceIds,
        ?Product $product,
        ?Product $emailProduct,
        bool $acknowledgeMailPull,
        bool $acknowledgeAddonSites,
        array $accountKeys = [],
        array $plans = [],
        ?ResellerProduct $fallbackListing = null,
    ): DaConvertBatch {
        if (! $reseller->is_reseller) {
            throw new InvalidArgumentException('Only a reseller book can be converted in batch.');
        }

        if ($product && ($product->type !== 'container_hosting' || (! $product->is_active && ! $this->isShellEngine($product)))) {
            throw new InvalidArgumentException('Select an active Application Hosting product as the fallback container size.');
        }

        $chosenListings = $this->resolvePlanChoices($reseller, $plans);

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

        $services = $services
            ->map(function (Service $service): Service {
                $this->prepareServiceForRetry($service);

                return $service->fresh(['user', 'product', 'node', 'latestDaAccountSnapshot', 'containerDeployment']) ?? $service;
            })
            ->values();

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

            $chosen = $chosenListings[$this->accountKeyForService($row['service'])]
                ?? $chosenListings['service:'.$row['service']->id]
                ?? null;
            $mapped = $this->packages->resolveForService($reseller, $row['service'], $product, $chosen);
            if (! $mapped['listing'] && $fallbackListing) {
                // Explicit choice, then the DirectAdmin package mapping, then the fallback plan.
                $mapped = $this->packages->resolveForService($reseller, $row['service'], $product, $fallbackListing);
            }
            $prepared[$index]['listing'] = $mapped['listing'];
            $prepared[$index]['engine'] = $mapped['engine'];
            $prepared[$index]['limits'] = $mapped['limits'];
            $prepared[$index]['retail'] = $mapped['retail'];
            $prepared[$index]['da_package'] = $mapped['da_package'];

            if (! $mapped['engine']) {
                $prepared[$index]['status'] = DaConvertBatchItemStatus::Blocked;
                $prepared[$index]['error'] = 'Choose an Application Hosting plan for this account, or import the DirectAdmin packages so each account maps to one.';
                $prepared[$index]['blockers'][] = $prepared[$index]['error'];
            }
        }

        $prepared = $this->blockWhatThePoolCannotHold($reseller, $prepared);

        $toQueue = array_values(array_filter(
            $prepared,
            fn (array $row): bool => $row['status'] === DaConvertBatchItemStatus::Queued
        ));

        foreach ($toQueue as $row) {
            $stack = (string) ($row['detected_stack'] ?: 'php');
            $sites = 1 + (int) ($row['addon_site_count'] ?? 0);
            $share = $sites > 1 ? round(1 / $sites, 4) : 1.0;
            $engine = $row['engine'] ?? $product;
            $this->convert->assertHostCapacityForConvert($row['service'], $engine, $stack, $share, $row['limits'] ?? null);
        }

        $batch = DB::transaction(function () use (
            $reseller,
            $actor,
            $product,
            $emailProduct,
            $acknowledgeMailPull,
            $acknowledgeAddonSites,
            $prepared,
        ): DaConvertBatch {
            $hasQueued = collect($prepared)->contains(
                fn (array $row): bool => $row['status'] === DaConvertBatchItemStatus::Queued
            );
            $firstEngine = collect($prepared)->first(fn (array $row): bool => ($row['engine'] ?? null) instanceof Product)['engine'] ?? null;

            $batch = DaConvertBatch::query()->create([
                'reseller_user_id' => $reseller->id,
                'admin_user_id' => $actor->id,
                'product_id' => $product?->id ?? $firstEngine?->id ?? app(ResellerProvisionProductResolver::class)->shellContainerProduct()->id,
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
                    $this->markServiceQueued($service, $engine, $row['detected_stack'], $listing, $row['retail'] ?? null, $row['limits'] ?? null);
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
                (int) ($item->product_id ?: $product?->id),
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
            'Queued DirectAdmin off-ramp batch #'.$batch->id.' for reseller '.$reseller->name
                .($actor->id === $reseller->id ? ' (queued by the reseller)' : ''),
            $reseller,
            [
                'batch_id' => $batch->id,
                'actor_user_id' => $actor->id,
                'actor_is_reseller' => $actor->id === $reseller->id,
                'service_ids' => $batch->items->pluck('service_id')->all(),
                'queued' => $batch->items->where('status', DaConvertBatchItemStatus::Queued)->count(),
                'blocked' => $batch->items->where('status', DaConvertBatchItemStatus::Blocked)->count(),
                'needs_ack' => $batch->items->where('status', DaConvertBatchItemStatus::NeedsAck)->count(),
            ],
        );

        return $batch->fresh('items.service') ?? $batch;
    }

    /**
     * Live DirectAdmin usernames the reseller owns that no managed service
     * carries yet: the candidates for pointing a mislinked service at the
     * right account.
     *
     * @return list<string>
     */
    public function unlinkedDirectAdminUsernames(User $reseller): array
    {
        $linked = [];
        foreach ($this->scope->managedServicesQuery($reseller)->get(['id', 'service_meta', 'external_reference']) as $service) {
            $username = $this->serviceDaUsername($service);
            if ($username !== '') {
                $linked[] = $username;
            }
        }

        $names = array_map(
            fn (array $entry): string => strtolower((string) ($entry['username'] ?? '')),
            $this->liveDirectAdminEntries($reseller, $linked)
        );
        $names = array_values(array_unique(array_filter($names)));
        sort($names);

        return $names;
    }

    /**
     * DirectAdmin nodes a reseller's account may be pointed at: the node their
     * reseller login is bound to and any node their managed services already use.
     *
     * @return Collection<int, Node>
     */
    public function resellerDirectAdminNodes(User $reseller): Collection
    {
        $ids = $this->scope->managedServicesQuery($reseller)
            ->whereNotNull('node_id')
            ->distinct()
            ->pluck('node_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $bound = $this->resellerDirectAdmin->resolveNode($reseller);
        if ($bound) {
            $ids[] = (int) $bound->id;
        }

        if ($ids === []) {
            return collect();
        }

        return Node::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where('type', 'directadmin')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Point a managed shared-hosting service at the DirectAdmin user it really
     * is, after the DNS snapshot found no such user on its node. The user must
     * exist on the chosen node and be created by this reseller's DirectAdmin
     * login, so a reseller cannot pull another reseller's account onto their
     * book. Then the move is queued again straight away.
     *
     * @return array{ok: bool, message: string, batch: ?DaConvertBatch, item: ?DaConvertBatchItem}
     */
    public function relinkDirectAdminAccount(
        User $reseller,
        User $actor,
        Service $service,
        string $username,
        ?int $nodeId = null,
        bool $retry = true,
    ): array {
        $username = strtolower(trim($username));
        if ($username === '' || ! preg_match('/^[a-z0-9._-]{1,64}$/', $username)) {
            return ['ok' => false, 'message' => 'Enter the DirectAdmin username exactly as DirectAdmin shows it.', 'batch' => null, 'item' => null];
        }

        $managed = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product', 'node'])
            ->get();
        $target = $managed->first(fn (Service $row): bool => (int) $row->id === (int) $service->id);
        if (! $target || ! $target->isSharedHosting()) {
            throw new InvalidArgumentException('That service is not a DirectAdmin account on this reseller\'s book.');
        }
        $service = $target;

        if ($this->isAlreadyBusy($service) && ! $this->isFailedConvert($service)) {
            return ['ok' => false, 'message' => 'This account is converting or has already moved; its DirectAdmin login cannot be changed now.', 'batch' => null, 'item' => null];
        }

        $holder = $managed->first(
            fn (Service $row): bool => (int) $row->id !== (int) $service->id && $this->serviceDaUsername($row) === $username
        );
        if ($holder) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'DirectAdmin user %s is already linked to service #%d (%s). Retry that row instead, or remove the duplicate.',
                    $username,
                    $holder->id,
                    $this->serviceHostname($holder) ?: $holder->name
                ),
                'batch' => null,
                'item' => null,
            ];
        }

        $nodes = $this->resellerDirectAdminNodes($reseller)->keyBy('id');
        $node = $nodeId ? $nodes->get((int) $nodeId) : ($service->node ?? $this->resellerDirectAdmin->resolveNode($reseller));
        if (! $node || ! $nodes->has((int) $node->id)) {
            return ['ok' => false, 'message' => 'Choose one of your DirectAdmin servers.', 'batch' => null, 'item' => null];
        }

        $verdict = $this->verifyDirectAdminOwnership($reseller, $node, $username);
        if (! $verdict['ok']) {
            return ['ok' => false, 'message' => $verdict['message'], 'batch' => null, 'item' => null];
        }

        $previousUsername = $this->serviceDaUsername($service);
        $previousNodeId = $service->node_id;

        DB::transaction(function () use ($service, $username, $node, $actor, $previousUsername, $previousNodeId, $verdict): void {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['username'] = $username;
            if (filled($verdict['domain'] ?? null) && blank($meta['domain'] ?? null)) {
                $meta['domain'] = $verdict['domain'];
            }
            // Cached account facts from the old login are stale.
            unset($meta['directadmin_account']);
            $history = is_array($meta['da_relink'] ?? null) ? $meta['da_relink'] : [];
            $history[] = [
                'from_username' => $previousUsername !== '' ? $previousUsername : null,
                'from_node_id' => $previousNodeId,
                'to_username' => $username,
                'to_node_id' => $node->id,
                'by_user_id' => $actor->id,
                'at' => now()->toIso8601String(),
            ];
            $meta['da_relink'] = array_slice($history, -10);

            $updates = [
                'service_meta' => $meta,
                'external_reference' => $username,
                'node_id' => $node->id,
            ];

            $credentials = is_string($service->credentials) ? json_decode($service->credentials, true) : null;
            if (is_array($credentials) && array_key_exists('username', $credentials)) {
                $credentials['username'] = $username;
                $updates['credentials'] = json_encode($credentials);
            }

            $service->update($updates);
        });

        AdminActivityService::log(
            'reseller.da_offramp_relink',
            sprintf(
                'Pointed service #%d (%s) at DirectAdmin user %s on %s (was %s on node %s)%s',
                $service->id,
                $this->serviceHostname($service) ?: $service->name,
                $username,
                $node->name,
                $previousUsername !== '' ? $previousUsername : 'unset',
                $previousNodeId ?: 'unset',
                $actor->id === $reseller->id ? ' (by the reseller)' : ''
            ),
            $service,
            [
                'reseller_user_id' => $reseller->id,
                'actor_user_id' => $actor->id,
                'from_username' => $previousUsername,
                'from_node_id' => $previousNodeId,
                'to_username' => $username,
                'to_node_id' => $node->id,
            ]
        );

        $service->refresh();
        $label = $this->serviceHostname($service) ?: $service->name;
        $saved = sprintf('Service #%d now points at DirectAdmin user %s on %s.', $service->id, $username, $node->name);

        if (! $retry) {
            return ['ok' => true, 'message' => $saved, 'batch' => null, 'item' => null];
        }

        $previousItem = $this->latestItemForService($reseller, $service);
        $listing = $previousItem?->reseller_product_id
            ? ResellerProduct::query()->where('reseller_id', $reseller->id)->find($previousItem->reseller_product_id)
            : null;
        $emailProduct = $previousItem?->batch?->email_product_id
            ? Product::query()->find($previousItem->batch->email_product_id)
            : null;

        $batch = $this->queueBatch(
            $reseller,
            $actor,
            [],
            null,
            $emailProduct,
            true,
            true,
            ['da:'.$username],
            [],
            $listing,
        );
        $item = $batch->items->first();

        if ($item?->status?->isActiveConvert()) {
            return ['ok' => true, 'message' => $saved.' Retrying '.$label.'; the log follows each step.', 'batch' => $batch, 'item' => $item];
        }

        return [
            'ok' => false,
            'message' => $saved.' The retry did not queue: '.($item?->error ?: 'no plan is mapped for it.'),
            'batch' => $batch,
            'item' => $item,
        ];
    }

    /**
     * The user must exist on the node and have been created by this reseller's
     * DirectAdmin login. Without a bound login there is no way to prove
     * ownership, so the relink is refused rather than guessed.
     *
     * @return array{ok: bool, message: string, domain: ?string}
     */
    private function verifyDirectAdminOwnership(User $reseller, Node $node, string $username): array
    {
        $resellerLogin = strtolower(trim((string) ($reseller->directadmin_username ?? '')));
        if ($resellerLogin === '') {
            return ['ok' => false, 'message' => 'Connect your DirectAdmin reseller login first so we can confirm the account is yours.', 'domain' => null];
        }

        $da = $this->resellerDirectAdmin->adminDirectAdmin($node);
        if (! $da) {
            return ['ok' => false, 'message' => 'DirectAdmin on '.$node->name.' is not reachable from the platform right now.', 'domain' => null];
        }

        $status = $da->getAccountLiveStatus($username);
        $detail = is_array($status['detail'] ?? null) ? $status['detail'] : [];

        return match ($status['live_status'] ?? '') {
            'active', 'suspended' => ($detail['creator'] ?? null) === $resellerLogin
                ? ['ok' => true, 'message' => 'OK', 'domain' => is_string($detail['domain'] ?? null) ? strtolower($detail['domain']) : null]
                : ['ok' => false, 'message' => sprintf('DirectAdmin user %s on %s was not created by your reseller login (%s), so it cannot be linked here.', $username, $node->name, $resellerLogin), 'domain' => null],
            'terminated' => ['ok' => false, 'message' => sprintf('There is no DirectAdmin user %s on %s: DirectAdmin has no user record for that name (the account was deleted, or only its home folder is left). Check the spelling against the list of your accounts, or pick the server the account lives on.', $username, $node->name), 'domain' => null],
            default => ['ok' => false, 'message' => 'Could not read that DirectAdmin user on '.$node->name.': '.(string) ($status['label'] ?? 'unknown error'), 'domain' => null],
        };
    }

    /**
     * Pull a converted account from DirectAdmin again: wipe what the convert
     * built on the container side, put the row back on DirectAdmin, and
     * queue it as a fresh single-account batch through preflight.
     *
     * @return array{ok: bool, message: string, batch: ?DaConvertBatch, item: ?DaConvertBatchItem}
     */
    public function repullFromDirectAdmin(User $reseller, User $actor, Service $service): array
    {
        $managed = $this->scope->managedServicesQuery($reseller)->with(['user', 'product', 'node', 'containerDeployment'])->get();
        $target = $managed->first(fn (Service $row): bool => (int) $row->id === (int) $service->id);
        if (! $target) {
            throw new InvalidArgumentException('That service is not on this reseller\'s book.');
        }
        $service = $target;
        $label = $this->serviceHostname($service) ?: $service->name;

        $previousItem = $this->latestItemForService($reseller, $service);
        $listing = $previousItem?->reseller_product_id
            ? ResellerProduct::query()->where('reseller_id', $reseller->id)->find($previousItem->reseller_product_id)
            : null;
        $emailProduct = $previousItem?->batch?->email_product_id
            ? Product::query()->find($previousItem->batch->email_product_id)
            : null;

        $wiped = app(DaConvertRepullService::class)->wipeForRepull($service, $actor);

        AdminActivityService::log(
            'reseller.da_offramp_repull',
            sprintf(
                'Pulled %s (service #%d) from DirectAdmin again: removed container %s and %d sibling site(s)%s',
                $label,
                $service->id,
                $wiped['container'] ?: 'none',
                $wiped['removed_siblings'],
                $actor->id === $reseller->id ? ' (by the reseller)' : ''
            ),
            $reseller,
            ['service_id' => $service->id, 'actor_user_id' => $actor->id, 'container' => $wiped['container'], 'removed_siblings' => $wiped['removed_siblings']]
        );

        $batch = $this->queueBatch(
            $reseller,
            $actor,
            [],
            null,
            $emailProduct,
            true,
            true,
            [$this->accountKeyForService($service->fresh())],
            [],
            $listing,
        );
        $item = $batch->items->first();
        $wipedNote = 'Removed the old container'.($wiped['removed_siblings'] > 0 ? ' and '.$wiped['removed_siblings'].' sibling site(s)' : '').'.';

        if ($item?->status?->isActiveConvert()) {
            return [
                'ok' => true,
                'message' => $wipedNote.' Pulling '.$label.' from DirectAdmin again; the log follows each step. Cut web DNS again once the new container is ready.',
                'batch' => $batch,
                'item' => $item,
            ];
        }

        return [
            'ok' => false,
            'message' => $wipedNote.' The fresh pull did not queue: '.($item?->error ?: 'no plan is mapped for it.').' The row is back on DirectAdmin; use Retry once that is fixed.',
            'batch' => $batch,
            'item' => $item,
        ];
    }

    /**
     * The console's Restart button: a queued item no worker ever started, a
     * converting one that stopped heartbeating, or one that ended blocked or
     * failed. A convert that is still moving keeps its button hidden.
     *
     * @param  array<string, mixed>  $view  the operator view for the item's service
     */
    public function canRestartItem(DaConvertBatchItem $item, array $view): bool
    {
        if ($view['is_active'] ?? false) {
            return false;
        }

        return in_array($item->status, [
            DaConvertBatchItemStatus::Queued,
            DaConvertBatchItemStatus::Converting,
            DaConvertBatchItemStatus::Failed,
            DaConvertBatchItemStatus::Blocked,
            DaConvertBatchItemStatus::NeedsAck,
        ], true);
    }

    /**
     * Restart one batch item from the console. A stale queued or converting
     * item is re-dispatched on the same batch row with the options it was
     * queued with; a blocked, failed or unacknowledged item goes back through
     * preflight as a fresh single-account batch, the way the board's Retry does.
     *
     * @return array{ok: bool, message: string, batch_id: ?int, item_id: ?int}
     */
    public function restartItem(User $reseller, User $actor, DaConvertBatchItem $item): array
    {
        $item->loadMissing('batch', 'service');
        if ((int) ($item->batch?->reseller_user_id ?? 0) !== (int) $reseller->id) {
            throw new InvalidArgumentException('That convert does not belong to this reseller.');
        }

        $service = $item->service?->fresh(['user', 'product', 'node']);
        if (! $service) {
            return ['ok' => false, 'message' => 'The service behind this convert no longer exists.', 'batch_id' => null, 'item_id' => null];
        }

        $view = $this->mailPull->operatorView($service);
        if (! $this->canRestartItem($item, $view)) {
            $message = $item->status?->isActiveConvert() || ($view['is_active'] ?? false)
                ? 'This convert is still running. Watch the log; Restart appears once it stalls or stops.'
                : 'This account has already moved off DirectAdmin; there is nothing to restart.';

            return ['ok' => false, 'message' => $message, 'batch_id' => $item->da_convert_batch_id, 'item_id' => $item->id];
        }

        if ($item->status?->isActiveConvert()) {
            return $this->requeueStaleItem($reseller, $actor, $item, $service);
        }

        $batch = $item->batch;
        $emailProduct = $batch?->email_product_id ? Product::query()->find($batch->email_product_id) : null;
        $listing = $item->reseller_product_id
            ? ResellerProduct::query()->where('reseller_id', $reseller->id)->find($item->reseller_product_id)
            : null;

        $fresh = $this->queueBatch(
            $reseller,
            $actor,
            [],
            null,
            $emailProduct,
            true,
            true,
            [$this->accountKeyForService($service)],
            [],
            $listing,
        );
        $new = $fresh->items->first();
        $label = $new?->hostname ?: $item->hostname ?: $service->name;

        if ($new?->status?->isActiveConvert()) {
            return ['ok' => true, 'message' => 'Retrying '.$label.' from preflight. The log follows each step.', 'batch_id' => $fresh->id, 'item_id' => $new->id];
        }

        return [
            'ok' => false,
            'message' => $new?->error ?: ('Could not retry '.$label.'.'),
            'batch_id' => $fresh->id,
            'item_id' => $new?->id,
        ];
    }

    /**
     * @return array{ok: bool, message: string, batch_id: ?int, item_id: ?int}
     */
    private function requeueStaleItem(User $reseller, User $actor, DaConvertBatchItem $item, Service $service): array
    {
        $batch = $item->batch;
        $product = Product::query()->find((int) ($item->product_id ?: $batch?->product_id));
        if (! $product) {
            return [
                'ok' => false,
                'message' => 'The Application Hosting engine this convert was queued with no longer exists. Retry it from the board so a plan is chosen again.',
                'batch_id' => $batch?->id,
                'item_id' => $item->id,
            ];
        }

        $progress = app(DaConvertProgress::class);
        $convert = $progress->convertMeta($service);
        $label = $item->hostname ?: $service->name;

        // A converting run that died may already have switched the row to container.
        app(DaConvertRetryService::class)->restoreDirectAdminRow($service);
        $released = $progress->releaseStaleNodeLock($service);

        if ($convert === [] || empty($convert['target_product_id'])) {
            $listing = $item->reseller_product_id
                ? ResellerProduct::query()->where('reseller_id', $reseller->id)->find($item->reseller_product_id)
                : null;
            $this->markServiceQueued($service, $product, $item->detected_stack, $listing);
        } else {
            $progress->merge($service, [
                'status' => 'queued',
                'mode' => DaConvertProgress::MODE_PRIMARY,
                'queued_at' => now()->toIso8601String(),
                'retried_at' => now()->toIso8601String(),
                'attempt' => (int) ($convert['attempt'] ?? 0) + 1,
                'last_error' => $convert['error'] ?? null,
                'error' => null,
                'steps' => [],
                'phase' => 'queued',
                'phase_fraction' => 0.0,
                'phase_detail' => '',
                'completed_at' => null,
                'failed_at' => null,
                'target_product_id' => $product->id,
                'target_product_name' => $product->name,
            ]);
        }

        $item->update(['status' => DaConvertBatchItemStatus::Queued, 'error' => null]);

        ConvertDirectAdminServiceToContainerJob::dispatch(
            (int) $service->id,
            (int) $product->id,
            (bool) ($batch?->acknowledge_mail_pull ?? true),
            null,
            (bool) ($batch?->acknowledge_addon_sites ?? true),
            $batch?->email_product_id ? (int) $batch->email_product_id : null,
            (int) $item->id,
        )->onQueue(self::QUEUE);

        $batch?->update(['status' => DaConvertBatchStatus::Converting]);

        AdminActivityService::log(
            'reseller.da_offramp_restart',
            'Restarted DirectAdmin off-ramp convert for '.$label.' (batch #'.$batch?->id.', item #'.$item->id.')'
                .($actor->id === $reseller->id ? ' (restarted by the reseller)' : ''),
            $reseller,
            [
                'batch_id' => $batch?->id,
                'item_id' => $item->id,
                'service_id' => $service->id,
                'actor_user_id' => $actor->id,
                'released_node_lock' => $released,
            ]
        );

        return [
            'ok' => true,
            'message' => 'Restarted '.$label.'.'.($released ? ' A stale node lock from a crashed run was cleared.' : '').' The log follows each step.',
            'batch_id' => $batch?->id,
            'item_id' => $item->id,
        ];
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

    public function isFailedConvert(Service $service): bool
    {
        if ($this->convertLooksComplete($service)) {
            return false;
        }

        return (string) ($service->service_meta['da_convert']['status'] ?? '') === 'failed';
    }

    /**
     * A later batch can record a Failed item (SSH listing flake) after the
     * primary site already landed on a running container.
     */
    public function convertLooksComplete(Service $service): bool
    {
        $service->loadMissing('containerDeployment');
        $running = $service->containerDeployment
            && in_array((string) $service->containerDeployment->status, ['running', 'active'], true);
        if (! $running || $service->provisioningDriver() !== 'container') {
            return false;
        }

        $convert = is_array($service->service_meta['da_convert'] ?? null)
            ? $service->service_meta['da_convert']
            : [];
        $status = (string) ($convert['status'] ?? '');
        if ($status === 'completed' || filled($convert['completed_at'] ?? null)) {
            return true;
        }

        foreach (is_array($convert['steps'] ?? null) ? $convert['steps'] : [] as $step) {
            if (str_contains((string) $step, 'Convert complete.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A failed in-place convert often already switched the service to container.
     * Put the billing row back on DirectAdmin so convertInPlace can run again.
     */
    public function prepareServiceForRetry(Service $service): void
    {
        if (! $this->isFailedConvert($service) && ! $this->containerConvertNeedsRetry($service)) {
            return;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $convert = is_array($meta['da_convert'] ?? null) ? $meta['da_convert'] : [];

        $convert['retried_at'] = now()->toIso8601String();
        $convert['last_error'] = $convert['error'] ?? null;
        unset($convert['status'], $convert['error']);
        $meta['da_convert'] = $convert;
        $service->update(['service_meta' => $meta]);

        // Same restore the per-service "Retry convert" uses, so a batch retry
        // also lands on the rows the first attempt created.
        app(DaConvertRetryService::class)->restoreDirectAdminRow($service);
    }

    private function containerConvertNeedsRetry(Service $service): bool
    {
        $status = (string) ($service->service_meta['da_convert']['status'] ?? '');
        $legacy = $service->service_meta['da_legacy'] ?? null;

        return $service->provisioningDriver() === 'container'
            && $status !== 'completed'
            && $status !== 'queued'
            && $status !== 'running'
            && is_array($legacy)
            && ! $this->convertLooksComplete($service);
    }

    private function isAlreadyBusy(Service $service): bool
    {
        $status = (string) ($service->service_meta['da_convert']['status'] ?? '');

        if ($this->isFailedConvert($service)) {
            return false;
        }

        if (in_array($status, ['queued', 'running', 'completed'], true)) {
            return true;
        }

        return $service->provisioningDriver() === 'container' && ! $this->containerConvertNeedsRetry($service);
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
            $existing = $this->matchingManagedServices($reseller, $username, null)->first();
            if ($existing) {
                $services->push($existing);

                continue;
            }

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

        if ($matches->contains(fn (Service $service): bool => $this->isFailedConvert($service))) {
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

        $domains = $this->domainsForReseller($reseller);

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

    public function forgetLiveDirectAdminCache(User $reseller): void
    {
        $generation = $this->liveDaCacheGeneration($reseller);
        Cache::forget($this->liveDaUserListCacheKey($reseller, $generation));
        Cache::forever($this->liveDaCacheGenerationKey($reseller), $generation + 1);
    }

    /**
     * @param  list<string>  $skipUsernames
     * @return list<array{username: string, domain: ?string, package: ?string, email: ?string, name: ?string, suspended: bool}>
     */
    private function liveDirectAdminEntries(User $reseller, array $skipUsernames = [], bool $refresh = false): array
    {
        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            return [];
        }

        $da = $this->resellerDirectAdmin->directAdmin($reseller);
        if (! $da) {
            return [];
        }

        if ($refresh) {
            $this->forgetLiveDirectAdminCache($reseller);
        }

        $skip = [];
        foreach ($skipUsernames as $username) {
            $username = strtolower(trim((string) $username));
            if ($username !== '') {
                $skip[$username] = true;
            }
        }

        $generation = $this->liveDaCacheGeneration($reseller);
        $usernames = Cache::remember(
            $this->liveDaUserListCacheKey($reseller, $generation),
            self::LIVE_DA_USER_LIST_TTL,
            fn (): array => array_values(array_filter(array_map(
                static fn (mixed $username): string => strtolower(trim((string) $username)),
                $da->listUsersOwnedByReseller((string) $reseller->directadmin_username) ?? []
            )))
        );

        $needed = [];
        foreach ($usernames as $username) {
            if ($username === '' || isset($skip[$username])) {
                continue;
            }
            $needed[] = $username;
        }

        $entries = [];
        $missing = [];
        foreach ($needed as $username) {
            $cached = Cache::get($this->liveDaEntryCacheKey($reseller, $generation, $username));
            if (is_array($cached) && ($cached['username'] ?? '') === $username) {
                $entries[] = $cached;

                continue;
            }
            $missing[] = $username;
        }

        foreach ($da->getAccountDirectoryEntries($missing) as $entry) {
            $username = strtolower(trim((string) ($entry['username'] ?? '')));
            if ($username === '') {
                continue;
            }
            Cache::put($this->liveDaEntryCacheKey($reseller, $generation, $username), $entry, self::LIVE_DA_ENTRY_TTL);
            $entries[] = $entry;
            $missing = array_values(array_filter($missing, fn (string $row): bool => $row !== $username));
        }

        foreach ($missing as $username) {
            $entries[] = [
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

    private function liveDaCacheGeneration(User $reseller): int
    {
        return max(1, (int) Cache::get($this->liveDaCacheGenerationKey($reseller), 1));
    }

    private function liveDaCacheGenerationKey(User $reseller): string
    {
        return 'da-offramp.gen.'.$reseller->id;
    }

    private function liveDaUserListCacheKey(User $reseller, int $generation): string
    {
        return 'da-offramp.users.'.$reseller->id.'.'.$generation;
    }

    private function liveDaEntryCacheKey(User $reseller, int $generation, string $username): string
    {
        return 'da-offramp.entry.'.$reseller->id.'.'.$generation.'.'.$username;
    }

    /**
     * @return Collection<int, Domain>
     */
    private function domainsForReseller(User $reseller): Collection
    {
        $id = (int) $reseller->id;
        if (! isset($this->resellerDomainIndex[$id])) {
            $this->resellerDomainIndex[$id] = Domain::query()
                ->where(function ($query) use ($reseller) {
                    $query->where('user_id', $reseller->id)
                        ->orWhereHas('user', fn ($user) => $user->where('reseller_id', $reseller->id));
                })
                ->get();
        }

        return $this->resellerDomainIndex[$id];
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
        ?array $limits = null,
    ): void {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ($listing) {
            $meta['reseller_product_id'] = $listing->id;
        }
        if (is_array($limits) && $limits !== []) {
            // The plan's specs size the container; the shell engine has none.
            $meta['reseller_catalog_limits'] = $limits;
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
     * One operator list: DA users still to convert, plus failed / in-flight / waiting-DNS accounts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function boardAccounts(User $reseller, bool $refreshLiveDa = false): Collection
    {
        $rows = $this->eligibleAccounts($reseller, $refreshLiveDa)->keyBy('key');

        foreach ($this->openConvertItems($reseller) as $item) {
            $service = $item->service;
            if (! $service) {
                continue;
            }

            $username = $this->serviceDaUsername($service);
            $key = $username !== '' ? 'da:'.$username : 'service:'.$service->id;
            if (isset($rows[$key])) {
                continue;
            }

            $rows[$key] = $this->accountFromService(
                $service,
                $key,
                $username,
                $this->serviceHostname($service)
            );
        }

        $this->primeLatestConvertItems($reseller, $rows);

        return $rows
            ->map(fn (array $account): array => $this->presentBoardAccount($reseller, $account))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    public function presentBoardAccount(User $reseller, array $account): array
    {
        $service = $account['service'] instanceof Service
            ? $account['service']->loadMissing(['user', 'product', 'node', 'latestDaAccountSnapshot', 'containerDeployment'])
            : null;
        $item = $service ? $this->latestItemForService($reseller, $service) : null;
        $convert = is_array($service?->service_meta['da_convert'] ?? null)
            ? $service->service_meta['da_convert']
            : [];
        $convertStatus = (string) ($convert['status'] ?? '');
        $steps = is_array($convert['steps'] ?? null) ? $convert['steps'] : [];
        $step = $steps !== [] ? (string) end($steps) : null;
        $complete = $service ? $this->convertLooksComplete($service) : false;
        $error = $complete
            ? null
            : ($item?->error ?: (isset($convert['error']) ? (string) $convert['error'] : null));
        $deployment = $service?->containerDeployment;
        $container = 'none';
        if ($deployment) {
            $container = in_array((string) $deployment->status, ['running', 'active'], true)
                ? 'running'
                : (string) $deployment->status;
        } elseif (in_array($convertStatus, ['queued', 'running'], true)) {
            $container = 'creating';
        }

        $board = $this->boardStatus($convertStatus, $item, $container, $error, $step, $complete);

        return array_merge($account, [
            'service_id' => $service?->id,
            'status' => $board['key'],
            'status_label' => $board['label'],
            'step' => $step,
            'error' => $error,
            'container' => $container,
            'container_label' => match ($container) {
                'none' => 'No container',
                'creating' => 'Creating container',
                'deploying' => 'Creating container',
                'running' => 'Container running',
                default => 'Container '.$container,
            },
            'can_queue' => $board['can_queue'],
            'can_retry' => $board['can_retry'],
            'can_cut_dns' => $board['can_cut_dns'],
            'cutover_batch_id' => $item?->da_convert_batch_id,
            'cutover_item_id' => $item?->id,
            'percent' => $board['percent'],
            'convert_status' => $board['label'],
            'security' => $this->securitySummary($service),
            'can_relink' => $service !== null
                && $service->isSharedHosting()
                && in_array($board['key'], ['blocked', 'failed', 'ready'], true),
            'can_restart' => $service !== null && $item !== null && $this->itemLooksStalled($item, $service),
            'can_repull' => $service !== null && $complete && app(DaConvertRepullService::class)->assess($service)['ok'],
            'sibling_count' => $service !== null && $complete ? app(DaConvertProgress::class)->siblingServices($service)->count() : 0,
            'node_id' => $service?->node_id,
        ]);
    }

    /**
     * A queued or converting item whose convert has no sign of life: never
     * started within the queue grace period, or stopped heartbeating. The
     * board shows Restart for these; restartItem re-checks before acting.
     */
    private function itemLooksStalled(DaConvertBatchItem $item, Service $service): bool
    {
        if (! $item->status?->isActiveConvert()) {
            return false;
        }

        $progress = app(DaConvertProgress::class);
        $convert = $progress->convertMeta($service);

        return ! $progress->isActive($convert)
            || $progress->queuedButNotStarting($convert, $service)
            || $progress->looksStuck($convert, $service);
    }

    /**
     * What the post-import scan found on a converted site, for the board.
     *
     * @return array{state: string, label: string, incidents: int, suspicious: int, exposed: int}
     */
    public function securitySummary(?Service $service): array
    {
        $meta = is_array($service?->service_meta) ? $service->service_meta : [];
        $scan = is_array($meta['integrity_scan'] ?? null) ? $meta['integrity_scan'] : null;
        $incidents = count(array_filter((array) ($meta['security_incidents'] ?? []), 'is_array'));

        if ($scan === null) {
            return ['state' => 'pending', 'label' => $service?->containerDeployment ? 'Not scanned yet' : '', 'incidents' => $incidents, 'suspicious' => 0, 'exposed' => 0];
        }

        $suspicious = (int) ($scan['suspicious_count'] ?? 0);
        $exposed = (int) ($scan['exposed_count'] ?? 0);
        $quarantined = (int) ($scan['quarantined_count'] ?? 0);

        if ($suspicious > 0) {
            $label = $suspicious.' file'.($suspicious === 1 ? '' : 's').' to review';
            $state = 'review';
        } elseif ($quarantined > 0 || $incidents > 0) {
            $label = ($quarantined > 0 ? $quarantined.' file'.($quarantined === 1 ? '' : 's').' removed to an incident' : $incidents.' incident'.($incidents === 1 ? '' : 's'));
            $state = 'archived';
        } else {
            $label = 'Clean';
            $state = 'clean';
        }
        if ($exposed > 0) {
            $label .= ' · '.$exposed.' exposed backup'.($exposed === 1 ? '' : 's');
        }
        if (! empty($scan['scan_partial'])) {
            $label .= ' (partial scan)';
        }

        return ['state' => $state, 'label' => $label, 'incidents' => $incidents, 'suspicious' => $suspicious, 'exposed' => $exposed];
    }

    /**
     * The reseller's own listings chosen per account, verified to be theirs.
     *
     * @param  array<string, int>  $plans
     * @return array<string, ResellerProduct>
     */
    private function resolvePlanChoices(User $reseller, array $plans): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $plans))));
        if ($ids === []) {
            return [];
        }
        $listings = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('type', 'container_hosting')
            ->whereIn('id', $ids)
            ->with('adminProduct')
            ->get()
            ->keyBy('id');

        $chosen = [];
        foreach ($plans as $key => $id) {
            $listing = $listings->get((int) $id);
            if (! $listing) {
                throw new InvalidArgumentException('One of the chosen plans is not an Application Hosting plan in your catalogue.');
            }
            $chosen[(string) $key] = $listing;
        }

        return $chosen;
    }

    private function accountKeyForService(Service $service): string
    {
        $username = $this->serviceDaUsername($service);

        return $username !== '' ? 'da:'.$username : 'service:'.$service->id;
    }

    private function isShellEngine(Product $product): bool
    {
        return $product->slug === ResellerProvisionProductResolver::CONTAINER_SHELL_PRODUCT_SLUG;
    }

    /**
     * Every queued account reserves its plan's vCPU and RAM from the
     * reseller's pool. Accounts are taken in order; the first one that no
     * longer fits, and every one after it, is blocked with the shortfall.
     *
     * @param  list<array<string, mixed>>  $prepared
     * @return list<array<string, mixed>>
     */
    private function blockWhatThePoolCannotHold(User $reseller, array $prepared): array
    {
        $compute = app(ResellerComputeUsageService::class);
        if (! $compute->isMetered($reseller)) {
            return $prepared;
        }

        $cpu = 0.0;
        $memory = 0;
        foreach ($prepared as $index => $row) {
            if ($row['status'] !== DaConvertBatchItemStatus::Queued) {
                continue;
            }
            $requested = $compute->requestedAllocationForListing($row['listing'] ?? null, $row['engine'] ?? null);
            $headroom = $compute->checkHeadroom($reseller, $cpu + $requested['cpu_cores'], $memory + $requested['memory_mb']);
            if ($headroom['allowed']) {
                $cpu += $requested['cpu_cores'];
                $memory += $requested['memory_mb'];

                continue;
            }
            $short = [];
            if ($headroom['cpu_over'] > 0) {
                $short[] = rtrim(rtrim(number_format($headroom['cpu_over'], 2), '0'), '.').' vCPU';
            }
            if ($headroom['memory_over'] > 0) {
                $short[] = number_format($headroom['memory_over'] / 1024, 1).' GB RAM';
            }
            $message = 'Your application hosting pool is short by '.implode(' and ', $short).' for this account. Free capacity or upgrade your package, then queue it again.';
            $prepared[$index]['status'] = DaConvertBatchItemStatus::Blocked;
            $prepared[$index]['error'] = $message;
            $prepared[$index]['blockers'][] = $message;
        }

        return $prepared;
    }

    /**
     * @return array{key: string, label: string, can_queue: bool, can_retry: bool, can_cut_dns: bool, percent: int}
     */
    public function boardStatus(
        string $convertStatus,
        ?DaConvertBatchItem $item,
        string $container,
        ?string $error = null,
        ?string $step = null,
        bool $convertComplete = false,
    ): array {
        $itemStatus = $item?->status;

        if ($convertComplete && $container === 'running') {
            if ($itemStatus === DaConvertBatchItemStatus::Done) {
                return [
                    'key' => 'done',
                    'label' => 'Done',
                    'can_queue' => false,
                    'can_retry' => false,
                    'can_cut_dns' => false,
                    'percent' => 100,
                ];
            }

            $waitingMx = $itemStatus === DaConvertBatchItemStatus::WaitingMx
                || (int) ($item?->mailbox_count ?? 0) > 0;

            return [
                'key' => $waitingMx ? 'waiting_mx' : 'waiting_dns',
                'label' => $waitingMx ? 'Waiting on MX' : 'Container ready',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => true,
                'percent' => 100,
            ];
        }

        if ($convertStatus === 'failed' || $itemStatus === DaConvertBatchItemStatus::Failed) {
            return [
                'key' => 'failed',
                'label' => 'Failed',
                'can_queue' => true,
                'can_retry' => true,
                'can_cut_dns' => false,
                'percent' => 0,
            ];
        }

        if ($convertStatus === 'queued' || $itemStatus === DaConvertBatchItemStatus::Queued) {
            return [
                'key' => 'queued',
                'label' => 'Queued',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => false,
                'percent' => 10,
            ];
        }

        if ($convertStatus === 'running' || $itemStatus === DaConvertBatchItemStatus::Converting) {
            $creating = in_array($container, ['none', 'creating', 'deploying'], true);

            return [
                'key' => $creating ? 'creating' : 'importing',
                'label' => $creating ? 'Creating container' : 'Importing site',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => false,
                'percent' => $creating ? 35 : 70,
            ];
        }

        if (in_array($itemStatus, [
            DaConvertBatchItemStatus::WaitingDns,
            DaConvertBatchItemStatus::Converted,
        ], true)) {
            return [
                'key' => 'waiting_dns',
                'label' => 'Container ready',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => $item !== null,
                'percent' => 100,
            ];
        }

        if ($itemStatus === DaConvertBatchItemStatus::WaitingMx) {
            return [
                'key' => 'waiting_mx',
                'label' => 'Waiting on MX',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => $item !== null,
                'percent' => 100,
            ];
        }

        if ($itemStatus === DaConvertBatchItemStatus::Done || $convertStatus === 'completed') {
            return [
                'key' => 'done',
                'label' => 'Done',
                'can_queue' => false,
                'can_retry' => false,
                'can_cut_dns' => false,
                'percent' => 100,
            ];
        }

        if ($itemStatus === DaConvertBatchItemStatus::Blocked) {
            return [
                'key' => 'blocked',
                'label' => 'Blocked',
                'can_queue' => true,
                'can_retry' => true,
                'can_cut_dns' => false,
                'percent' => 0,
            ];
        }

        return [
            'key' => 'ready',
            'label' => 'Ready',
            'can_queue' => true,
            'can_retry' => false,
            'can_cut_dns' => false,
            'percent' => 0,
        ];
    }

    /**
     * @return Collection<int, DaConvertBatchItem>
     */
    public function openConvertItems(User $reseller): Collection
    {
        return DaConvertBatchItem::query()
            ->whereHas('batch', fn ($query) => $query->where('reseller_user_id', $reseller->id))
            ->whereIn('status', [
                DaConvertBatchItemStatus::Queued,
                DaConvertBatchItemStatus::Converting,
                DaConvertBatchItemStatus::Failed,
                DaConvertBatchItemStatus::WaitingDns,
                DaConvertBatchItemStatus::WaitingMx,
                DaConvertBatchItemStatus::Converted,
            ])
            ->with(['service.user', 'service.product', 'service.node', 'service.latestDaAccountSnapshot', 'service.containerDeployment'])
            ->latest('id')
            ->get()
            ->unique('service_id')
            ->values();
    }

    public function latestItemForService(User $reseller, Service $service): ?DaConvertBatchItem
    {
        if (is_array($this->latestItemsByServiceId) && array_key_exists((int) $service->id, $this->latestItemsByServiceId)) {
            return $this->latestItemsByServiceId[(int) $service->id];
        }

        $items = DaConvertBatchItem::query()
            ->where('service_id', $service->id)
            ->whereHas('batch', fn ($query) => $query->where('reseller_user_id', $reseller->id))
            ->latest('id')
            ->get();

        return $this->pickPreferredConvertItem($items);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function primeLatestConvertItems(User $reseller, Collection $rows): void
    {
        $ids = $rows
            ->map(fn (array $account): ?int => $account['service'] instanceof Service ? (int) $account['service']->id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->latestItemsByServiceId = array_fill_keys($ids, null);
        if ($ids === []) {
            return;
        }

        $grouped = DaConvertBatchItem::query()
            ->whereIn('service_id', $ids)
            ->whereHas('batch', fn ($query) => $query->where('reseller_user_id', $reseller->id))
            ->orderByDesc('id')
            ->get()
            ->groupBy('service_id');

        foreach ($grouped as $serviceId => $items) {
            $this->latestItemsByServiceId[(int) $serviceId] = $this->pickPreferredConvertItem($items);
        }
    }

    /**
     * @param  Collection<int, DaConvertBatchItem>  $items
     */
    private function pickPreferredConvertItem(Collection $items): ?DaConvertBatchItem
    {
        return $items->first(fn (DaConvertBatchItem $item): bool => in_array($item->status, [
            DaConvertBatchItemStatus::WaitingDns,
            DaConvertBatchItemStatus::WaitingMx,
            DaConvertBatchItemStatus::Converted,
            DaConvertBatchItemStatus::Done,
            DaConvertBatchItemStatus::Converting,
            DaConvertBatchItemStatus::Queued,
        ], true)) ?? $items->first();
    }

    /**
     * Live operator payload for the off-ramp terminal: convert steps + mail pull.
     *
     * @return array{
     *     is_active: bool,
     *     active_count: int,
     *     items: list<array<string, mixed>>,
     *     current: ?array<string, mixed>,
     *     accounts: list<array<string, mixed>>
     * }
     */
    public function operatorProgress(User $reseller, bool $forReseller = false): array
    {
        // The latest few batches for context, plus every batch that still holds
        // an open item: each Retry or relink makes a new batch, so a queued
        // account from earlier today must not drop out of the console.
        $batches = DaConvertBatch::query()
            ->where('reseller_user_id', $reseller->id)
            ->with(['items.service.user'])
            ->latest()
            ->limit(4)
            ->get();
        $openItems = $this->openConvertItems($reseller);
        $missingBatchIds = $openItems->pluck('da_convert_batch_id')->map(fn ($id): int => (int) $id)
            ->diff($batches->pluck('id')->map(fn ($id): int => (int) $id))
            ->unique()
            ->values();
        if ($missingBatchIds->isNotEmpty()) {
            $batches = $batches->concat(
                DaConvertBatch::query()->whereIn('id', $missingBatchIds->all())->with(['items.service.user'])->latest()->get()
            );
        }

        // One console row per service: the item the board itself follows.
        $preferredItemIds = [];
        foreach ($batches->flatMap(fn (DaConvertBatch $batch) => $batch->items)->groupBy('service_id') as $serviceItems) {
            $preferred = $this->pickPreferredConvertItem($serviceItems->sortByDesc('id')->values());
            if ($preferred) {
                $preferredItemIds[(int) $preferred->id] = true;
            }
        }

        $items = [];
        foreach ($batches as $batch) {
            foreach ($batch->items as $item) {
                if (! isset($preferredItemIds[(int) $item->id])) {
                    continue;
                }
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
                    'service_url' => $forReseller ? route('reseller.services.show', $service) : route('admin.services.show', $service),
                    'wizard_url' => $forReseller ? null : route('admin.services.migrate-to-container', $service),
                    ...$view,
                    'is_active' => $itemActive,
                    'can_restart' => $this->canRestartItem($item, $view),
                    'restart_url' => $forReseller
                        ? route('reseller.directadmin-offramp.restart', [$batch, $item])
                        : route('admin.resellers.directadmin-offramp.restart', [$reseller, $batch, $item]),
                ];
            }
        }

        $active = collect($items)->firstWhere('is_active', true) ?? ($items[0] ?? null);

        $accounts = [];
        foreach ($this->openConvertItems($reseller) as $item) {
            $service = $item->service;
            if (! $service) {
                continue;
            }
            $username = $this->serviceDaUsername($service);
            $key = $username !== '' ? 'da:'.$username : 'service:'.$service->id;
            $accounts[] = $this->presentBoardAccount(
                $reseller,
                $this->accountFromService($service, $key, $username, $this->serviceHostname($service))
            );
        }

        return [
            'is_active' => collect($items)->contains(fn (array $row): bool => (bool) ($row['is_active'] ?? false)),
            'active_count' => collect($items)->where('is_active', true)->count(),
            'items' => $items,
            'current' => $active,
            'accounts' => $accounts,
        ];
    }
}
