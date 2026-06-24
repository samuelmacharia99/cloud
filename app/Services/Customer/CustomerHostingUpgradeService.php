<?php

namespace App\Services\Customer;

use App\Enums\ServiceStatus;
use App\Models\DirectAdminPackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Hosting\ServicePackageLimitEnforcementService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\NotificationService;
use App\Services\Provisioning\DirectAdminSetupService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerEnforcementService;
use App\Services\TaxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerHostingUpgradeService
{
    public function __construct(
        private DirectAdminSetupService $directAdminSetup,
        private ResellerCustomerCatalogService $catalog,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private NotificationService $notifications,
    ) {}

    /**
     * @return Collection<int, Product>
     */
    public function upgradeOptions(Service $service, User $customer): Collection
    {
        if ($service->user_id !== $customer->id) {
            return collect();
        }

        return $this->upgradeOptionsForService($service);
    }

    /**
     * @return Collection<int, array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int
     * }>
     */
    public function planChangeOptions(Service $service, User $customer): Collection
    {
        if ($service->user_id !== $customer->id) {
            return collect();
        }

        return $this->planChangeOptionsForService($service);
    }

    public function planChangeEmptyReason(Service $service, User $customer): ?string
    {
        if ($service->user_id !== $customer->id) {
            return 'You do not have access to change this service plan.';
        }

        $service->loadMissing('product', 'node', 'user');

        if ($service->product?->type !== 'shared_hosting') {
            return 'Only shared hosting services can change plans here.';
        }

        if (! in_array($service->status->value, ['active', 'suspended'], true)) {
            return 'Plan changes are only available for active or suspended hosting services.';
        }

        if ($this->resolveServiceNodeId($service) === null) {
            return 'This service is not linked to a hosting server yet. Contact support to finish setup.';
        }

        if ($this->planChangeOptionsForService($service)->isEmpty()) {
            if ($this->catalog->isResellerCustomer($customer)) {
                return 'Your provider has no other shared hosting plans on this server. Ask them to publish more plans in their catalog.';
            }

            return 'There are no other shared hosting plans on this server besides your current plan.';
        }

        return null;
    }

    /**
     * @return Collection<int, array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int
     * }>
     */
    public function planChangeOptionsForService(Service $service): Collection
    {
        $service->loadMissing('product.directAdminPackage', 'node', 'user', 'reseller');

        if ($service->product?->type !== 'shared_hosting') {
            return collect();
        }

        if (! in_array($service->status->value, ['active', 'suspended'], true)) {
            return collect();
        }

        $nodeId = $this->resolveServiceNodeId($service);
        if ($nodeId === null) {
            return collect();
        }

        $customer = $service->user;
        if (! $customer) {
            return collect();
        }

        $cycle = $service->billing_cycle ?? 'monthly';
        $currentListing = $this->currentListingForService($service, $customer);
        $currentPrice = $this->currentPlanPrice($service, $customer, $cycle, $currentListing);
        $currentOrder = (int) ($service->product->order ?? 0);
        $currentListingId = (int) ($service->service_meta['reseller_product_id'] ?? 0);

        $options = $this->catalog->isResellerCustomer($customer)
            ? $this->resellerPlanChangeCandidates($service, $customer, $nodeId, $currentListingId)
            : $this->platformPlanChangeCandidates($service, $nodeId);

        return $options
            ->map(function (array $candidate) use ($service, $customer, $cycle, $currentPrice, $currentOrder, $currentListingId) {
                /** @var Product $product */
                $product = $candidate['product'];
                /** @var ?ResellerProduct $listing */
                $listing = $candidate['listing'] ?? null;
                $listingId = $listing?->id;

                if ($listingId !== null && $listingId === $currentListingId) {
                    return null;
                }

                if ($listingId === null && (int) $product->id === (int) $service->product_id) {
                    return null;
                }

                $targetPrice = $listing
                    ? $this->effectiveListingCyclePrice($listing, $cycle)
                    : $this->effectiveCyclePrice($customer, $product, $cycle);
                $targetOrder = (int) ($product->order ?? 0);

                return [
                    'product' => $product,
                    'listing' => $listing,
                    'reseller_product_id' => $listingId,
                    'name' => $listing?->name ?? $product->name,
                    'change_type' => $this->resolveChangeType($currentPrice, $currentOrder, $targetPrice, $targetOrder),
                    'display_price' => $targetPrice,
                    'disk_quota' => $candidate['disk_quota'] ?? null,
                    'bandwidth_quota' => $candidate['bandwidth_quota'] ?? null,
                    'num_databases' => $candidate['num_databases'] ?? null,
                ];
            })
            ->filter()
            ->sortBy(fn (array $option) => match ($option['change_type']) {
                'upgrade' => 0,
                'lateral' => 1,
                default => 2,
            })
            ->sortBy('display_price')
            ->values();
    }

    /**
     * @return Collection<int, Product>
     */
    public function upgradeOptionsForService(Service $service): Collection
    {
        return $this->planChangeOptionsForService($service)
            ->filter(fn (array $option) => in_array($option['change_type'], ['upgrade', 'lateral'], true))
            ->map(fn (array $option) => $option['product'])
            ->values();
    }

    /**
     * @param  array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int
     * }  $option
     */
    public function assertValidPlanChangeTarget(Service $service, array $option): void
    {
        $valid = $this->planChangeOptionsForService($service)->contains(function (array $candidate) use ($option) {
            if ((int) $candidate['product']->id !== (int) $option['product']->id) {
                return false;
            }

            return ($candidate['reseller_product_id'] ?? null) === ($option['reseller_product_id'] ?? null);
        });

        if (! $valid) {
            throw new \InvalidArgumentException('Selected plan is not available for this service on its current server.');
        }
    }

    public function assertValidUpgradeTarget(Service $service, Product $targetProduct): void
    {
        if (! $this->upgradeOptionsForService($service)->contains('id', $targetProduct->id)) {
            throw new \InvalidArgumentException('Selected plan is not a valid upgrade for this service on its current server.');
        }
    }

    public function findPlanChangeOption(Service $service, int $productId, ?int $resellerProductId = null): ?array
    {
        return $this->planChangeOptionsForService($service)->first(function (array $option) use ($productId, $resellerProductId) {
            if ((int) $option['product']->id !== $productId) {
                return false;
            }

            $optionListingId = $option['reseller_product_id'] ?? null;

            return $resellerProductId === null
                ? $optionListingId === null
                : $optionListingId === $resellerProductId;
        });
    }

    public function recommendedUpgrade(Service $service, User $customer, ?string $limitingMetric = null): ?Product
    {
        return $this->recommendedPlanOption($service, $customer, $limitingMetric)['product'] ?? null;
    }

    public function recommendedPlanOption(Service $service, User $customer, ?string $limitingMetric = null): ?array
    {
        $options = $this->planChangeOptions($service, $customer)
            ->filter(fn (array $option) => in_array($option['change_type'], ['upgrade', 'lateral'], true));

        if ($options->isEmpty()) {
            return null;
        }

        return match ($limitingMetric) {
            'database' => $options->sortBy(fn (array $option) => $option['num_databases'] ?? 0)->first(),
            'bandwidth' => $options->sortBy(fn (array $option) => (float) ($option['bandwidth_quota'] ?? 0))->first(),
            default => $options->sortBy(fn (array $option) => (float) ($option['disk_quota'] ?? 0))->first(),
        };
    }

    public function createUpgradeInvoice(Service $service, User $customer, Product $targetProduct, ?int $resellerProductId = null): Invoice
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('Unauthorized service upgrade.');
        }

        $option = $this->findPlanChangeOption($service, $targetProduct->id, $resellerProductId);

        if (! $option) {
            throw new \InvalidArgumentException('Selected plan is not available for this service.');
        }

        return $this->createPlanChangeInvoice($service, $customer, $option);
    }

    /**
     * @param  array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int
     * }  $option
     */
    public function createPlanChangeInvoice(Service $service, User $customer, array $option): Invoice
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('Unauthorized service plan change.');
        }

        $targetProduct = $option['product'];

        if ($targetProduct->type !== 'shared_hosting') {
            throw new \InvalidArgumentException('Target product is not a shared hosting plan.');
        }

        $this->assertValidPlanChangeTarget($service, $option);

        $price = $this->proratedPlanChangePrice($service, $customer, $option);
        $taxBreakdown = TaxService::calculateForUser($price, $service->user);
        $tax = $taxBreakdown['tax'];
        $total = $taxBreakdown['total'];
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $changeLabel = match ($option['change_type']) {
            'downgrade' => 'Hosting plan change (downgrade)',
            'lateral' => 'Hosting plan change',
            default => 'Hosting upgrade',
        };

        $invoice = DB::transaction(function () use ($service, $customer, $targetProduct, $option, $prefix, $price, $tax, $total, $dueDate, $taxBreakdown, $changeLabel) {
            $year = now()->format('Y');
            $sequence = Invoice::whereYear('created_at', $year)->lockForUpdate()->count() + 1;
            $number = $prefix.'-'.$year.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => $dueDate,
                'subtotal' => $taxBreakdown['subtotal'],
                'tax' => $tax,
                'total' => $total,
                'notes' => "{$changeLabel}: {$service->product->name} → {$option['name']}",
            ]);

            $customOptions = [
                'hosting_upgrade' => true,
                'hosting_plan_change' => true,
                'change_type' => $option['change_type'],
                'from_product_id' => $service->product_id,
                'to_product_id' => $targetProduct->id,
            ];

            if (! empty($option['reseller_product_id'])) {
                $customOptions['to_reseller_product_id'] = $option['reseller_product_id'];
            }

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $targetProduct->id,
                'description' => "Change to {$option['name']}",
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'custom_options' => $customOptions,
            ]);

            return $invoice;
        });

        $this->notifications->notifyInvoiceGenerated($invoice->fresh(['user', 'items']));

        return $invoice;
    }

    public function applyPaidUpgradesForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items.service.product', 'items.product');

        foreach ($invoice->items as $item) {
            $options = $item->custom_options ?? [];

            if (empty($options['hosting_upgrade']) || empty($options['to_product_id'])) {
                continue;
            }

            $service = $item->service;
            $targetProduct = $item->product ?? Product::find($options['to_product_id']);

            if (! $service || ! $targetProduct) {
                continue;
            }

            $listing = ! empty($options['to_reseller_product_id'])
                ? ResellerProduct::find($options['to_reseller_product_id'])
                : null;

            try {
                $this->applyPlanChange($service, $targetProduct, $listing);
            } catch (\Throwable $e) {
                Log::error('Failed to apply hosting upgrade after payment', [
                    'invoice_id' => $invoice->id,
                    'service_id' => $service->id,
                    'target_product_id' => $targetProduct->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function applyUpgrade(Service $service, Product $targetProduct, ?ResellerProduct $listing = null): void
    {
        $this->applyPlanChange($service, $targetProduct, $listing);
    }

    public function applyPlanChange(Service $service, Product $targetProduct, ?ResellerProduct $listing = null): void
    {
        $service->loadMissing('node', 'reseller', 'product.directAdminPackage', 'user');

        if (! $service->node || $service->node->type !== 'directadmin') {
            throw new \RuntimeException('Service is not on a DirectAdmin node.');
        }

        $previousProduct = $service->product;
        if (! $previousProduct) {
            throw new \RuntimeException('Current product not found on service.');
        }

        $meta = $service->service_meta ?? [];
        $username = $meta['username'] ?? $service->external_reference;

        if (! $username) {
            throw new \RuntimeException('Hosting username not found on service.');
        }

        $directAdmin = $this->resellerDirectAdmin->directAdminForService($service);
        if (! $directAdmin) {
            throw new \RuntimeException('DirectAdmin API is not configured for this service.');
        }

        $ownerReseller = $this->resellerDirectAdmin->impersonationUsernameForService($service);

        if ($listing?->usesDirectAdminPackage()) {
            $packageApiName = (string) $listing->direct_admin_package_name;
            $result = $directAdmin->changeUserPackage($username, $packageApiName);

            if (! $result['success']) {
                throw new \RuntimeException($result['message']);
            }

            $meta = array_merge($meta, $listing->directAdminPackageMeta(), [
                'reseller_product_id' => $listing->id,
            ]);
        } else {
            $targetProduct->loadMissing('directAdminPackage');
            $package = $targetProduct->directAdminPackage;

            if (! $package) {
                throw new \RuntimeException('Target product has no DirectAdmin package.');
            }

            $nodeId = $this->resolveServiceNodeId($service);
            if ($nodeId === null || (int) $package->node_id !== $nodeId) {
                throw new \RuntimeException('Target plan is not available on this service\'s DirectAdmin server.');
            }

            $this->directAdminSetup->ensurePackageOnServer($directAdmin, $package, $ownerReseller);

            $result = $directAdmin->changeUserPackage($username, $package->package_key);

            if (! $result['success']) {
                throw new \RuntimeException($result['message']);
            }

            $meta['package'] = $package->package_key;
            $meta['package_name'] = $package->name;

            if ($listing) {
                $meta['reseller_product_id'] = $listing->id;
            } else {
                unset($meta['reseller_product_id']);
            }
        }

        $wasPackageSuspended = in_array(
            $meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null,
            [
                ResellerEnforcementService::REASON_PACKAGE_OVERQUOTA,
                ResellerEnforcementService::REASON_DISK_OVERQUOTA,
            ],
            true,
        );

        $meta = $this->clearPackageLimitEnforcementMeta($meta);

        $service->update([
            'product_id' => $targetProduct->id,
            'provisioning_driver_key' => 'directadmin',
            'service_meta' => $meta,
        ]);

        $fresh = $service->fresh(['user', 'product', 'node']);
        $this->refreshUsageAfterUpgrade($fresh, $wasPackageSuspended);

        $this->notifications->notifyHostingUpgradeCompleted(
            $fresh,
            $previousProduct,
            $targetProduct,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function clearPackageLimitEnforcementMeta(array $meta): array
    {
        unset(
            $meta[ServicePackageUsageService::META_KEY],
            $meta['package_overlimit_metrics'],
            $meta['package_overlimit_at'],
            $meta['disk_used_mb'],
            $meta['disk_limit_mb'],
            $meta['disk_suspended_at'],
        );

        if (in_array($meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null, [
            ResellerEnforcementService::REASON_PACKAGE_OVERQUOTA,
            ResellerEnforcementService::REASON_DISK_OVERQUOTA,
        ], true)) {
            unset($meta[ResellerEnforcementService::META_SUSPENSION_REASON]);
        }

        return $meta;
    }

    private function refreshUsageAfterUpgrade(Service $service, bool $wasPackageSuspended): void
    {
        $usage = app(ServicePackageUsageService::class);
        $snapshot = $usage->syncFromDirectAdmin($service);

        if ($snapshot === null || ! $wasPackageSuspended || $service->status !== ServiceStatus::Suspended) {
            return;
        }

        $enforcement = app(ServicePackageLimitEnforcementService::class);
        if ($enforcement->metricsOverLimit($snapshot) !== []) {
            return;
        }

        try {
            $enforcement->tryRestoreFromPackageOverlimit($service->fresh());
        } catch (\Throwable $e) {
            Log::warning('Hosting upgrade completed but automatic unsuspend failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function displayPriceForCycle(User $customer, Product $product, string $cycle): float
    {
        return $this->effectiveCyclePrice($customer, $product, $cycle);
    }

    public function displayPriceForPlanOption(User $customer, array $option, string $cycle): float
    {
        if (! empty($option['listing']) && $option['listing'] instanceof ResellerProduct) {
            return $this->effectiveListingCyclePrice($option['listing'], $cycle);
        }

        return $this->effectiveCyclePrice($customer, $option['product'], $cycle);
    }

    private function resolveServiceNodeId(Service $service): ?int
    {
        if ($service->node_id) {
            return (int) $service->node_id;
        }

        $meta = $service->service_meta ?? [];
        $metaNodeId = $meta['node_id'] ?? null;

        return $metaNodeId ? (int) $metaNodeId : null;
    }

    private function currentListingForService(Service $service, User $customer): ?ResellerProduct
    {
        $listingId = (int) ($service->service_meta['reseller_product_id'] ?? 0);

        if ($listingId > 0) {
            return ResellerProduct::query()
                ->where('id', $listingId)
                ->where('reseller_id', $customer->reseller_id)
                ->first();
        }

        if (! $this->catalog->isResellerCustomer($customer)) {
            return null;
        }

        return $this->catalog->findListingForProduct($customer, (int) $service->product_id);
    }

    private function currentPlanPrice(Service $service, User $customer, string $cycle, ?ResellerProduct $listing): float
    {
        if ($listing) {
            return $this->effectiveListingCyclePrice($listing, $cycle);
        }

        return $this->effectiveCyclePrice($customer, $service->product, $cycle);
    }

    /**
     * @return Collection<int, array{product: Product, listing: ?ResellerProduct, disk_quota: ?float, bandwidth_quota: mixed, num_databases: ?int}>
     */
    private function platformPlanChangeCandidates(Service $service, int $nodeId): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->whereHas('directAdminPackage', fn ($package) => $package->where('node_id', $nodeId))
            ->with('directAdminPackage')
            ->orderBy('monthly_price')
            ->get()
            ->map(fn (Product $product) => [
                'product' => $product,
                'listing' => null,
                'disk_quota' => $product->directAdminPackage?->disk_quota,
                'bandwidth_quota' => $product->directAdminPackage?->bandwidth_quota,
                'num_databases' => $product->directAdminPackage?->num_databases,
            ]);
    }

    /**
     * @return Collection<int, array{product: Product, listing: ?ResellerProduct, disk_quota: ?float, bandwidth_quota: mixed, num_databases: ?int}>
     */
    private function resellerPlanChangeCandidates(Service $service, User $customer, int $nodeId, int $currentListingId): Collection
    {
        return $this->catalog->activeCatalog($customer)
            ->filter(fn (ResellerProduct $listing) => $listing->type === 'shared_hosting')
            ->filter(fn (ResellerProduct $listing) => $listing->isOrderable())
            ->filter(fn (ResellerProduct $listing) => $listing->id !== $currentListingId)
            ->map(function (ResellerProduct $listing) use ($nodeId) {
                $product = $listing->provisionProduct();
                if (! $product) {
                    return null;
                }

                if ($listing->usesDirectAdminPackage()) {
                    return [
                        'product' => $product,
                        'listing' => $listing,
                        'disk_quota' => null,
                        'bandwidth_quota' => null,
                        'num_databases' => null,
                    ];
                }

                $package = $listing->adminProduct?->directAdminPackage;
                if (! $package || (int) $package->node_id !== $nodeId) {
                    return null;
                }

                return [
                    'product' => $product,
                    'listing' => $listing,
                    'disk_quota' => $package->disk_quota,
                    'bandwidth_quota' => $package->bandwidth_quota,
                    'num_databases' => $package->num_databases,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return 'upgrade'|'downgrade'|'lateral'
     */
    private function resolveChangeType(float $currentPrice, int $currentOrder, float $targetPrice, int $targetOrder): string
    {
        if ($targetPrice > $currentPrice) {
            return 'upgrade';
        }

        if ($targetPrice < $currentPrice) {
            return 'downgrade';
        }

        return $targetOrder > $currentOrder ? 'upgrade' : ($targetOrder < $currentOrder ? 'downgrade' : 'lateral');
    }

    private function effectiveListingCyclePrice(ResellerProduct $listing, string $cycle): float
    {
        return match ($cycle) {
            'annual' => (float) ($listing->yearly_price ?: $listing->monthly_price * 12),
            default => (float) $listing->monthly_price,
        };
    }

    /**
     * @param  array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float
     * }  $option
     */
    private function proratedPlanChangePrice(Service $service, User $customer, array $option): float
    {
        if ($option['change_type'] === 'downgrade') {
            return 0.0;
        }

        $cycle = $service->billing_cycle ?? 'monthly';
        $currentListing = $this->currentListingForService($service, $customer);
        $current = $this->currentPlanPrice($service, $customer, $cycle, $currentListing);
        $target = (float) $option['display_price'];
        $diff = max(0, $target - $current);

        if ($diff <= 0) {
            return 0.0;
        }

        if (! $service->next_due_date || $service->next_due_date->isPast()) {
            return round($diff, 2);
        }

        $daysRemaining = max(1, now()->diffInDays($service->next_due_date));
        $cycleDays = $cycle === 'annual' ? 365 : 30;

        return round($diff * ($daysRemaining / $cycleDays), 2);
    }

    private function isViableResourceUpgrade(DirectAdminPackage $current, DirectAdminPackage $candidate): bool
    {
        return $this->quotaIsNotReduced($current->disk_quota, $candidate->disk_quota)
            && $this->quotaIsNotReduced($current->bandwidth_quota, $candidate->bandwidth_quota, treatUnlimitedAs: true)
            && (int) $candidate->num_databases >= (int) $current->num_databases;
    }

    /**
     * When the current plan has unlimited quota, do not block upgrades based on that metric.
     */
    private function quotaIsNotReduced(mixed $current, mixed $candidate, bool $treatUnlimitedAs = false): bool
    {
        if ($treatUnlimitedAs && ($current === null || (float) $current < 0)) {
            return true;
        }

        return (float) $candidate >= (float) $current;
    }

    private function cyclePrice(Product $product, string $cycle): float
    {
        return match ($cycle) {
            'annual' => (float) ($product->yearly_price ?: $product->monthly_price * 12),
            default => (float) $product->monthly_price,
        };
    }

    private function effectiveCyclePrice(User $customer, Product $product, string $cycle): float
    {
        if ($this->catalog->isResellerCustomer($customer)) {
            $listing = $this->catalog->findListingForProduct($customer, $product->id);

            if ($listing) {
                return match ($cycle) {
                    'annual' => (float) ($listing->yearly_price ?: $listing->monthly_price * 12),
                    default => (float) $listing->monthly_price,
                };
            }
        }

        return $this->cyclePrice($product, $cycle);
    }

    private function isHigherTier(DirectAdminPackage $current, ?DirectAdminPackage $candidate): bool
    {
        if (! $candidate) {
            return false;
        }

        $currentDisk = (float) $current->disk_quota;
        $currentBandwidth = $this->normalizedBandwidthQuota($current->bandwidth_quota);
        $currentDatabases = (int) $current->num_databases;

        $candidateDisk = (float) $candidate->disk_quota;
        $candidateBandwidth = $this->normalizedBandwidthQuota($candidate->bandwidth_quota);
        $candidateDatabases = (int) $candidate->num_databases;

        $currentHasUnlimitedBandwidth = $current->bandwidth_quota === null || (float) $current->bandwidth_quota < 0;

        $notLower = $candidateDisk >= $currentDisk
            && ($currentHasUnlimitedBandwidth || $candidateBandwidth >= $currentBandwidth)
            && $candidateDatabases >= $currentDatabases;

        $strictlyHigher = $candidateDisk > $currentDisk
            || (! $currentHasUnlimitedBandwidth && $candidateBandwidth > $currentBandwidth)
            || ($current->bandwidth_quota !== null && (float) $current->bandwidth_quota >= 0 && ($candidate->bandwidth_quota === null || (float) $candidate->bandwidth_quota < 0))
            || $candidateDatabases > $currentDatabases;

        return $notLower && $strictlyHigher;
    }

    private function normalizedBandwidthQuota(mixed $quota): float
    {
        if ($quota === null || (float) $quota < 0) {
            return PHP_FLOAT_MAX;
        }

        return (float) $quota;
    }

    private function proratedUpgradePrice(Service $service, Product $targetProduct): float
    {
        $option = $this->findPlanChangeOption($service, $targetProduct->id);

        if (! $option) {
            return 0.0;
        }

        return $this->proratedPlanChangePrice($service, $service->user, $option);
    }

    /**
     * Refresh live DirectAdmin usage and clear stale over-limit flags when usage is healthy.
     *
     * @return array{
     *     snapshot: ?array<string, mixed>,
     *     cleared_stale_flags: bool,
     *     directadmin_package: ?string,
     *     platform_product: ?string
     * }
     */
    public function reconcilePackageState(Service $service): array
    {
        $service->loadMissing('product', 'node');
        $usage = app(ServicePackageUsageService::class);
        $enforcement = app(ServicePackageLimitEnforcementService::class);

        $snapshot = $usage->syncFromDirectAdmin($service);
        $clearedStale = false;

        $meta = $service->fresh()->service_meta ?? [];
        if (! empty($meta['package_overlimit_metrics'])
            && $snapshot !== null
            && $enforcement->metricsOverLimit($snapshot) === []) {
            $service->update(['service_meta' => $this->clearPackageLimitEnforcementMeta($meta)]);
            $clearedStale = true;
            $service->refresh();
            $enforcement->tryRestoreFromPackageOverlimit($service);
        }

        $freshMeta = $service->fresh()->service_meta ?? [];

        return [
            'snapshot' => $snapshot,
            'cleared_stale_flags' => $clearedStale,
            'directadmin_package' => $freshMeta['directadmin_account']['package'] ?? ($freshMeta['package_name'] ?? null),
            'platform_product' => $service->product?->name,
        ];
    }
}
