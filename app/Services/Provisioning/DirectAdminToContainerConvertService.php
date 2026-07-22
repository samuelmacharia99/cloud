<?php

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin-only convert-in-place: DA shared hosting → Application Hosting on the same Service.
 * Keeps next_due_date / billing_cycle, switches product to container pricing, no invoice, no customer notify.
 * Email may remain on DirectAdmin temporarily, or be moved to Mailcow via the mail migration wizard.
 */
class DirectAdminToContainerConvertService
{
    public function __construct(
        private DirectAdminToContainerMigrationService $migrator,
        private ContainerDeploymentService $deployments,
        private ServiceRenewalPricingService $renewalPricing,
    ) {}

    /**
     * @return array{
     *     inventory: array,
     *     email: array{
     *         success: bool,
     *         message: string,
     *         has_extra_mailboxes: bool,
     *         default_mailboxes: list<array{account: string, email: string}>,
     *         extra_mailboxes: list<array{account: string, email: string}>,
     *         all: list<array{account: string, email: string}>
     *     },
     *     can_convert: bool,
     *     blockers: list<string>,
     *     detected_stack: string,
     *     has_addon_sites: bool,
     *     container_products: list<Product>,
     *     wordpress_products: list<Product>,
     *     products_are_fallback: bool
     * }
     */
    public function preflight(Service $service): array
    {
        if (! $service->isSharedHosting()) {
            throw new \InvalidArgumentException('Only DirectAdmin shared hosting services can be converted.');
        }

        $inventory = $this->migrator->inventory($service);
        $email = $this->emailPreflight($service);
        $blockers = [];

        $stack = $this->normalizeConvertibleStack($inventory);
        $supported = in_array($stack, ['wordpress', 'laravel', 'php', 'static_or_php'], true);

        if (! $supported) {
            $blockers[] = 'Site stack could not be detected as WordPress, Laravel, PHP, or static. Confirm the primary docroot path.';
        }

        if (! $email['success']) {
            $blockers[] = 'Could not list email accounts: '.$email['message'];
        }

        $productPick = $this->availableProductsForStack($stack);
        $products = $productPick['products'];
        $productsAreFallback = $productPick['fallback'];

        $activeEligible = $productsAreFallback
            ? $products->filter(fn (Product $product) => $product->is_active && $this->productMatchesStack($product, $stack))
            : $products->where('is_active', true);

        if ($activeEligible->isEmpty()) {
            $blockers[] = sprintf(
                'No active Application Hosting products are available for detected stack (%s). Create or activate a matching container product under Admin → Products.',
                str_replace('_', ' ', $stack)
            );
        }

        $addonCount = (int) ($inventory['addon_site_count'] ?? 0);

        return [
            'inventory' => $inventory,
            'email' => $email,
            'can_convert' => $blockers === [] && $supported,
            'blockers' => $blockers,
            'detected_stack' => $stack,
            'has_addon_sites' => $addonCount > 0,
            'container_products' => $products->all(),
            // Backward-compatible alias for older views/controllers.
            'wordpress_products' => $products->all(),
            'products_are_fallback' => $productsAreFallback,
        ];
    }

    /**
     * @param  array{stack?: string, has_wp_config?: bool}  $inventory
     */
    public function normalizeConvertibleStack(array $inventory): string
    {
        if (($inventory['has_wp_config'] ?? false) || ($inventory['stack'] ?? '') === 'wordpress') {
            return 'wordpress';
        }

        $stack = (string) ($inventory['stack'] ?? 'unknown');

        return in_array($stack, ['laravel', 'php', 'static_or_php'], true) ? $stack : $stack;
    }

