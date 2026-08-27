<?php

namespace App\Services\Provisioning;

use App\Jobs\ConvertDirectAdminProjectSiteJob;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin-only convert-in-place: DA shared hosting → Application Hosting on the same Service.
 * Keeps next_due_date / billing_cycle, switches product to container pricing, no invoice, no customer notify.
 * Email is pulled to Mailcow so the DirectAdmin account can be decommissioned.
 */
class DirectAdminToContainerConvertService
{
    public const PROJECT_RECIPE_KEY = 'da_convert';

    public function __construct(
        private DirectAdminToContainerMigrationService $migrator,
        private ContainerDeploymentService $deployments,
        private ServiceRenewalPricingService $renewalPricing,
        private DirectAdminToMailcowMigrationService $mailMigrator,
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
     *     recommended_products: list<Product>,
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
        $email = $this->emailPreflight($service, $inventory);
        $blockers = [];

        $stack = $this->normalizeConvertibleStack($inventory);

        if (! $email['success']) {
            $blockers[] = 'Could not list email accounts: '.$email['message'];
        }

        $catalog = $this->applicationHostingCatalog($stack);
        $products = $catalog['products'];
        $recommended = $catalog['recommended'];
        $productsAreFallback = $recommended->isEmpty() && $products->isNotEmpty();

        $activeEligible = $products->where('is_active', true);

        if ($activeEligible->isEmpty()) {
            $blockers[] = 'No active Application Hosting products are available. Create or activate a container product under Admin → Products.';
        }

        $hasContainerHost = Node::query()
            ->where('type', 'container_host')
            ->where('is_active', true)
            ->where('status', 'online')
            ->exists();
        if (! $hasContainerHost) {
            $blockers[] = 'No online container host is available. Add or unsuspend a container node before converting.';
        }

        $emailProducts = Product::query()
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->where('provisioning_driver_key', 'mailcow')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $mailboxCount = count($email['all'] ?? []);
        $daEmailCount = (int) ($inventory['account']['counts']['email'] ?? 0);
        $emailWarnings = $email['warnings'] ?? [];
        $dashboardFailed = filled($inventory['account']['dashboard_error'] ?? null);
        $virtualPasswdScanned = (bool) ($email['virtual_passwd_scanned'] ?? false);

        if ($this->shouldBlockOnMissingMailboxInventory(
            $daEmailCount,
            $mailboxCount,
            $dashboardFailed,
            $virtualPasswdScanned,
        )) {
            $blockers[] = sprintf(
                'DirectAdmin reports %d email account(s) but mailbox inventory found none. Mail would be left on DirectAdmin if you convert now. Check POP API access on the DA node or verify maildirs under /home/{username}/imap and /etc/virtual/{domain}/passwd before converting.',
                $daEmailCount
            );
        } elseif ($daEmailCount > 0 && $mailboxCount === 0) {
            $emailWarnings[] = sprintf(
                'Cached DirectAdmin usage shows %d email account(s), but the live dashboard failed and /etc/virtual listed none. Convert will not pull mail — confirm on DirectAdmin before decommissioning.',
                $daEmailCount
            );
        } elseif ($daEmailCount > $mailboxCount && $mailboxCount > 0) {
            $emailWarnings[] = sprintf(
                'DirectAdmin usage shows %d email account(s) but only %d mailbox(es) were inventoried. Confirm all mail domains are listed before decommissioning DirectAdmin.',
                $daEmailCount,
                $mailboxCount
            );
        }

        if ($mailboxCount > 0) {
            if (! app(MailcowProvisioningService::class)->resolveNode()) {
                $blockers[] = 'Mailboxes exist on DirectAdmin but no active Mailcow node is available. Add a Mailcow node before converting — we are not leaving mail on DirectAdmin.';
            }
            $hasBundle = $products->contains(fn (Product $product) => $product->hasEmailBundle());
            if ($emailProducts->isEmpty() && ! $hasBundle) {
                $blockers[] = 'Mailboxes exist on DirectAdmin but no Email Hosting product is available to pull them onto Mailcow.';
            }
        }

        $addonCount = (int) ($inventory['addon_site_count'] ?? 0);
        $databaseWarnings = $this->databaseExportWarnings($stack, $inventory);

        return [
            'inventory' => $inventory,
            'email' => array_merge($email, [
                'da_email_count' => $daEmailCount,
                'warnings' => $emailWarnings,
            ]),
            'can_convert' => $blockers === [],
            'blockers' => $blockers,
            'detected_stack' => $stack,
            'has_addon_sites' => $addonCount > 0,
            'container_products' => $products->all(),
            'recommended_products' => $recommended->all(),
            'wordpress_products' => $products->all(),
            'products_are_fallback' => $productsAreFallback,
            'email_products' => $emailProducts->all(),
            'mailbox_count' => $mailboxCount,
            'must_pull_mail' => $mailboxCount > 0,
            'da_email_count' => $daEmailCount,
            'database_warnings' => $databaseWarnings,
        ];
    }

