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
 * Admin-only convert-in-place: DA shared hosting → App Hosting on the same Service.
 * Keeps next_due_date / billing_cycle, switches product to container pricing, no invoice, no customer notify.
 * Email stays on DirectAdmin (account is not deleted).
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

        if ($inventory['stack'] !== 'wordpress' && ! $inventory['has_wp_config']) {
            $blockers[] = 'Site does not look like WordPress (wp-config.php not found).';
        }

        if (! $email['success']) {
            $blockers[] = 'Could not list email accounts: '.$email['message'];
        }

        $productPick = $this->availableWordPressProducts();
        $products = $productPick['products'];
        $productsAreFallback = $productPick['fallback'];

        $activeEligible = $productsAreFallback
            ? $products->filter(fn (Product $product) => $product->is_active && $this->productIsWordPressContainer($product))
            : $products->where('is_active', true);

        if ($activeEligible->isEmpty()) {
            $blockers[] = 'No active WordPress App Hosting products are available. Create or activate a product with the WordPress container template under Admin → Products.';
        }

        $looksLikeWordpress = $inventory['stack'] === 'wordpress' || $inventory['has_wp_config'];

        return [
            'inventory' => $inventory,
            'email' => $email,
            'can_convert' => $blockers === [] && $looksLikeWordpress,
            'blockers' => $blockers,
            'wordpress_products' => $products->all(),
            'products_are_fallback' => $productsAreFallback,
        ];
    }

    /**
     * WordPress App Hosting catalog for the convert dropdown.
     *
     * @return array{products: Collection<int, Product>, fallback: bool}
     */
    public function availableWordPressProducts(): array
    {
        $base = Product::query()
            ->where('type', 'container_hosting')
            ->with('containerTemplate');

        $wordpress = (clone $base)
            ->where(function ($query) {
                $query->whereHas('containerTemplate', function ($template) {
                    $template->whereRaw('LOWER(slug) = ?', ['wordpress'])
                        ->orWhereRaw('LOWER(slug) LIKE ?', ['%wordpress%'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%wordpress%']);
                })->orWhereRaw('LOWER(name) LIKE ?', ['%wordpress%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%wordpress%']);
            })
            ->orderByDesc('is_active')
            ->orderBy('order')
            ->orderBy('monthly_price')
            ->orderBy('price')
            ->orderBy('name')
            ->get();

        if ($wordpress->isNotEmpty()) {
            return ['products' => $wordpress, 'fallback' => false];
        }

        $all = (clone $base)
            ->orderByDesc('is_active')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return ['products' => $all, 'fallback' => $all->isNotEmpty()];
    }

    public function productIsWordPressContainer(Product $product): bool
    {
        $product->loadMissing('containerTemplate');
        $slug = strtolower((string) ($product->containerTemplate?->slug ?? ''));
        $templateName = strtolower((string) ($product->containerTemplate?->name ?? ''));
        $name = strtolower((string) $product->name);
        $productSlug = strtolower((string) ($product->slug ?? ''));

        if ($product->type !== 'container_hosting') {
            return false;
        }

        return $slug === 'wordpress'
            || str_contains($slug, 'wordpress')
            || str_contains($templateName, 'wordpress')
            || str_contains($name, 'wordpress')
            || str_contains($productSlug, 'wordpress');
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
     * Convert the same service row from DA shared hosting to WordPress App Hosting.
     * No invoice. No customer notification. Preserves next_due_date and billing_cycle.
     *
     * @return array{ok: bool, message: string, steps: list<string>}
     */
    public function convertInPlace(
        Service $service,
        Product $containerProduct,
        bool $acknowledgeExtraMailboxes = false,
        ?string $databaseName = null,
    ): array {
        $preflight = $this->preflight($service);
        if ($preflight['blockers'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $preflight['blockers']));
        }

        if ($preflight['email']['has_extra_mailboxes'] && ! $acknowledgeExtraMailboxes) {
            throw new \InvalidArgumentException(
                'This account has mailboxes beyond the default DA user mailbox. Acknowledge that email stays on DirectAdmin before converting.'
            );
        }

        if (! $this->productIsWordPressContainer($containerProduct)) {
            throw new \InvalidArgumentException('Select a WordPress App Hosting product (container product with WordPress template).');
        }

        if (! $containerProduct->is_active) {
            throw new \InvalidArgumentException('The selected App Hosting product is inactive. Activate it first.');
        }

        $service->loadMissing('node', 'product', 'user');
        $inventory = $preflight['inventory'];
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

        $steps = ['Preflight OK'];
        $this->writeConvertMeta($service, [
            'status' => 'running',
            'mode' => 'convert_in_place',
            'started_at' => now()->toIso8601String(),
            'previous' => $previous,
            'steps' => $steps,
            'error' => null,
            'quiet' => true,
            'no_invoice' => true,
        ]);

        $export = null;

        try {
            $steps[] = 'Exporting WordPress files and database from DirectAdmin';
            $this->appendConvertStep($service, $steps);
            $export = $this->migrator->exportWordPressFromDirectAdmin($service, $inventory, $databaseName);

            $creds = $service->getHostingCredentials() ?? [];
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['da_legacy'] = [
                'username' => $creds['username'] ?? $meta['username'] ?? $service->external_reference,
                'domain' => $inventory['domain'],
                'da_node_id' => $daNode->id,
                'docroot' => $inventory['docroot'],
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

            $steps[] = 'Switching service product to App Hosting (keeping due date; clearing custom price)';
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

            $steps[] = 'Provisioning WordPress container (silent — no customer notification)';
            $this->appendConvertStep($service, $steps);
            $this->deployments->deploy($service, ContainerDeployOptions::quiet());

            $service->refresh()->load('containerDeployment.node', 'product.containerTemplate');

            $steps[] = 'Importing WordPress into container';
            $this->appendConvertStep($service, $steps);
            $this->migrator->importWordPressIntoContainer(
                $service,
                $export['local_dump'],
                $export['local_tar'],
                $export['remote_work'],
                $daNode,
            );

            $renewalPreview = $this->renewalPricing->unitPrice($service->fresh());
            $steps[] = sprintf(
                'Convert complete. Next due %s · renewal will bill App Hosting (~%s). Email remains on DirectAdmin.',
                optional($service->next_due_date)->toDateString() ?? 'n/a',
                number_format($renewalPreview, 2)
            );

            $this->writeConvertMeta($service, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'steps' => $steps,
                'target_product_id' => $containerProduct->id,
                'renewal_unit_price' => $renewalPreview,
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
                'message' => 'Service converted to App Hosting. Billing date unchanged; container rates apply at next renewal. Email stays on DirectAdmin.',
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
            // Only roll product back if container deploy never became healthy
            if (! $service->containerDeployment || $service->containerDeployment->status !== 'running') {
                $service->update([
                    'product_id' => $previous['product_id'],
                    'node_id' => $previous['node_id'],
                    'provisioning_driver_key' => $previous['provisioning_driver_key'],
                    'custom_price' => $previous['custom_price'],
                    'status' => $previous['status'] ?: 'active',
                ]);
            }
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
        $this->writeConvertMeta($service, ['steps' => $steps, 'status' => 'running']);
    }
}
