<?php

namespace App\Services\Checkout;

use App\Enums\BillingMode;
use App\Enums\SharedHostingDomainMode;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\UsageBillingProfileService;
use App\Services\Billing\UsageDeployGuardService;
use App\Services\DomainCloudflareDnsService;
use App\Services\DomainInputParser;
use App\Services\DomainTransferService;
use App\Services\NodeNameserverService;
use App\Services\Provisioning\DirectAdminDomainValidator;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerNameserverService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bundle an email hosting service with a platform container product at checkout,
 * and collect register / transfer / existing domain for usage-mode apps.
 */
class ContainerEmailBundleService
{
    public function __construct(
        private DirectAdminDomainValidator $domainValidator,
        private MailcowProvisioningService $mailcow,
        private UsageBillingProfileService $usageProfile,
        private SharedHostingCheckoutService $sharedHostingCheckout,
        private NodeNameserverService $nameserverService,
    ) {}

    /**
     * Usage-billed application cart lines (domain chosen at checkout).
     *
     * @param  array<string, mixed>  $cart
     * @return list<array{key: string, product: Product, email_product: ?Product, usage_billing: bool, billing_cycle: string, name: string}>
     */
    public function usageAppCartItems(array $cart): array
    {
        $items = [];

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product' || empty($item['usage_billing'])) {
                continue;
            }

            $product = Product::query()->with('bundledEmailProduct')->find($item['product_id'] ?? null);
            if (! $product || $product->type !== 'container_hosting') {
                continue;
            }

            $emailProduct = null;
            if ($this->usageProfile->autoIncludeEmail()) {
                $emailProduct = $this->usageProfile->resolveEmailProduct();
            } elseif ($product->hasEmailBundle()) {
                $emailProduct = $product->bundledEmailProduct;
            }

