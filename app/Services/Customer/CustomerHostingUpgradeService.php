<?php

namespace App\Services\Customer;

use App\Enums\ServiceStatus;
use App\Models\DirectAdminPackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
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
     * @return Collection<int, Product>
     */
    public function upgradeOptionsForService(Service $service): Collection
    {
        $service->loadMissing('product.directAdminPackage', 'node', 'user');

        if ($service->product?->type !== 'shared_hosting') {
            return collect();
        }

        if (! in_array($service->status->value, ['active', 'suspended'], true)) {
            return collect();
        }

        if (! $service->node_id) {
            return collect();
        }

        $customer = $service->user;
        if (! $customer) {
            return collect();
        }

        $cycle = $service->billing_cycle ?? 'monthly';
        $currentPrice = $this->effectiveCyclePrice($customer, $service->product, $cycle);
        $currentOrder = (int) ($service->product->order ?? 0);
        $currentPackage = $service->product->directAdminPackage;

        $query = Product::query()
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->where('id', '!=', $service->product_id)
            ->whereHas('directAdminPackage', fn ($package) => $package->where('node_id', $service->node_id))
            ->with('directAdminPackage')
            ->orderBy('monthly_price');

        $this->catalog->scopePlatformProducts($query, $customer);

        return $query->get()->filter(function (Product $product) use ($customer, $currentPackage, $currentPrice, $currentOrder, $cycle) {
            $targetPrice = $this->effectiveCyclePrice($customer, $product, $cycle);
            $targetOrder = (int) ($product->order ?? 0);

            $isHigherPrice = $targetPrice > $currentPrice;
            $isSamePriceHigherOrder = $targetPrice === $currentPrice && $targetOrder > $currentOrder;

            if (! $isHigherPrice && ! $isSamePriceHigherOrder) {
                return false;
            }

            if ($currentPackage && $product->directAdminPackage) {
                return $this->isViableResourceUpgrade($currentPackage, $product->directAdminPackage);
            }

            return true;
        })->values();
    }

    public function assertValidUpgradeTarget(Service $service, Product $targetProduct): void
    {
        $options = $this->upgradeOptionsForService($service);

        if (! $options->contains('id', $targetProduct->id)) {
            throw new \InvalidArgumentException('Selected plan is not a valid upgrade for this service on its current server.');
        }
    }

    public function recommendedUpgrade(Service $service, User $customer, ?string $limitingMetric = null): ?Product
    {
        $options = $this->upgradeOptions($service, $customer);
        if ($options->isEmpty()) {
            return null;
        }

        return match ($limitingMetric) {
            'database' => $options->sortBy(fn (Product $product) => $product->directAdminPackage?->num_databases ?? 0)->first(),
            'bandwidth' => $options->sortBy(fn (Product $product) => $product->directAdminPackage?->bandwidth_quota ?? 0)->first(),
            default => $options->sortBy(fn (Product $product) => $product->directAdminPackage?->disk_quota ?? 0)->first(),
        };
    }

    public function createUpgradeInvoice(Service $service, User $customer, Product $targetProduct): Invoice
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('Unauthorized service upgrade.');
        }

        if ($targetProduct->type !== 'shared_hosting') {
            throw new \InvalidArgumentException('Target product is not a shared hosting plan.');
        }

        $options = $this->upgradeOptions($service, $customer);

        if (! $options->contains('id', $targetProduct->id)) {
            throw new \InvalidArgumentException('Selected plan is not a valid upgrade for this service.');
        }

        $price = $this->proratedUpgradePrice($service, $targetProduct);
        $taxBreakdown = TaxService::calculateForUser($price, $service->user);
        $tax = $taxBreakdown['tax'];
        $total = $taxBreakdown['total'];
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();
        $prefix = Setting::getValue('invoice_prefix', 'INV');

        $invoice = DB::transaction(function () use ($service, $customer, $targetProduct, $prefix, $price, $tax, $total, $dueDate, $taxBreakdown) {
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
                'notes' => "Hosting upgrade: {$service->product->name} → {$targetProduct->name}",
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $targetProduct->id,
                'description' => "Upgrade to {$targetProduct->name}",
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'custom_options' => [
                    'hosting_upgrade' => true,
                    'from_product_id' => $service->product_id,
                    'to_product_id' => $targetProduct->id,
                ],
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

            try {
                $this->applyUpgrade($service, $targetProduct);
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

    public function applyUpgrade(Service $service, Product $targetProduct): void
    {
        $service->loadMissing('node', 'reseller', 'product.directAdminPackage', 'user');

        if (! $service->node || $service->node->type !== 'directadmin') {
            throw new \RuntimeException('Service is not on a DirectAdmin node.');
        }

        $targetProduct->loadMissing('directAdminPackage');
        $package = $targetProduct->directAdminPackage;

        if (! $package) {
            throw new \RuntimeException('Target product has no DirectAdmin package.');
        }

        if ((int) $package->node_id !== (int) $service->node_id) {
            throw new \RuntimeException('Target plan is not available on this service\'s DirectAdmin server.');
        }

        $previousProduct = $service->product;
        if (! $previousProduct) {
            throw new \RuntimeException('Current product not found on service.');
        }

        $meta = $service->service_meta ?? [];
        $username = $meta['username'] ?? null;

        if (! $username) {
            throw new \RuntimeException('Hosting username not found on service.');
        }

        $directAdmin = $this->resellerDirectAdmin->directAdminForService($service);
        if (! $directAdmin) {
            throw new \RuntimeException('DirectAdmin API is not configured for this service.');
        }

        $ownerReseller = $this->resellerDirectAdmin->impersonationUsernameForService($service);
        $this->directAdminSetup->ensurePackageOnServer($directAdmin, $package, $ownerReseller);

        $result = $directAdmin->changeUserPackage($username, $package->package_key);

        if (! $result['success']) {
            throw new \RuntimeException($result['message']);
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
        $meta['package'] = $package->package_key;
        $meta['package_name'] = $package->name;

        $service->update([
            'product_id' => $targetProduct->id,
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
        $cycle = $service->billing_cycle ?? 'monthly';
        $customer = $service->user;
        $current = $this->effectiveCyclePrice($customer, $service->product, $cycle);
        $target = $this->effectiveCyclePrice($customer, $targetProduct, $cycle);

        $diff = max(0, $target - $current);

        if ($diff <= 0) {
            return max(0, $target);
        }

        if (! $service->next_due_date || $service->next_due_date->isPast()) {
            return round($diff, 2);
        }

        $daysRemaining = max(1, now()->diffInDays($service->next_due_date));
        $cycleDays = $cycle === 'annual' ? 365 : 30;

        return round($diff * ($daysRemaining / $cycleDays), 2);
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