    /**
     * @param  array{databases?: list<array{name: string}>, account?: array{counts?: array{database?: int}}}  $inventory
     * @return list<string>
     */
    public function databaseExportWarnings(string $stack, array $inventory): array
    {
        $warnings = [];
        $dbCount = count($inventory['databases'] ?? []);
        $daDbCount = (int) ($inventory['account']['counts']['database'] ?? $dbCount);

        if ($daDbCount > 0 && $dbCount === 0) {
            $warnings[] = sprintf(
                'DirectAdmin reports %d MySQL database(s) but CMD_API_DATABASES returned none. Database export may fail — verify API access on the DA node.',
                $daDbCount
            );
        }

        if ($stack === 'nodejs' && $dbCount > 0) {
            $warnings[] = 'Node.js convert exports MySQL when .env lists DB credentials, you pick a source database below, or DATABASE_URL is set. Without that, only files are migrated.';
        }

        if ($stack === 'static_or_php' && $dbCount > 0) {
            $warnings[] = 'Static/PHP sites export MySQL only when wp-config, .env, or similar config on the DA host resolves database credentials.';
        }

        return $warnings;
    }

    /**
     * Cached DA usage is not proof mail exists when SHOW_USER_CONFIG failed.
     * Trust /etc/virtual/{domain}/passwd over a stale email count.
     */
    public function shouldBlockOnMissingMailboxInventory(
        int $daEmailCount,
        int $mailboxCount,
        bool $dashboardFailed,
        bool $virtualPasswdScanned,
    ): bool {
        if ($mailboxCount > 0 || $daEmailCount <= 0) {
            return false;
        }

        if ($dashboardFailed && $virtualPasswdScanned) {
            return false;
        }

        return true;
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

        return in_array($stack, ['laravel', 'nodejs', 'php', 'static_or_php'], true) ? $stack : $stack;
    }

    public static function stackLabel(string $stack): string
    {
        return match ($stack) {
            'wordpress' => 'WordPress',
            'laravel' => 'Laravel',
            'nodejs' => 'Node.js',
            'php' => 'PHP',
            'static_or_php' => 'static or PHP',
            default => str_replace('_', ' ', $stack) ?: 'unknown',
        };
    }

