<?php

namespace App\Services\Customer;

use App\Models\DirectAdminPackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TaxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerServiceRenewalService
{
    public function __construct(
        private CustomerHostingUpgradeService $hostingUpgrades,
        private NotificationService $notifications,
        private ResellerCustomerCatalogService $catalog,
    ) {}

    public function findOutstandingRenewalInvoice(Service $service): ?Invoice
    {
        return InvoiceItem::query()
            ->where('service_id', $service->id)
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', ['draft', 'unpaid'])
                    ->where('created_at', '>=', now()->subDays(30));
            })
            ->with('invoice')
            ->latest('id')
            ->first()
            ?->invoice;
    }

    /**
     * @return array{
     *     current: array{
     *         product: Product,
     *         listing: ?ResellerProduct,
     *         reseller_product_id: ?int,
     *         name: string,
     *         display_price: float,
     *         disk_quota: ?float,
     *         bandwidth_quota: mixed,
     *         num_databases: ?int,
     *         is_current: true
     *     },
     *     upgrades: Collection<int, array{
     *         product: Product,
     *         listing: ?ResellerProduct,
     *         reseller_product_id: ?int,
     *         name: string,
     *         change_type: 'upgrade'|'downgrade'|'lateral',
     *         display_price: float,
     *         disk_quota: ?float,
     *         bandwidth_quota: mixed,
     *         num_databases: ?int,
     *         is_current: false
     *     }>,
     *     billing_cycle: string,
     *     can_choose_plan: bool
     * }
     */
    public function renewalOptions(Service $service, User $customer): array
    {
        $service->loadMissing('product.directAdminPackage', 'node', 'user');
        $billingCycle = $service->billing_cycle ?? 'monthly';
        $current = $this->currentPlanOption($service, $customer, $billingCycle);

        $upgrades = $this->alternativePlansForRenewal($service, $customer);

        return [
            'current' => $current,
            'upgrades' => $upgrades,
            'billing_cycle' => $billingCycle,
            'can_choose_plan' => $upgrades->isNotEmpty(),
        ];
    }

    private function alternativePlansForRenewal(Service $service, User $customer): Collection
    {
        $product = $service->product;

        if (! $product) {
            return collect();
        }

        if ($product->type === 'shared_hosting' || $service->isSharedHosting()) {
            return $this->sameNodeSharedHostingPlans($service, $customer);
        }

        $meta = $service->service_meta ?? [];
        if ($this->resolveRenewalNodeId($service) !== null
            && (filled($service->external_reference) || filled($meta['username'] ?? null))) {
            return $this->sameNodeSharedHostingPlans($service, $customer);
        }

        if (! $this->supportsRenewalPlanPicker($service)) {
            return collect();
        }

        return $this->categoryRenewalAlternatives($service, $customer)
            ->map(fn (array $option) => array_merge($option, ['is_current' => false]))
            ->values();
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
     *     num_databases: ?int,
     *     is_current: false
     * }>
     */
    private function sameNodeSharedHostingPlans(Service $service, User $customer): Collection
    {
        $nodeId = $this->resolveRenewalNodeId($service);

        if ($nodeId === null) {
            return collect();
        }

        $fromPlanChange = $this->hostingUpgrades
            ->planChangeOptions($service, $customer)
            ->map(fn (array $option) => array_merge($option, ['is_current' => false]));

        if ($fromPlanChange->isNotEmpty()) {
            return $fromPlanChange->values();
        }

        $fromPlatform = $this->querySameNodePlatformProducts($service, $customer, $nodeId);

        if ($fromPlatform->isNotEmpty()) {
            return $fromPlatform;
        }

        return $this->querySameNodeResellerListings($service, $customer, $nodeId);
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
     *     num_databases: ?int,
     *     is_current: false
     * }>
     */
    private function querySameNodePlatformProducts(Service $service, User $customer, int $nodeId): Collection
    {
        $product = $service->product;
        $billingCycle = $service->billing_cycle ?? 'monthly';
        $currentPrice = $this->hostingUpgrades->displayPriceForCycle($customer, $product, $billingCycle);
        $currentOrder = (int) ($product->order ?? 0);

        return $this->hostingUpgrades
            ->platformHostingPlansOnNode($service, $nodeId)
            ->filter(fn (array $candidate) => (int) $candidate['product']->id !== (int) $product->id)
            ->map(fn (array $candidate) => $this->mapProductToRenewalOption(
                $candidate['product'],
                $candidate['listing'] ?? null,
                $customer,
                $billingCycle,
                $currentPrice,
                $currentOrder,
                $candidate['disk_quota'] ?? null,
                $candidate['bandwidth_quota'] ?? null,
                $candidate['num_databases'] ?? null,
            ))
            ->values();
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
     *     num_databases: ?int,
     *     is_current: false
     * }>
     */
    private function querySameNodeResellerListings(Service $service, User $customer, int $nodeId): Collection
    {
        if (! $this->catalog->isResellerCustomer($customer)) {
            return collect();
        }

        $product = $service->product;
        $billingCycle = $service->billing_cycle ?? 'monthly';
        $currentPrice = $this->hostingUpgrades->displayPriceForCycle($customer, $product, $billingCycle);
        $currentOrder = (int) ($product->order ?? 0);
        $currentListingId = (int) ($service->service_meta['reseller_product_id'] ?? 0);

        return $this->catalog->activeCatalog($customer)
            ->filter(fn (ResellerProduct $listing) => $listing->type === 'shared_hosting')
            ->filter(fn (ResellerProduct $listing) => $listing->isOrderable())
            ->filter(fn (ResellerProduct $listing) => $listing->id !== $currentListingId)
            ->map(function (ResellerProduct $listing) use ($nodeId, $customer, $billingCycle, $currentPrice, $currentOrder) {
                $candidate = $listing->provisionProduct();

                if (! $candidate) {
                    return null;
                }

                if ($listing->usesDirectAdminPackage()) {
                    return $this->mapProductToRenewalOption(
                        $candidate,
                        $listing,
                        $customer,
                        $billingCycle,
                        $currentPrice,
                        $currentOrder,
                    );
                }

                $package = $listing->adminProduct?->directAdminPackage;

                if (! $package || (int) $package->node_id !== $nodeId) {
                    return null;
                }

                return $this->mapProductToRenewalOption(
                    $candidate,
                    $listing,
                    $customer,
                    $billingCycle,
                    $currentPrice,
                    $currentOrder,
                    $package->disk_quota,
                    $package->bandwidth_quota,
                    $package->num_databases,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     change_type: 'upgrade'|'downgrade'|'lateral',
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int,
     *     is_current: false
     * }
     */
    private function mapProductToRenewalOption(
        Product $candidate,
        ?ResellerProduct $listing,
        User $customer,
        string $billingCycle,
        float $currentPrice,
        int $currentOrder,
        ?float $diskQuota = null,
        mixed $bandwidthQuota = null,
        ?int $numDatabases = null,
    ): array {
        $targetPrice = $listing
            ? $this->hostingUpgrades->displayPriceForPlanOption($customer, [
                'product' => $candidate,
                'listing' => $listing,
                'reseller_product_id' => $listing->id,
            ], $billingCycle)
            : $this->hostingUpgrades->displayPriceForCycle($customer, $candidate, $billingCycle);

        $targetOrder = (int) ($candidate->order ?? 0);

        return [
            'product' => $candidate,
            'listing' => $listing,
            'reseller_product_id' => $listing?->id,
            'name' => $listing?->name ?? $candidate->name,
            'change_type' => $this->resolveChangeType($currentPrice, $currentOrder, $targetPrice, $targetOrder),
            'display_price' => $targetPrice,
            'disk_quota' => $diskQuota ?? $candidate->directAdminPackage?->disk_quota,
            'bandwidth_quota' => $bandwidthQuota ?? $candidate->directAdminPackage?->bandwidth_quota,
            'num_databases' => $numDatabases ?? $candidate->directAdminPackage?->num_databases,
            'is_current' => false,
        ];
    }

    private function supportsRenewalPlanPicker(Service $service): bool
    {
        $type = $service->product?->type;

        return in_array($type, ['shared_hosting', 'container_hosting', 'email_hosting'], true)
            || $service->isSharedHosting();
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
    private function categoryRenewalAlternatives(Service $service, User $customer): Collection
    {
        $product = $service->product;

        if (! $product) {
            return collect();
        }

        $billingCycle = $service->billing_cycle ?? 'monthly';
        $currentPrice = $this->hostingUpgrades->displayPriceForCycle($customer, $product, $billingCycle);
        $currentOrder = (int) ($product->order ?? 0);
        $nodeId = $this->resolveRenewalNodeId($service);

        $query = Product::query()
            ->where('is_active', true)
            ->where('type', $product->type)
            ->where('id', '!=', $product->id)
            ->with('directAdminPackage');

        if (filled($product->category)) {
            $query->where('category', $product->category);
        }

        if ($product->type === 'shared_hosting' && $nodeId !== null) {
            $query->whereHas('directAdminPackage', fn ($package) => $package->where('node_id', $nodeId));
        }

        $alternatives = $this->mapCategoryRenewalCandidates(
            $query->orderBy('order')->orderBy('monthly_price')->get(),
            $customer,
            $billingCycle,
            $currentPrice,
            $currentOrder,
        );

        if ($alternatives->isNotEmpty() || ! filled($product->category)) {
            return $alternatives;
        }

        $fallbackQuery = Product::query()
            ->where('is_active', true)
            ->where('type', $product->type)
            ->where('id', '!=', $product->id)
            ->with('directAdminPackage');

        if ($product->type === 'shared_hosting' && $nodeId !== null) {
            $fallbackQuery->whereHas('directAdminPackage', fn ($package) => $package->where('node_id', $nodeId));
        }

        return $this->mapCategoryRenewalCandidates(
            $fallbackQuery->orderBy('order')->orderBy('monthly_price')->get(),
            $customer,
            $billingCycle,
            $currentPrice,
            $currentOrder,
        );
    }

    /**
     * @param  Collection<int, Product>  $candidates
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
    private function mapCategoryRenewalCandidates(
        Collection $candidates,
        User $customer,
        string $billingCycle,
        float $currentPrice,
        int $currentOrder,
    ): Collection {
        return $candidates
            ->map(function (Product $candidate) use ($customer, $billingCycle, $currentPrice, $currentOrder) {
                $targetPrice = $this->hostingUpgrades->displayPriceForCycle($customer, $candidate, $billingCycle);
                $targetOrder = (int) ($candidate->order ?? 0);
                $changeType = $this->resolveChangeType($currentPrice, $currentOrder, $targetPrice, $targetOrder);

                if (! in_array($changeType, ['upgrade', 'lateral', 'downgrade'], true)) {
                    return null;
                }

                return [
                    'product' => $candidate,
                    'listing' => null,
                    'reseller_product_id' => null,
                    'name' => $candidate->name,
                    'change_type' => $changeType,
                    'display_price' => $targetPrice,
                    'disk_quota' => $candidate->directAdminPackage?->disk_quota,
                    'bandwidth_quota' => $candidate->directAdminPackage?->bandwidth_quota,
                    'num_databases' => $candidate->directAdminPackage?->num_databases,
                ];
            })
            ->filter()
            ->values();
    }

    private function resolveRenewalNodeId(Service $service): ?int
    {
        if ($service->node_id) {
            return (int) $service->node_id;
        }

        $meta = $service->service_meta ?? [];
        if (! empty($meta['node_id'])) {
            return (int) $meta['node_id'];
        }

        $service->loadMissing('product.directAdminPackage');

        if ($service->product?->directAdminPackage?->node_id) {
            return (int) $service->product->directAdminPackage->node_id;
        }

        $packageKey = $meta['package'] ?? $meta['package_name'] ?? null;

        if ($packageKey) {
            $package = DirectAdminPackage::query()
                ->where(function ($query) use ($packageKey) {
                    $query->where('package_key', $packageKey)
                        ->orWhere('name', $packageKey);
                })
                ->first();

            if ($package?->node_id) {
                return (int) $package->node_id;
            }
        }

        return null;
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

    public function findRenewalOption(Service $service, User $customer, int $productId, ?int $resellerProductId = null): ?array
    {
        $options = $this->renewalOptions($service, $customer);
        $candidates = collect([$options['current']])->merge($options['upgrades']);

        return $candidates->first(function (array $option) use ($productId, $resellerProductId) {
            if ((int) $option['product']->id !== $productId) {
                return false;
            }

            $optionListingId = $option['reseller_product_id'] ?? null;

            return $resellerProductId === null
                ? $optionListingId === null
                : $optionListingId === $resellerProductId;
        });
    }

    public function createRenewalInvoice(Service $service, User $customer, int $productId, ?int $resellerProductId = null): Invoice
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('Unauthorized service renewal.');
        }

        if (! in_array($service->status->value, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('Only active or suspended services can be renewed.');
        }

        $option = $this->findRenewalOption($service, $customer, $productId, $resellerProductId);

        if (! $option) {
            throw new \InvalidArgumentException('Selected plan is not available for this renewal.');
        }

        $isCurrentPlan = ! empty($option['is_current']);
        $price = $this->renewalPrice($service, $customer, $option);
        $taxBreakdown = TaxService::calculateForUser($price, $customer);
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $cycleLabel = ucfirst($service->billing_cycle ?? 'monthly');

        $invoice = DB::transaction(function () use (
            $service,
            $customer,
            $option,
            $isCurrentPlan,
            $prefix,
            $price,
            $taxBreakdown,
            $dueDate,
            $cycleLabel,
        ) {
            $year = now()->format('Y');
            $sequence = Invoice::whereYear('created_at', $year)->lockForUpdate()->count() + 1;
            $number = $prefix.'-'.$year.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

            $notes = $isCurrentPlan
                ? "Manual renewal — {$option['name']} ({$cycleLabel})"
                : match ($option['change_type'] ?? 'upgrade') {
                    'downgrade' => "Renewal with plan change (downgrade) — {$service->product->name} → {$option['name']} ({$cycleLabel})",
                    default => "Renewal with plan change — {$service->product->name} → {$option['name']} ({$cycleLabel})",
                };

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => $dueDate,
                'subtotal' => $taxBreakdown['subtotal'],
                'tax' => $taxBreakdown['tax'],
                'total' => $taxBreakdown['total'],
                'notes' => $notes,
            ]);

            $customOptions = null;

            if (! $isCurrentPlan) {
                $customOptions = [
                    'hosting_renewal_upgrade' => true,
                    'hosting_upgrade' => true,
                    'hosting_plan_change' => true,
                    'change_type' => $option['change_type'] ?? 'upgrade',
                    'from_product_id' => $service->product_id,
                    'to_product_id' => $option['product']->id,
                ];

                if (! empty($option['reseller_product_id'])) {
                    $customOptions['to_reseller_product_id'] = $option['reseller_product_id'];
                }
            }

            $description = $isCurrentPlan
                ? "{$option['name']} — {$cycleLabel} renewal"
                : "{$option['name']} — {$cycleLabel} renewal (plan change)";

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $option['product']->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'custom_options' => $customOptions,
            ]);

            $service->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        $this->notifications->notifyInvoiceGenerated($invoice->fresh(['user', 'items']));

        return $invoice;
    }

    /**
     * @return array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     display_price: float,
     *     disk_quota: ?float,
     *     bandwidth_quota: mixed,
     *     num_databases: ?int,
     *     is_current: true
     * }
     */
    private function currentPlanOption(Service $service, User $customer, string $billingCycle): array
    {
        $product = $service->product;
        $listingId = (int) ($service->service_meta['reseller_product_id'] ?? 0);
        $listing = $listingId > 0
            ? ResellerProduct::query()->where('id', $listingId)->first()
            : null;

        $displayPrice = $listing
            ? $this->hostingUpgrades->displayPriceForPlanOption($customer, [
                'product' => $product,
                'listing' => $listing,
                'reseller_product_id' => $listing?->id,
            ], $billingCycle)
            : $this->priceForProductCycle($service, $product, $billingCycle);

        return [
            'product' => $product,
            'listing' => $listing,
            'reseller_product_id' => $listing?->id,
            'name' => $listing?->name ?? $product->name,
            'display_price' => $displayPrice,
            'disk_quota' => $product->directAdminPackage?->disk_quota,
            'bandwidth_quota' => $product->directAdminPackage?->bandwidth_quota,
            'num_databases' => $product->directAdminPackage?->num_databases,
            'is_current' => true,
        ];
    }

    /**
     * @param  array{
     *     product: Product,
     *     listing: ?ResellerProduct,
     *     reseller_product_id: ?int,
     *     name: string,
     *     display_price: float
     * }  $option
     */
    private function renewalPrice(Service $service, User $customer, array $option): float
    {
        if ($this->supportsRenewalPlanPicker($service)) {
            return $this->hostingUpgrades->displayPriceForPlanOption(
                $customer,
                $option,
                $service->billing_cycle ?? 'monthly',
            );
        }

        return $this->priceForProductCycle($service, $option['product'], $service->billing_cycle ?? 'monthly');
    }

    private function priceForProductCycle(Service $service, Product $product, string $cycle): float
    {
        return match ($cycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => (float) ($product->monthly_price * 3),
            'semi-annual' => (float) ($product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?: ($product->monthly_price * 12)),
            default => (float) $product->price,
        };
    }
}