            $items[] = [
                'key' => (string) $key,
                'product' => $product,
                'email_product' => $emailProduct,
                'billing_cycle' => (string) ($item['billing_cycle'] ?? 'monthly'),
                'usage_billing' => true,
                'name' => (string) ($item['name'] ?? $product->name),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return list<array{key: string, product: Product, email_product: Product, usage_billing: bool, billing_cycle: string}>
     */
    public function bundledContainerItems(array $cart): array
    {
        $items = [];

        foreach ($this->usageAppCartItems($cart) as $entry) {
            if (! $entry['email_product'] || $entry['email_product']->type !== 'email_hosting' || ! $entry['email_product']->is_active) {
                // Non-usage containers with product-level email bundle.
                continue;
            }
            $items[] = $entry;
        }

        // Legacy product-level email bundles (non-usage).
        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product' || ! empty($item['usage_billing'])) {
                continue;
            }

            $product = Product::query()->with('bundledEmailProduct')->find($item['product_id'] ?? null);
            if (! $product || $product->type !== 'container_hosting' || ! $product->hasEmailBundle()) {
                continue;
            }

            $emailProduct = $product->bundledEmailProduct;
            if (! $emailProduct || $emailProduct->type !== 'email_hosting' || ! $emailProduct->is_active) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'product' => $product,
                'email_product' => $emailProduct,
                'billing_cycle' => (string) ($item['billing_cycle'] ?? 'monthly'),
                'usage_billing' => false,
                'name' => (string) ($item['name'] ?? $product->name),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function validateCheckoutRequest(Request $request, array $cart): void
    {
        $usageItems = $this->usageAppCartItems($cart);
        $legacyBundled = array_values(array_filter(
            $this->bundledContainerItems($cart),
            fn (array $e) => empty($e['usage_billing'])
        ));

        if ($usageItems === [] && $legacyBundled === []) {
            return;
        }

        if ($usageItems !== []) {
            $this->validateUsageAppDomains($request, $cart, $usageItems);
        }

        if ($legacyBundled !== []) {
            $this->validateLegacyBundleDomains($request, $cart, $legacyBundled);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $cart
     */
    private function validateUsageAppDomains(Request $request, array $cart, array $items): void
    {
        $this->applyLinkedCartDomainModes($request, $cart, $items);

        $rules = [];
        $messages = [
            'app_domain_mode.*.required' => 'Choose how you want to connect a domain to your application.',
            'app_domain_mode.*.in' => 'Invalid domain option selected.',
            'app_domain_added.*.accepted' => 'Check availability and add the domain to your order before placing it.',
        ];

        foreach ($items as $entry) {
            $key = $entry['key'];

            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $key)) {
                $rules["app_domain_mode.{$key}"] = ['required', Rule::in([SharedHostingDomainMode::FromCart->value])];

                continue;
            }

            $rules["app_domain_mode.{$key}"] = ['required', Rule::enum(SharedHostingDomainMode::class)];
            $mode = $request->input("app_domain_mode.{$key}");

            if ($mode === SharedHostingDomainMode::Register->value) {
                $rules["app_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9-]+$/i'];
                $rules["app_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["app_domain_years.{$key}"] = ['required', 'integer', 'min:1', 'max:10'];
                $rules["app_domain_added.{$key}"] = ['accepted'];
            } elseif ($mode === SharedHostingDomainMode::Existing->value) {
                $rules["app_domain_fqdn.{$key}"] = ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'];
            } elseif ($mode === SharedHostingDomainMode::Transfer->value) {
                $rules["app_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'];
                $rules["app_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["app_transfer_epp.{$key}"] = ['required', 'string', 'min:5'];
                $rules["app_transfer_registrar.{$key}"] = ['required', 'string', 'min:2'];
                $rules["app_transfer_registrar_url.{$key}"] = ['nullable', 'url'];
            }
        }

        $request->validate($rules, $messages);

        $guard = app(UsageDeployGuardService::class);
        $user = $request->user() ?? auth()->user();
        if (! $user) {
            throw ValidationException::withMessages([
                'app_domain_mode' => 'You must be signed in to deploy an application.',
            ]);
        }

        foreach ($items as $entry) {
            $key = $entry['key'];
            try {
                $fqdn = $this->resolveFqdnFromRequest($request, $cart, $key);
                $guard->assertCanDeploy($user, $fqdn);
            } catch (ValidationException $e) {
                $errors = $e->errors();
                $mapped = [];
                foreach ($errors as $field => $msgs) {
                    $mapped[$field === 'primary_domain' ? "app_domain_fqdn.{$key}" : $field] = $msgs;
                }
                if ($mapped === []) {
                    $mapped["app_domain_mode.{$key}"] = ['This domain cannot be used right now.'];
                }
                throw ValidationException::withMessages($mapped);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    "app_domain_mode.{$key}" => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $bundled
     * @param  array<string, mixed>  $cart
     */
    private function validateLegacyBundleDomains(Request $request, array $cart, array $bundled): void
    {
        $rules = [];
        $messages = [];

        foreach ($bundled as $entry) {
            $key = $entry['key'];
            $cartDomain = (string) (session('cart.'.$key.'.primary_domain') ?? '');
            if ($cartDomain !== '' && ! $request->filled("bundle_primary_domain.{$key}")) {
                $request->merge([
                    'bundle_primary_domain' => array_merge(
                        (array) $request->input('bundle_primary_domain', []),
                        [$key => $cartDomain]
                    ),
                ]);
            }

            $rules["bundle_primary_domain.{$key}"] = ['required', 'string', 'max:253'];
            $messages["bundle_primary_domain.{$key}.required"] = 'Enter the domain for your application and email.';
        }

        $validated = $request->validate($rules, $messages);

        foreach ($bundled as $entry) {
            $key = $entry['key'];
            $raw = (string) ($validated['bundle_primary_domain'][$key] ?? '');
            try {
                $this->domainValidator->assertValid($raw);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    "bundle_primary_domain.{$key}" => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function estimateDomainAddonTotal(Request $request, array $cart): float
    {
        $total = 0.0;

        foreach ($this->usageAppCartItems($cart) as $item) {
            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $item['key'])) {
                continue;
            }

            $addon = $this->resolveDomainAddon($request, $item['key']);
            if ($addon) {
                $total += $addon['amount'];
            }
        }

        return $total;
    }

    /**
     * @return array{amount: float, description: string, mode: string}|null
     */
    public function resolveDomainAddon(Request $request, string $cartKey): ?array
    {
        $mode = SharedHostingDomainMode::tryFrom((string) $request->input("app_domain_mode.{$cartKey}"));
        if (! $mode) {
            return null;
        }

        if ($mode === SharedHostingDomainMode::Register) {
            if (! $request->boolean("app_domain_added.{$cartKey}")) {
                return null;
            }

            $extension = DomainExtension::where('extension', $request->input("app_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $years = (int) $request->input("app_domain_years.{$cartKey}", 1);
            $amount = app(ResellerCustomerCatalogService::class)->domainRegistrationPrice(
                $request->user(),
                $extension,
                $years,
            ) ?? 0.0;
            $name = strtolower((string) $request->input("app_domain_name.{$cartKey}"));

            return [
                'amount' => $amount,
                'description' => "Domain registration: {$name}{$extension->extension} ({$years} year(s))",
                'mode' => $mode->value,
            ];
        }

        if ($mode === SharedHostingDomainMode::Transfer) {
            $extension = DomainExtension::where('extension', $request->input("app_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $name = strtolower((string) $request->input("app_domain_name.{$cartKey}"));

            return [
                'amount' => (float) ($extension->transfer_price ?? 0),
                'description' => "Domain transfer: {$name}{$extension->extension}",
                'mode' => $mode->value,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array{fqdn: string, service_meta: array<string, mixed>, invoice_items: list<array<string, mixed>>}
     */
    public function buildAppDomainContext(
        Request $request,
        string $cartKey,
        User $user,
        Invoice $invoice,
        Order $order,
        array $cart = [],
        array $domainsCreatedByCartKey = [],
    ): array {
        $linkedDomain = $this->sharedHostingCheckout->linkedDomainDetails($cart, $cartKey);
        $mode = $linkedDomain
            ? SharedHostingDomainMode::FromCart
            : SharedHostingDomainMode::from((string) $request->input("app_domain_mode.{$cartKey}"));
        $invoiceItems = [];

        $fqdn = $this->resolveFqdnFromRequest($request, $cart, $cartKey);
        app(UsageDeployGuardService::class)->assertCanDeploy($user, $fqdn);
        $cloudflareAvailable = app(DomainCloudflareDnsService::class)->isAvailableForCustomer($user);
        $nameservers = app(ResellerNameserverService::class)->defaultsForCustomer($user);
        $domainNameservers = $this->nameserverService->toDomainColumns($nameservers);

        $serviceMeta = [
            'primary_domain' => $fqdn,
            'domain' => $fqdn,
            'app_domain_mode' => $mode->value,
        ];

        if ($mode === SharedHostingDomainMode::FromCart) {
            $serviceMeta['linked_domain_cart_key'] = $linkedDomain['cart_key'];
            $serviceMeta['domain_registration_years'] = $linkedDomain['years'];
            $serviceMeta['cloudflare_dns'] = true;
            if (isset($domainsCreatedByCartKey[$linkedDomain['cart_key']])) {
                $serviceMeta['domain_id'] = $domainsCreatedByCartKey[$linkedDomain['cart_key']];
            }
        } elseif ($mode === SharedHostingDomainMode::Register) {
            $years = (int) $request->input("app_domain_years.{$cartKey}", 1);
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $pricing = $extension->getRetailPricing($years);
            $amount = $pricing ? (float) $pricing->price : 0.0;

            $domain = Domain::create([
                'user_id' => $user->id,
                'name' => $parts['name'],
                'extension' => $parts['extension'],
                'status' => 'pending',
                'cloudflare_dns_enabled' => $cloudflareAvailable,
                ...$domainNameservers,
            ]);

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['domain_registration_years'] = $years;
            $serviceMeta['cloudflare_dns'] = $cloudflareAvailable;

            if ($amount > 0) {
                $invoiceItems[] = [
                    'description' => "Domain registration: {$fqdn} ({$years} year(s))",
                    'amount' => $amount,
                    'meta' => [
                        'type' => 'domain_registration',
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                        'years' => $years,
                    ],
                ];
            }
        } elseif ($mode === SharedHostingDomainMode::Existing) {
            $owned = $this->findOwnedDomain($user, $fqdn);
            if ($owned) {
                $serviceMeta['domain_id'] = $owned->id;
                if ($owned->cloudflare_dns_enabled && filled($owned->cloudflare_zone_id)) {
                    $serviceMeta['cloudflare_dns'] = true;
                }
            } else {
                $serviceMeta['nameservers'] = $nameservers;
                $serviceMeta['nameserver_instructions'] = 'Point this domain’s DNS (or nameservers) to Talksasa so your app and email can go live.';
            }
        } elseif ($mode === SharedHostingDomainMode::Transfer) {
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $transferPrice = (float) ($extension->transfer_price ?? 0);

            $domain = DomainTransferService::createTransferRequest(
                $user,
                $parts['name'],
                $parts['extension'],
                (string) $request->input("app_transfer_epp.{$cartKey}"),
                (string) $request->input("app_transfer_registrar.{$cartKey}"),
                $request->input("app_transfer_registrar_url.{$cartKey}")
            );

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['transfer_pending'] = true;
            $serviceMeta['cloudflare_dns'] = $cloudflareAvailable;

            if ($transferPrice > 0) {
                $invoiceItems[] = [
                    'description' => "Domain transfer: {$fqdn}",
                    'amount' => $transferPrice,
                    'meta' => [
                        'type' => 'domain_transfer',
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                    ],
                ];
            }
        }

        $this->sharedHostingCheckout->persistExtraInvoiceItems($invoice, $order, $invoiceItems);

        // Keep cart meta in sync for email attach / finalize helpers.
        $request->merge([
            'bundle_primary_domain' => array_merge(
                (array) $request->input('bundle_primary_domain', []),
                [$cartKey => $fqdn]
            ),
        ]);

        return [
            'fqdn' => $fqdn,
            'service_meta' => $serviceMeta,
            'invoice_items' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function primaryDomainForCartKey(Request $request, string $cartKey, array $cart = []): string
    {
        if ($request->filled("bundle_primary_domain.{$cartKey}")) {
            return $this->domainValidator->assertValid((string) $request->input("bundle_primary_domain.{$cartKey}"));
        }

        $sessionCart = $cart !== [] ? $cart : session('cart', []);
        if ($this->sharedHostingCheckout->hasLinkedDomainInCart($sessionCart, $cartKey)
            || $request->filled("app_domain_mode.{$cartKey}")) {
            return $this->resolveFqdnFromRequest($request, $sessionCart, $cartKey);
        }

        $raw = (string) (session("cart.{$cartKey}.primary_domain") ?? '');
        if ($raw !== '') {
            return $this->domainValidator->assertValid($raw);
        }

        throw ValidationException::withMessages([
            "app_domain_mode.{$cartKey}" => 'Choose a domain for your application.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    private function resolveFqdnFromRequest(Request $request, array $cart, string $cartKey): string
    {
        $linkedDomain = $this->sharedHostingCheckout->linkedDomainDetails($cart, $cartKey);
        if ($linkedDomain) {
            return $this->domainValidator->assertValid($linkedDomain['fqdn']);
        }

        $mode = SharedHostingDomainMode::from((string) $request->input("app_domain_mode.{$cartKey}"));

        return match ($mode) {
            SharedHostingDomainMode::Register, SharedHostingDomainMode::Transfer => $this->fqdnFromParts(
                (string) $request->input("app_domain_name.{$cartKey}"),
                (string) $request->input("app_domain_extension.{$cartKey}")
            ),
            SharedHostingDomainMode::Existing => $this->domainValidator->assertValid(
                (string) $request->input("app_domain_fqdn.{$cartKey}")
            ),
            SharedHostingDomainMode::FromCart => throw new \RuntimeException('Linked cart domain is missing.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $cart
     */
    private function applyLinkedCartDomainModes(Request $request, array $cart, array $items): void
    {
        $merge = [];

        foreach ($items as $item) {
            $key = $item['key'];
            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $key)) {
                $merge["app_domain_mode.{$key}"] = SharedHostingDomainMode::FromCart->value;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function findOwnedDomain(User $user, string $fqdn): ?Domain
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));

        return Domain::query()
            ->where('user_id', $user->id)
            ->get()
            ->first(fn (Domain $domain) => strtolower($domain->fqdn()) === $fqdn);
    }

    private function fqdnFromParts(string $name, string $extension): string
    {
        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $parsed = app(DomainInputParser::class)->parse($name, $extension, $allowedExtensions);

        if ($parsed !== null) {
            return $this->domainValidator->assertValid($parsed['name'].$parsed['extension']);
        }

        $name = strtolower(trim($name));
        $extension = str_starts_with($extension, '.') ? $extension : '.'.$extension;

        return $this->domainValidator->assertValid($name.$extension);
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function estimateInvoiceAddonTotal(array $cart): float
    {
        $total = 0.0;

        foreach ($this->bundledContainerItems($cart) as $entry) {
            /** @var Product $containerProduct */
            $containerProduct = $entry['product'];

            if (! empty($entry['usage_billing'])) {
                continue;
            }

            if (! $containerProduct->bundle_email_include_in_invoice) {
                continue;
            }

            $cycle = $this->resolveBillingCycle($containerProduct, $entry['billing_cycle']);
            $total += $this->priceForCycle($entry['email_product'], $cycle);
        }

        return round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $containerCartItem
     */
    public function attachToContainerService(
        Request $request,
        string $cartKey,
        User $user,
        Product $containerProduct,
        Service $containerService,
        Invoice $invoice,
        Order $order,
        array $containerCartItem,
    ): ?Service {
        $containerProduct->loadMissing('bundledEmailProduct');

        $usageBilling = ! empty($containerCartItem['usage_billing'])
            || $this->usageProfile->serviceUsesUsageBilling($containerService);

        $emailProduct = null;
        if ($usageBilling && $this->usageProfile->autoIncludeEmail()) {
            $emailProduct = $this->usageProfile->resolveEmailProduct();
        } elseif ($containerProduct->hasEmailBundle()) {
            $emailProduct = $containerProduct->bundledEmailProduct;
        }

        if (! $emailProduct || $emailProduct->type !== 'email_hosting') {
            return null;
        }

        $fqdn = $this->primaryDomainForCartKey($request, $cartKey);
        $billingCycle = $usageBilling
            ? 'monthly'
            : $this->resolveBillingCycle($containerProduct, (string) ($containerCartItem['billing_cycle'] ?? 'monthly'));

        $included = $usageBilling
            ? $this->usageProfile->includedLimits()
            : $this->mailcow->limitsForProduct($emailProduct);

        $unitPrice = $usageBilling ? 0.0 : $this->priceForCycle($emailProduct, $billingCycle);
        $delayMonths = $usageBilling ? 0 : max(0, (int) ($containerProduct->bundle_email_billing_delay_months ?? 0));
        $cycleMonths = $this->billingCycleMonths($billingCycle);
        $mailNode = $this->mailcow->resolveNode();

        $serviceMeta = [
            'mailcow_domain' => $fqdn,
            'domain' => $fqdn,
            'email_domain_mode' => $usageBilling ? 'bundled_with_usage_hosting' : 'bundled_with_container',
            'mailbox_limit' => (int) ($included['mailboxes'] ?? 5),
            'alias_limit' => (int) ($included['aliases'] ?? 10),
            'mailbox_quota_mb' => (int) ($included['mailbox_quota_mb'] ?? 5120),
            'quota_mb' => (int) ($included['quota_mb'] ?? 25600),
            'msgs_per_day' => (int) ($included['msgs_per_day'] ?? 500),
            'bundled_from_service_id' => $containerService->id,
            'bundled_from_product_id' => $containerProduct->id,
            'bundle_include_in_invoice' => $usageBilling ? false : (bool) $containerProduct->bundle_email_include_in_invoice,
        ];

        $emailService = Service::create([
            'user_id' => $user->id,
            'product_id' => $emailProduct->id,
            'order_item_id' => $containerService->order_item_id,
            'invoice_id' => $invoice->id,
            'reseller_id' => $containerService->reseller_id,
            'name' => $emailProduct->name.' ('.$fqdn.')',
            'status' => 'pending',
            'billing_cycle' => $billingCycle,
            'billing_mode' => $usageBilling ? BillingMode::Usage : BillingMode::Package,
            'custom_price' => $unitPrice,
            'included_limits' => $usageBilling ? $this->usageProfile->includedLimits() : null,
            'usage_rates' => $usageBilling ? $this->usageProfile->usageRates() : null,
            'next_due_date' => now()->addMonths($delayMonths + $cycleMonths),
            'provisioning_driver_key' => $emailProduct->provisioning_driver_key ?: 'mailcow',
            'node_id' => $mailNode?->id,
            'service_meta' => $serviceMeta,
        ]);

        $meta = $containerService->service_meta ?? [];
        $meta['primary_domain'] = $fqdn;
        $meta['bundled_email_service_id'] = $emailService->id;
        $containerService->update(['service_meta' => $meta]);

        if (! $usageBilling && $containerProduct->bundle_email_include_in_invoice) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $emailService->id,
                'product_id' => $emailProduct->id,
                'description' => $emailProduct->name.' (bundled with '.$containerProduct->name.')',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'amount' => $unitPrice,
            ]);
        }

        return $emailService;
    }

    public function resolveBillingCycle(Product $containerProduct, string $containerCycle): string
    {
        $override = $containerProduct->bundle_email_billing_cycle;

        if (in_array($override, ['monthly', 'annual'], true)) {
            return $override;
        }

        return in_array($containerCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)
            ? ($containerCycle === 'annual' ? 'annual' : (str_starts_with($containerCycle, 'month') ? 'monthly' : $containerCycle))
            : 'monthly';
    }

    public function priceForCycle(Product $emailProduct, string $billingCycle): float
    {
        if ($billingCycle === 'annual') {
            return (float) ($emailProduct->yearly_price ?? $emailProduct->monthly_price ?? $emailProduct->price ?? 0);
        }

        return (float) ($emailProduct->monthly_price ?? $emailProduct->price ?? 0);
    }

    public function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }
}