    /**
     * Application Hosting catalog for the convert dropdown, filtered by detected stack.
     *
     * @return array{products: Collection<int, Product>, fallback: bool}
     */
    public function availableProductsForStack(string $stack): array
    {
        $stack = $stack === 'wordpress' || ($stack !== '' && $this->stackKeywords($stack) !== [])
            ? $stack
            : 'wordpress';

        $base = Product::query()
            ->where('type', 'container_hosting')
            ->with('containerTemplate');

        $keywords = $this->stackKeywords($stack);
        $matched = (clone $base)
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $like = '%'.$keyword.'%';
                    $query->orWhere(function ($inner) use ($keyword, $like) {
                        $inner->whereHas('containerTemplate', function ($template) use ($keyword, $like) {
                            $template->whereRaw('LOWER(slug) = ?', [$keyword])
                                ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                        })->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(slug) LIKE ?', [$like]);
                    });
                }
            })
            ->orderByDesc('is_active')
            ->orderBy('order')
            ->orderBy('monthly_price')
            ->orderBy('price')
            ->orderBy('name')
            ->get();

        if ($matched->isNotEmpty()) {
            return ['products' => $matched, 'fallback' => false];
        }

        $all = (clone $base)
            ->orderByDesc('is_active')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return ['products' => $all, 'fallback' => $all->isNotEmpty()];
    }

    /**
     * @return list<string>
     */
    public function stackKeywords(string $stack): array
    {
        return match ($stack) {
            'wordpress' => ['wordpress'],
            'laravel' => ['laravel'],
            'php' => ['php'],
            'static_or_php' => ['static-site', 'static', 'php'],
            default => [],
        };
    }

    /**
     * @deprecated Use availableProductsForStack('wordpress')
     * @return array{products: Collection<int, Product>, fallback: bool}
     */
    public function availableWordPressProducts(): array
    {
        return $this->availableProductsForStack('wordpress');
    }

    public function productMatchesStack(Product $product, string $stack): bool
    {
        $product->loadMissing('containerTemplate');
        if ($product->type !== 'container_hosting') {
            return false;
        }

        $haystacks = [
            strtolower((string) ($product->containerTemplate?->slug ?? '')),
            strtolower((string) ($product->containerTemplate?->name ?? '')),
            strtolower((string) $product->name),
            strtolower((string) ($product->slug ?? '')),
        ];

        foreach ($this->stackKeywords($stack) as $keyword) {
            foreach ($haystacks as $haystack) {
                if ($haystack === $keyword || str_contains($haystack, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function productIsWordPressContainer(Product $product): bool
    {
        return $this->productMatchesStack($product, 'wordpress');
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     has_extra_mailboxes: bool,
     *     default_mailboxes: list<array{account: string, email: string}>,
     *     extra_mailboxes: list<array{account: string, email: string}>,
     *     all: list<array{account: string, email: string}>
     * }
     */
    public function emailPreflight(Service $service): array
    {
        $service->loadMissing('node');
        $creds = $service->getHostingCredentials() ?? [];
        $username = (string) ($creds['username'] ?? $service->external_reference ?? ($service->service_meta['username'] ?? ''));
        $domain = $service->attachedDomainName() ?? ($creds['domain'] ?? null);

        if ($username === '' || ! is_string($domain) || $domain === '' || ! $service->node) {
            return [
                'success' => false,
                'message' => 'Missing DA username, domain, or node for mailbox inventory.',
                'has_extra_mailboxes' => false,
                'default_mailboxes' => [],
                'extra_mailboxes' => [],
                'all' => [],
            ];
        }

        $api = DirectAdminCustomerPanelApi::forServiceNode($service->node);
        $list = $api->listEmailAccounts($username, $domain);
        if (! ($list['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($list['message'] ?? 'Failed to list mailboxes.'),
                'has_extra_mailboxes' => false,
                'default_mailboxes' => [],
                'extra_mailboxes' => [],
                'all' => [],
            ];
        }

        $all = [];
        foreach ($list['data'] ?? [] as $row) {
            $account = (string) ($row['account'] ?? '');
            $email = (string) ($row['email'] ?? $account);
            if ($account === '' && $email === '') {
                continue;
            }
            $all[] = [
                'account' => $account !== '' ? $account : $email,
                'email' => $email !== '' ? $email : $account,
            ];
        }

        $classified = $this->classifyMailboxes($username, $all);

        return [
            'success' => true,
            'message' => 'OK',
            'has_extra_mailboxes' => $classified['has_extra_mailboxes'],
            'default_mailboxes' => $classified['default_mailboxes'],
            'extra_mailboxes' => $classified['extra_mailboxes'],
            'all' => $all,
        ];
    }

    /**
     * Default DA mailbox = local-part matching the DA username (ignores extra accounts).
     *
     * @param  list<array{account: string, email: string}>  $all
     * @return array{
     *     has_extra_mailboxes: bool,
     *     default_mailboxes: list<array{account: string, email: string}>,
     *     extra_mailboxes: list<array{account: string, email: string}>
     * }
     */
    public function classifyMailboxes(string $username, array $all): array
    {
        $defaultLocal = strtolower($username);
        $defaults = [];
        $extras = [];
        foreach ($all as $row) {
            $local = strtolower((string) ($row['account'] ?? ''));
            if (str_contains($local, '@')) {
                $local = explode('@', $local, 2)[0];
            }
            if ($local === $defaultLocal) {
                $defaults[] = $row;
            } else {
                $extras[] = $row;
            }
        }

        return [
            'has_extra_mailboxes' => $extras !== [],
            'default_mailboxes' => $defaults,
            'extra_mailboxes' => $extras,
        ];
    }

    /**
     * Convert the same service row from DA shared hosting to Application Hosting (stack-aware).
     * No invoice. No customer notification. Preserves next_due_date and billing_cycle.
     *
     * @return array{ok: bool, message: string, steps: list<string>}
     */
    public function convertInPlace(
        Service $service,
        Product $containerProduct,
        bool $acknowledgeExtraMailboxes = false,
        ?string $databaseName = null,
        bool $acknowledgeAddonSites = false,
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $preflight = $this->preflight($service);
        if ($preflight['blockers'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $preflight['blockers']));
        }

        if ($preflight['email']['has_extra_mailboxes'] && ! $acknowledgeExtraMailboxes) {
            throw new \InvalidArgumentException(
                'This account has mailboxes beyond the default DA user mailbox. Acknowledge that email stays on DirectAdmin (or migrate mail to Mailcow first) before converting.'
            );
        }

        if (($preflight['has_addon_sites'] ?? false) && ! $acknowledgeAddonSites) {
            throw new \InvalidArgumentException(
                'This DA user has additional domains/sites. Acknowledge that only the primary site converts on this service; other sites need separate Application Hosting services.'
            );
        }

        $stack = (string) ($preflight['detected_stack'] ?? 'unknown');
        if (! $this->productMatchesStack($containerProduct, $stack) && ! ($preflight['products_are_fallback'] ?? false)) {
            throw new \InvalidArgumentException(
                'Select an Application Hosting product that matches the detected stack ('.$stack.').'
            );
        }

        if (! $containerProduct->is_active) {
            throw new \InvalidArgumentException('The selected Application Hosting product is inactive. Activate it first.');
        }

        $service->loadMissing('node', 'product', 'user');
        $inventory = $preflight['inventory'];
        $inventory['stack'] = $stack;
        $daNode = $service->node;
        if (! $daNode) {
            throw new \InvalidArgumentException('DirectAdmin node is missing.');
        }

        $previous = [
            'product_id' => $service->product_id,
            'node_id' => $service->node_id,
            'provisioning_driver_key' => $service->provisioning_driver_key,
            'custom_price' => $service->custom_price,
            'status' => $service->status?->value ?? (string) $service->status,
        ];

        $steps = ['Preflight OK · stack '.$stack];
        $this->writeConvertMeta($service, [
            'status' => 'running',
            'mode' => 'convert_in_place',
            'stack' => $stack,
            'started_at' => now()->toIso8601String(),
            'previous' => $previous,
            'steps' => $steps,
            'error' => null,
            'quiet' => true,
            'no_invoice' => true,
        ]);

        $export = null;

        try {
            $steps[] = 'Exporting site files'.(in_array($stack, ['wordpress', 'laravel', 'php'], true) ? ' and database' : '').' from DirectAdmin';
            $this->appendConvertStep($service, $steps);
            $export = $this->migrator->exportSiteFromDirectAdmin($service, $inventory, $databaseName);

            $creds = $service->getHostingCredentials() ?? [];
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['da_legacy'] = [
                'username' => $creds['username'] ?? $meta['username'] ?? $service->external_reference,
                'domain' => $inventory['domain'],
                'da_node_id' => $daNode->id,
                'docroot' => $inventory['docroot'],
                'stack' => $stack,
                'addon_sites' => $inventory['sites'] ?? [],
                'converted_at' => now()->toIso8601String(),
                'keep_email_on_da' => true,
                'had_extra_mailboxes' => $preflight['email']['has_extra_mailboxes'],
            ];
            // Preserve panel password in meta if present (deploy overwrites credentials JSON)
            if (! empty($creds['password'])) {
                $meta['da_legacy']['password'] = $creds['password'];
            } elseif (! empty($meta['password'])) {
                $meta['da_legacy']['password'] = $meta['password'];
            }

            $steps[] = 'Switching service product to Application Hosting (keeping due date; clearing custom price)';
            $this->appendConvertStep($service, $steps);

            DB::transaction(function () use ($service, $containerProduct, $meta) {
                $service->update([
                    'product_id' => $containerProduct->id,
                    'provisioning_driver_key' => 'container',
                    'custom_price' => null,
                    'node_id' => null,
                    'status' => 'provisioning',
                    'service_meta' => $meta,
                    // next_due_date + billing_cycle unchanged
                ]);
            });

            $service->refresh()->load('product.containerTemplate', 'user');

            $steps[] = 'Provisioning '.$stack.' container (silent — no customer notification)';
            $this->appendConvertStep($service, $steps);
            $this->deployments->deploy($service, ContainerDeployOptions::quietConvert());

            $service->refresh()->load('containerDeployment.node', 'product.containerTemplate');

            $steps[] = 'Importing site into container';
            $this->appendConvertStep($service, $steps);
            $this->migrator->importSiteIntoContainer(
                $service,
                $export['local_dump'] ?? null,
                $export['local_tar'],
                $export['remote_work'],
                (string) ($export['stack'] ?? $stack),
                $daNode,
                function (string $detail) use ($service, &$steps): void {
                    $steps[] = 'Import: '.$detail;
                    $this->appendConvertStep($service, $steps);
                },
            );

            $renewalPreview = $this->renewalPricing->unitPrice($service->fresh());
            $addonNote = ($preflight['has_addon_sites'] ?? false)
                ? ' Addon domains on this DA user still need their own Application Hosting services.'
                : '';
            $steps[] = sprintf(
                'Convert complete. Next due %s · renewal will bill Application Hosting (~%s). Email remains on DirectAdmin.%s',
                optional($service->next_due_date)->toDateString() ?? 'n/a',
                number_format($renewalPreview, 2),
                $addonNote
            );

            $this->writeConvertMeta($service, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'steps' => $steps,
                'target_product_id' => $containerProduct->id,
                'renewal_unit_price' => $renewalPreview,
                'stack' => $stack,
            ]);

            // Mirror into da_migration for any existing overview banners
            $this->migrator->recordExternalProgress($service, [
                'status' => 'completed',
                'mode' => 'convert_in_place',
                'steps' => $steps,
                'completed_at' => now()->toIso8601String(),
            ]);

            return [
                'ok' => true,
                'message' => 'Service converted to Application Hosting. Billing date unchanged; container rates apply at next renewal. Email remains on DirectAdmin until migrated to Mailcow.',
                'steps' => $steps,
            ];
        } catch (\Throwable $e) {
            Log::error('DA→container convert-in-place failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            $this->attemptRollback($service, $previous, $e->getMessage());
            $this->writeConvertMeta($service->fresh(), [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
                'steps' => $steps,
            ]);

            throw $e;
        } finally {
            if (is_array($export)) {
                foreach (['local_dump', 'local_tar'] as $key) {
                    $path = $export[$key] ?? null;
                    if (is_string($path) && is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }
    }

    /**
     * @param  array{product_id: int, node_id: ?int, provisioning_driver_key: ?string, custom_price: mixed, status: string}  $previous
     */
    private function attemptRollback(Service $service, array $previous, string $error): void
    {
        try {
            $service->refresh();
            // Always restore the DA product so convert can be retried. A running
            // container from a failed import is cleaned up on the next deploy.
            $service->update([
                'product_id' => $previous['product_id'],
                'node_id' => $previous['node_id'],
                'provisioning_driver_key' => $previous['provisioning_driver_key'],
                'custom_price' => $previous['custom_price'],
                'status' => $previous['status'] ?: 'active',
            ]);
        } catch (\Throwable $rollbackError) {
            Log::warning('Convert-in-place rollback incomplete', [
                'service_id' => $service->id,
                'original_error' => $error,
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }
    }

    private function writeConvertMeta(Service $service, array $data): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['da_convert'] = array_merge($meta['da_convert'] ?? [], $data);
        $service->update(['service_meta' => $meta]);
        $service->refresh();
    }

    /**
     * @param  list<string>  $steps
     */
    private function appendConvertStep(Service $service, array $steps): void
    {
        $this->writeConvertMeta($service, [
            'steps' => $steps,
            'status' => 'running',
            'heartbeat_at' => now()->toIso8601String(),
        ]);
    }

    public function canRevertToDirectAdmin(Service $service): bool
    {
        $convert = is_array($service->service_meta['da_convert'] ?? null)
            ? $service->service_meta['da_convert']
            : [];

        $previous = $convert['previous'] ?? null;
        if (! is_array($previous) || empty($previous['product_id'])) {
            return false;
        }

        if (in_array($convert['status'] ?? '', ['queued', 'running'], true)) {
            // Allow force-revert when the job died mid-flight (e.g. PHP 30s timeout).
            return $this->convertLooksStuck($convert);
        }

        $alreadyOnPrevious = (int) $service->product_id === (int) $previous['product_id']
            && $service->isSharedHosting()
            && ($service->provisioning_driver_key === 'directadmin'
                || $service->provisioning_driver_key === ($previous['provisioning_driver_key'] ?? 'directadmin'));

        return ! $alreadyOnPrevious;
    }

    /**
     * @param  array<string, mixed>  $convert
     */
    public function convertLooksStuck(array $convert): bool
    {
        $marker = $convert['heartbeat_at']
            ?? $convert['started_at']
            ?? $convert['queued_at']
            ?? null;

        if (! is_string($marker) || $marker === '') {
            return true;
        }

        try {
            return \Carbon\Carbon::parse($marker)->lt(now()->subMinutes(15));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Restore the platform service row to DirectAdmin from da_convert.previous.
     * Does not touch the container on the server — admin removes that manually.
     */
    public function revertToDirectAdmin(Service $service): Service
    {
        if (! $this->canRevertToDirectAdmin($service)) {
            throw new \InvalidArgumentException(
                'This service cannot be reverted to DirectAdmin (missing convert snapshot, or convert still running).'
            );
        }

        $service->loadMissing('containerDeployment', 'product');
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $previous = $meta['da_convert']['previous'];
        $legacy = is_array($meta['da_legacy'] ?? null) ? $meta['da_legacy'] : [];

        $nodeId = $previous['node_id']
            ?? ($legacy['da_node_id'] ?? null)
            ?? ($meta['node_id'] ?? null);

        $containerName = $service->containerDeployment?->container_name;

        $service->update([
            'product_id' => $previous['product_id'],
            'node_id' => $nodeId,
            'provisioning_driver_key' => $previous['provisioning_driver_key'] ?? 'directadmin',
            'custom_price' => $previous['custom_price'] ?? null,
            'status' => $previous['status'] ?: 'active',
        ]);

        if (! empty($legacy['username']) && blank($meta['username'] ?? null)) {
            $meta['username'] = $legacy['username'];
        }
        if (! empty($legacy['domain']) && blank($meta['domain'] ?? null)) {
            $meta['domain'] = $legacy['domain'];
        }
        if (! empty($legacy['password']) && blank($meta['password'] ?? null)) {
            $meta['password'] = $legacy['password'];
        }

        $meta['da_convert'] = array_merge($meta['da_convert'] ?? [], [
            'status' => 'reverted',
            'reverted_at' => now()->toIso8601String(),
            'manual_container_cleanup' => $containerName,
            'error' => null,
        ]);

        $service->update(['service_meta' => $meta]);

        return $service->fresh(['product', 'node', 'user']);
    }
}