    /**
     * Full Application Hosting catalog for convert — admin picks the plan that
     * GenerateInvoicesCommand will bill when the current DirectAdmin term ends.
     *
     * @return array{products: Collection<int, Product>, recommended: Collection<int, Product>, fallback: bool}
     */
    public function applicationHostingCatalog(string $stack = ''): array
    {
        $products = Product::query()
            ->where('type', 'container_hosting')
            ->with(['containerTemplate', 'bundledEmailProduct'])
            ->orderByDesc('is_active')
            ->orderBy('order')
            ->orderBy('monthly_price')
            ->orderBy('yearly_price')
            ->orderBy('name')
            ->get();

        $keywords = $this->stackKeywords($stack);
        $recommended = $keywords === []
            ? collect()
            : $products->filter(fn (Product $product) => $this->productMatchesStack($product, $stack))->values();

        return [
            'products' => $products,
            'recommended' => $recommended,
            'fallback' => $recommended->isEmpty() && $products->isNotEmpty(),
        ];
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
            ->orderBy('yearly_price')
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
            'nodejs' => ['nodejs', 'node.js', 'node-js'],
            'php' => ['php'],
            'static_or_php' => ['static-site', 'static', 'php'],
            default => [],
        };
    }

    /**
     * @deprecated Use availableProductsForStack('wordpress')
     *
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
     * @param  array{sites?: list<array{domain?: string}>, domain?: ?string}  $inventory
     * @return array{
     *     success: bool,
     *     message: string,
     *     has_extra_mailboxes: bool,
     *     default_mailboxes: list<array{account: string, email: string}>,
     *     extra_mailboxes: list<array{account: string, email: string}>,
     *     all: list<array{account: string, email: string, domain?: string}>,
     *     by_domain: array<string, list<array{account: string, email: string, domain: string}>>,
     *     errors: list<string>
     * }
     */
    public function emailPreflight(Service $service, array $inventory = []): array
    {
        $service->loadMissing('node');
        $domains = [];
        $username = (string) (($service->getHostingCredentials()['username'] ?? null)
            ?: ($service->service_meta['username'] ?? null)
            ?: $service->external_reference
            ?: '');
        if ($username !== '' && $service->node) {
            try {
                $owned = DirectAdminCustomerPanelApi::forServiceNode($service->node)->listUserDomains($username);
                foreach ($owned['data'] ?? [] as $row) {
                    $name = strtolower(trim((string) ($row['name'] ?? '')));
                    if ($name !== '') {
                        $domains[] = $name;
                    }
                }
            } catch (\Throwable) {
            }
        }
        foreach ($inventory['sites'] ?? [] as $site) {
            $name = strtolower(trim((string) ($site['domain'] ?? '')));
            if ($name !== '') {
                $domains[] = $name;
            }
        }
        $primary = strtolower(trim((string) ($inventory['domain'] ?? $service->attachedDomainName() ?? '')));
        if ($primary !== '') {
            $domains[] = $primary;
        }
        $domains = array_values(array_unique($domains));

        if ($domains === [] || ! $service->node) {
            return [
                'success' => false,
                'message' => 'Missing DA username, domain, or node for mailbox inventory.',
                'has_extra_mailboxes' => false,
                'default_mailboxes' => [],
                'extra_mailboxes' => [],
                'all' => [],
                'by_domain' => [],
                'errors' => [],
            ];
        }

        $listed = $this->mailMigrator->listMailboxesByDomains($service, $domains);
        $all = $listed['all'];
        $creds = $service->getHostingCredentials() ?? [];
        $username = (string) ($creds['username'] ?? $service->external_reference ?? ($service->service_meta['username'] ?? ''));
        $classified = $this->classifyMailboxes($username, $all);

        $sshScanned = (bool) ($listed['ssh_scanned'] ?? false);
        $fatal = $all === [] && $listed['errors'] !== [] && ! $sshScanned;
        $daEmailCount = (int) ($inventory['account']['counts']['email'] ?? 0);
        $warnings = [];
        if ($daEmailCount > 0 && $all === [] && ! $fatal) {
            $warnings[] = sprintf(
                'DirectAdmin usage shows %d email account(s) but POP/SSH inventory found none on the domains scanned.',
                $daEmailCount
            );
        }

        return [
            'success' => ! $fatal,
            'message' => $fatal
                ? implode(' ', $listed['errors'])
                : 'OK',
            'has_extra_mailboxes' => $classified['has_extra_mailboxes'],
            'default_mailboxes' => $classified['default_mailboxes'],
            'extra_mailboxes' => $classified['extra_mailboxes'],
            'all' => $all,
            'by_domain' => $listed['by_domain'],
            'errors' => $listed['errors'],
            'da_email_count' => $daEmailCount,
            'warnings' => $warnings,
            'ssh_scanned' => $sshScanned,
            'virtual_passwd_scanned' => (bool) ($listed['virtual_passwd_scanned'] ?? false),
        ];
    }

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
        ?Product $emailProduct = null,
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $preflight = $this->preflight($service);
        if ($preflight['blockers'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $preflight['blockers']));
        }

        $mustPullMail = (int) ($preflight['mailbox_count'] ?? count($preflight['email']['all'] ?? [])) > 0;
        if ($mustPullMail && ! $acknowledgeExtraMailboxes) {
            throw new \InvalidArgumentException(
                'This account has mailboxes. Acknowledge that mail is pulled to Mailcow (IMAP sync). Update MX when sync has caught up, then DirectAdmin can be decommissioned.'
            );
        }

        if ($mustPullMail) {
            $emailProduct = $this->resolveEmailProductForConvert($containerProduct, $emailProduct);
            if (! $emailProduct) {
                throw new \InvalidArgumentException(
                    'Select an Email Hosting plan (or bundle email on the Application Hosting product) so mail can leave DirectAdmin.'
                );
            }
        }

        if (($preflight['has_addon_sites'] ?? false) && ! $acknowledgeAddonSites) {
            throw new \InvalidArgumentException(
                'This DA user has additional live sites. Acknowledge that extra sites launch as sibling containers on the same Application Hosting package. Combined usage above that package is billed as overage.'
            );
        }

        $stack = (string) ($preflight['detected_stack'] ?? 'unknown');
        if ($containerProduct->type !== 'container_hosting') {
            throw new \InvalidArgumentException('Select an Application Hosting product. That plan is billed when the current DirectAdmin term ends.');
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
        $extraSites = $this->extraConvertibleSites($inventory);
        $siteCount = 1 + count($extraSites);
        $share = $siteCount > 1 ? round(1 / $siteCount, 4) : 1.0;

        try {
            $this->assertContainerHostCapacity($service, $containerProduct, $stack, $share);
            $steps[] = 'Container host capacity OK';
            $this->appendConvertStep($service, $steps);

            $steps[] = 'Exporting site files'.$this->exportIncludesDatabaseMessage($stack).' from DirectAdmin';
            $this->appendConvertStep($service, $steps);
            $export = $this->migrator->exportSiteFromDirectAdmin($service, $inventory, $databaseName);

            if (! empty($export['files_export_empty'])) {
                $steps[] = 'Primary docroot missing on DirectAdmin — exported an empty site archive (addon containers carry live files)';
                $this->appendConvertStep($service, $steps);
            }

            if (! empty($export['local_dump'])) {
                $this->migrator->ensureMysqlSidecarForImport($service->fresh());
                $service->refresh();
            }

            $emailServiceId = null;
            if ($mustPullMail && $emailProduct) {
                $steps[] = 'Pulling mailboxes to Mailcow (IMAP sync from DirectAdmin)';
                $this->appendConvertStep($service, $steps);
                $byDomain = $preflight['email']['by_domain'] ?? [];
                if ($byDomain === [] && ($preflight['email']['all'] ?? []) !== []) {
                    foreach ($preflight['email']['all'] as $box) {
                        $domain = strtolower((string) ($box['domain'] ?? ''));
                        if ($domain === '' && str_contains((string) ($box['email'] ?? ''), '@')) {
                            $domain = explode('@', (string) $box['email'], 2)[1] ?? '';
                        }
                        if ($domain === '') {
                            continue;
                        }
                        $byDomain[$domain][] = $box;
                    }
                }
                $mailResult = $this->mailMigrator->pullFromDirectAdminUser(
                    $service,
                    $emailProduct,
                    $byDomain,
                    [
                        'pull_mail' => true,
                        'da_imap_host' => (string) ($daNode->hostname ?? ''),
                        'bundled' => $containerProduct->hasEmailBundle()
                            && (int) $containerProduct->bundled_email_product_id === (int) $emailProduct->id,
                        'project_id' => $service->project_id,
                    ],
                );
                if (! ($mailResult['success'] ?? false)) {
                    throw new \RuntimeException((string) ($mailResult['message'] ?? 'Mail pull to Mailcow failed.'));
                }
                $emailServiceId = isset($mailResult['email_service']) ? (int) $mailResult['email_service']->id : null;
                $steps[] = (string) ($mailResult['message'] ?? 'Mail pull queued.');
                $this->appendConvertStep($service, $steps);
            }

            $creds = $service->getHostingCredentials() ?? [];
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $templateSlug = $this->templateSlugForDetectedStack($stack, $containerProduct);
            $daUsername = (string) ($creds['username'] ?? $meta['username'] ?? $service->external_reference ?? '');

            $meta['da_legacy'] = [
                'username' => $daUsername,
                'domain' => $inventory['domain'],
                'da_node_id' => $daNode->id,
                'docroot' => $inventory['docroot'],
                'app_root' => $inventory['app_root'] ?? $inventory['docroot'],
                'stack' => $stack,
                'addon_sites' => $inventory['sites'] ?? [],
                'databases' => $inventory['databases'] ?? [],
                'converted_at' => now()->toIso8601String(),
                'keep_email_on_da' => false,
                'had_extra_mailboxes' => $preflight['email']['has_extra_mailboxes'],
                'email_service_id' => $emailServiceId,
            ];
            // Preserve panel password in meta if present (deploy overwrites credentials JSON)
            if (! empty($creds['password'])) {
                $meta['da_legacy']['password'] = $creds['password'];
            } elseif (! empty($meta['password'])) {
                $meta['da_legacy']['password'] = $meta['password'];
            }

            if ($extraSites !== []) {
                $meta['project_recipe'] = self::PROJECT_RECIPE_KEY;
                $meta['project_role'] = 'primary';
                $meta['project_role_label'] = (string) ($inventory['domain'] ?? $service->name);
                $meta['project_billing_anchor'] = true;
                $meta['provision_template_slug'] = $templateSlug;
                $meta['language_slug'] = $templateSlug;
                $meta['domain'] = $meta['domain'] ?? $inventory['domain'];
                $meta['resource_share'] = [
                    'cpu' => $share,
                    'memory' => $share,
                ];
            } else {
                $meta['provision_template_slug'] = $templateSlug;
                $meta['language_slug'] = $templateSlug;
            }
            if ($emailServiceId) {
                $meta['bundled_email_service_id'] = $emailServiceId;
            }

            $steps[] = 'Switching service product to Application Hosting (keeping due date; clearing custom price)';
            $this->appendConvertStep($service, $steps);

            $siblingIds = [];
            $primaryHostname = strtolower(trim((string) ($inventory['domain'] ?? $meta['domain'] ?? '')));
            if ($primaryHostname !== '') {
                $meta['domain'] = $primaryHostname;
                $meta['project_role_label'] = $meta['project_role_label'] ?? $primaryHostname;
            }
            DB::transaction(function () use ($service, $containerProduct, $meta, $extraSites, $daNode, $daUsername, $share, $primaryHostname, $inventory, &$siblingIds) {
                $service->update([
                    'product_id' => $containerProduct->id,
                    'provisioning_driver_key' => 'container',
                    'custom_price' => null,
                    'node_id' => null,
                    'status' => 'provisioning',
                    'name' => $primaryHostname !== '' ? mb_substr($primaryHostname, 0, 100) : $service->name,
                    'service_meta' => $meta,
                    // next_due_date + billing_cycle unchanged
                ]);

                if ($extraSites === []) {
                    return;
                }

                $attached = $this->attachConvertProject(
                    $service->fresh(),
                    $containerProduct,
                    $extraSites,
                    (int) $daNode->id,
                    $daUsername,
                    $share,
                    $inventory['databases'] ?? [],
                );
                $siblingIds = $attached['sibling_ids'];
            });

            if ($siblingIds !== []) {
                $this->writeConvertMeta($service, [
                    'sibling_service_ids' => $siblingIds,
                    'project_id' => $service->fresh()->project_id,
                ]);
            }

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

            if ($primaryHostname !== '') {
                $this->attachConvertedHostname($service->fresh(), $primaryHostname);
                $steps[] = 'Bound '.$primaryHostname.' to the Application Hosting container';
                $this->appendConvertStep($service, $steps);
            }

            $renewalPreview = $this->renewalPricing->unitPrice($service->fresh());
            if ($siblingIds !== []) {
                $steps[] = sprintf(
                    'Queuing %d extra live site(s) as sibling containers on this same package (not separately billed)',
                    count($siblingIds)
                );
                $this->appendConvertStep($service, $steps);
                foreach ($siblingIds as $siblingId) {
                    ConvertDirectAdminProjectSiteJob::dispatch((int) $siblingId);
                }
            }
            $projectId = (int) ($service->fresh()->project_id ?? 0);
            if ($emailServiceId && $projectId > 0) {
                Service::query()->whereKey($emailServiceId)->update(['project_id' => $projectId]);
            }
            $addonNote = $siblingIds !== []
                ? sprintf(' %d extra site(s) queued as sibling containers on this package. Combined usage above package specs bills as overage.', count($siblingIds))
                : '';
            $mailNote = $emailServiceId
                ? ' '.trim((string) ($mailResult['message'] ?? 'Mail pulled to Mailcow.'))
                : '';
            $steps[] = sprintf(
                'Convert complete. Next due %s · renewal will bill Application Hosting (~%s).%s%s',
                optional($service->next_due_date)->toDateString() ?? 'n/a',
                number_format($renewalPreview, 2),
                $addonNote,
                $mailNote
            );

            $this->writeConvertMeta($service, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'steps' => $steps,
                'target_product_id' => $containerProduct->id,
                'target_product_name' => $containerProduct->name,
                'renewal_unit_price' => $renewalPreview,
                'renewal_due_date' => optional($service->next_due_date)->toDateString(),
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
                'message' => 'Service converted to Application Hosting. Billing date unchanged. Mail is pulling into Mailcow — update MX when sync has caught up, then DirectAdmin can be decommissioned.',
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
            $siblingIds = $service->service_meta['da_convert']['sibling_service_ids'] ?? [];
            if (is_array($siblingIds) && $siblingIds !== []) {
                Service::query()
                    ->whereIn('id', $siblingIds)
                    ->whereIn('status', ['pending', 'provisioning'])
                    ->whereDoesntHave('containerDeployment')
                    ->delete();
            }

            // Always restore the DA product so convert can be retried. A running
            // container from a failed import is cleaned up on the next deploy.
            $service->update([
                'product_id' => $previous['product_id'],
                'node_id' => $previous['node_id'],
                'provisioning_driver_key' => $previous['provisioning_driver_key'],
                'custom_price' => $previous['custom_price'],
                'status' => $previous['status'] ?: 'active',
                'project_id' => null,
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
            return Carbon::parse($marker)->lt(now()->subMinutes(15));
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

    public function resolveEmailProductForConvert(Product $containerProduct, ?Product $selected = null): ?Product
    {
        if ($selected && $selected->type === 'email_hosting' && $selected->is_active) {
            return $selected;
        }

        $containerProduct->loadMissing('bundledEmailProduct');
        if ($containerProduct->hasEmailBundle() && $containerProduct->bundledEmailProduct?->is_active) {
            return $containerProduct->bundledEmailProduct;
        }

        return Product::query()
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->where('provisioning_driver_key', 'mailcow')
            ->orderBy('order')
            ->orderBy('name')
            ->first();
    }

    /**
     * Fail before the DA export if no container host can take this footprint.
     */
    private function assertContainerHostCapacity(
        Service $service,
        Product $product,
        string $stack,
        float $share,
    ): void {
        $product->loadMissing('containerTemplate');
        $templateSlug = $this->templateSlugForDetectedStack($stack, $product);
        $probe = $service->replicate();
        $probe->id = $service->id;
        $probe->exists = true;
        $probe->product_id = $product->id;
        $probe->node_id = null;
        $probe->setRelation('product', $product);
        $probe->unsetRelation('containerDeployment');
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['provision_template_slug'] = $templateSlug;
        $meta['language_slug'] = $templateSlug;
        $meta['resource_share'] = [
            'cpu' => $share,
            'memory' => $share,
        ];
        $probe->service_meta = $meta;

        if ($probe->effectiveContainerTemplate() === null) {
            throw new \DomainException(
                'Could not resolve a container template for stack "'.$stack.'". '
                .'Ensure container templates exist (static-site, php, etc.) under Admin → Container templates.'
            );
        }

        $this->deployments->assertHostHasCapacity($probe);
    }

    /**
     * Extra live sites on the DA user (not the primary domain).
     *
     * @param  array{sites?: list<array<string, mixed>>}  $inventory
     * @return list<array<string, mixed>>
     */
    public function extraConvertibleSites(array $inventory): array
    {
        $sites = is_array($inventory['sites'] ?? null) ? $inventory['sites'] : [];
        $primary = strtolower(trim((string) ($inventory['domain'] ?? '')));

        return array_values(array_filter(
            $sites,
            function ($site) use ($primary): bool {
                if (! is_array($site) || blank($site['domain'] ?? null)) {
                    return false;
                }
                if (! empty($site['is_primary'])) {
                    return false;
                }

                return $primary === '' || strcasecmp((string) $site['domain'], $primary) !== 0;
            }
        ));
    }

    public function templateSlugForDetectedStack(string $stack, Product $product): string
    {
        $product->loadMissing('containerTemplate');
        $candidates = match ($stack) {
            'wordpress' => ['wordpress'],
            'laravel' => ['laravel'],
            'nodejs' => ['nodejs'],
            'php' => ['php'],
            'static_or_php' => ['static-site', 'php'],
            'unknown' => array_values(array_unique(array_filter([
                $product->containerTemplate?->slug,
                'static-site',
                'php',
            ]))),
            default => array_values(array_unique(array_filter([
                $product->containerTemplate?->slug,
                'static-site',
                'php',
            ]))),
        };

        foreach ($candidates as $slug) {
            if (is_string($slug) && $slug !== '' && ContainerTemplate::query()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        $fallback = ContainerTemplate::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->value('slug');

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        throw new \DomainException(
            'No container templates are configured. Add templates under Admin → Container templates before converting.'
        );
    }

    /**
     * Group the converted primary with extra live sites on one billed package.
     *
     * @param  list<array<string, mixed>>  $extraSites
     * @return array{project: CustomerProject, sibling_ids: list<int>}
     */
    public function attachConvertProject(
        Service $anchor,
        Product $product,
        array $extraSites,
        int $daNodeId,
        string $daUsername,
        float $share,
        array $databases = [],
    ): array {
        $anchor->loadMissing('user');
        $domain = (string) ($anchor->attachedDomainName() ?? $anchor->name);
        $project = CustomerProject::create([
            'user_id' => $anchor->user_id,
            'name' => mb_substr($domain !== '' ? $domain : 'Project', 0, 100),
            'recipe_key' => self::PROJECT_RECIPE_KEY,
            'billing_service_id' => $anchor->id,
            'resource_pool' => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'site_count' => 1 + count($extraSites),
                'cpu_share' => $share,
                'memory_share' => $share,
            ],
        ]);

        $anchor->update(['project_id' => $project->id]);

        $siblingIds = [];
        foreach ($extraSites as $site) {
            $sibling = $this->createSiblingSiteService(
                $anchor->fresh(),
                $project,
                $product,
                $site,
                $share,
                $daNodeId,
                $daUsername,
                $databases,
            );
            $siblingIds[] = (int) $sibling->id;
        }

        return [
            'project' => $project->fresh(),
            'sibling_ids' => $siblingIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $site
     */
    public function createSiblingSiteService(
        Service $anchor,
        CustomerProject $project,
        Product $product,
        array $site,
        float $share,
        int $daNodeId,
        string $daUsername,
        array $databases = [],
    ): Service {
        $domain = strtolower(trim((string) ($site['domain'] ?? '')));
        $stack = $this->normalizeConvertibleStack([
            'stack' => (string) ($site['stack'] ?? 'unknown'),
            'has_wp_config' => (bool) ($site['has_wp_config'] ?? false),
        ]);
        $templateSlug = $this->templateSlugForDetectedStack($stack, $product);

        return Service::query()->create([
            'user_id' => $anchor->user_id,
            'project_id' => $project->id,
            'product_id' => $product->id,
            'reseller_id' => $anchor->reseller_id,
            'name' => mb_substr($domain !== '' ? $domain : 'site', 0, 100),
            'status' => 'pending',
            'billing_cycle' => $anchor->billing_cycle,
            'custom_price' => 0,
            'next_due_date' => $anchor->next_due_date,
            'provisioning_driver_key' => 'container',
            'node_id' => null,
            'service_meta' => [
                'domain' => $domain,
                'project_recipe' => self::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'project_role_label' => $domain,
                'project_billing_anchor' => false,
                'provision_template_slug' => $templateSlug,
                'language_slug' => $templateSlug,
                'resource_share' => [
                    'cpu' => $share,
                    'memory' => $share,
                ],
                'source_service_id' => $anchor->id,
                'da_legacy' => [
                    'username' => $daUsername,
                    'domain' => $domain,
                    'da_node_id' => $daNodeId,
                    'docroot' => $site['docroot'] ?? null,
                    'app_root' => $site['app_root'] ?? ($site['docroot'] ?? null),
                    'stack' => $stack,
                    'has_wp_config' => (bool) ($site['has_wp_config'] ?? false),
                    'databases' => array_values(array_map(
                        fn ($row) => ['name' => (string) ($row['name'] ?? $row)],
                        $databases,
                    )),
                    'keep_email_on_da' => false,
                ],
            ],
        ]);
    }

    /**
     * Export one extra DA site into its already-created sibling Application Hosting service.
     */
    public function convertProjectSite(Service $sibling): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $sibling->loadMissing('product.containerTemplate', 'user');
        $meta = is_array($sibling->service_meta) ? $sibling->service_meta : [];
        $legacy = is_array($meta['da_legacy'] ?? null) ? $meta['da_legacy'] : [];
        $stack = $this->normalizeConvertibleStack([
            'stack' => (string) ($legacy['stack'] ?? 'unknown'),
            'has_wp_config' => (bool) ($legacy['has_wp_config'] ?? false),
        ]);

        $inventory = [
            'domain' => $legacy['domain'] ?? $meta['domain'] ?? $sibling->name,
            'docroot' => $legacy['docroot'] ?? null,
            'app_root' => $legacy['app_root'] ?? ($legacy['docroot'] ?? null),
            'stack' => $stack,
            'has_wp_config' => (bool) ($legacy['has_wp_config'] ?? false),
            'databases' => $this->resolveSiblingDatabaseInventory($sibling, $legacy),
            'da_node_id' => $legacy['da_node_id'] ?? null,
        ];

        $daNode = $this->migrator->resolveDirectAdminNode($sibling, $inventory);

        $this->deployments->assertHostHasCapacity($sibling);

        $export = $this->migrator->exportSiteFromDirectAdmin($sibling, $inventory);

        if (! empty($export['local_dump'])) {
            $this->migrator->ensureMysqlSidecarForImport($sibling->fresh());
            $sibling->refresh();
        }

        try {
            $this->deployments->deploy($sibling, ContainerDeployOptions::quietConvert());
            $sibling->refresh()->load('containerDeployment.node', 'product.containerTemplate');
            $this->migrator->importSiteIntoContainer(
                $sibling,
                $export['local_dump'] ?? null,
                $export['local_tar'],
                $export['remote_work'],
                (string) ($export['stack'] ?? $stack),
                $daNode,
            );
            $this->attachConvertedHostname($sibling->fresh(), (string) ($inventory['domain'] ?? ''));
        } finally {
            foreach (['local_dump', 'local_tar'] as $key) {
                $path = $export[$key] ?? null;
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Show the live hostname on the customer portal and point nginx at this container.
     * DNS/SSL can follow after cutover; a bind failure must not undo the file import.
     */
    public function attachConvertedHostname(Service $service, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return;
        }

        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return;
        }

        $existing = ContainerDomain::query()->where('domain', $hostname)->first();
        if ($existing && (int) $existing->container_deployment_id !== (int) $deployment->id) {
            Log::warning('Convert hostname already bound to another container', [
                'service_id' => $service->id,
                'domain' => $hostname,
                'other_deployment_id' => $existing->container_deployment_id,
            ]);

            return;
        }

        $domain = $existing ?? ContainerDomain::query()->create([
            'container_deployment_id' => $deployment->id,
            'domain' => $hostname,
            'status' => 'pending',
        ]);

        try {
            app(NginxProxyService::class)->bind($domain);
        } catch (\Throwable $e) {
            Log::warning('Convert hostname recorded but nginx bind failed', [
                'service_id' => $service->id,
                'domain' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function exportIncludesDatabaseMessage(string $stack): string
    {
        return match ($stack) {
            'wordpress', 'laravel', 'php', 'nodejs' => ' and database (when credentials resolve)',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return list<array{name: string}>
     */
    public function resolveSiblingDatabaseInventory(Service $sibling, array $legacy): array
    {
        $fromLegacy = $legacy['databases'] ?? [];
        if (is_array($fromLegacy) && $fromLegacy !== []) {
            return array_values(array_filter(array_map(function ($row) {
                $name = is_array($row) ? (string) ($row['name'] ?? '') : (string) $row;

                return $name !== '' ? ['name' => $name] : null;
            }, $fromLegacy)));
        }

        $sourceId = (int) ($sibling->service_meta['source_service_id'] ?? 0);
        if ($sourceId > 0) {
            $anchor = Service::query()->find($sourceId);
            $anchorLegacy = is_array($anchor?->service_meta['da_legacy'] ?? null)
                ? $anchor->service_meta['da_legacy']
                : [];
            $fromAnchor = $anchorLegacy['databases'] ?? [];
            if (is_array($fromAnchor) && $fromAnchor !== []) {
                return array_values(array_filter(array_map(function ($row) {
                    $name = is_array($row) ? (string) ($row['name'] ?? '') : (string) $row;

                    return $name !== '' ? ['name' => $name] : null;
                }, $fromAnchor)));
            }
        }

        return [];
    }
}
