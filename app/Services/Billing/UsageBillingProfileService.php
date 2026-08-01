<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerCustomerCatalogService;

/**
 * Resolves floor price, included limits, and overage rates for usage-mode hosting.
 */
class UsageBillingProfileService
{
    public function isEnabled(): bool
    {
        return (bool) config('usage_billing.enabled', true);
    }

    public function shouldUseUsageBillingForCustomer(?User $user): bool
    {
        if (! $this->isEnabled() || ! $user) {
            return false;
        }

        // Platform customers only — reseller catalogs stay fixed packages.
        return ! app(ResellerCustomerCatalogService::class)->isResellerCustomer($user);
    }

    /**
     * @return array{cpu: float, memory_mb: int, disk_gb: float, mailboxes: int, aliases: int, mailbox_quota_mb: int, quota_mb: int, msgs_per_day: int}
     */
    public function includedLimits(): array
    {
        $included = config('usage_billing.included', []);

        return [
            'cpu' => (float) ($included['cpu'] ?? 1),
            'memory_mb' => (int) ($included['memory_mb'] ?? 1024),
            'disk_gb' => (float) ($included['disk_gb'] ?? 20),
            'mailboxes' => (int) ($included['mailboxes'] ?? 5),
            'aliases' => (int) ($included['aliases'] ?? 10),
            'mailbox_quota_mb' => (int) ($included['mailbox_quota_mb'] ?? 5120),
            'quota_mb' => (int) ($included['quota_mb'] ?? 25600),
            'msgs_per_day' => (int) ($included['msgs_per_day'] ?? 500),
        ];
    }

    /**
     * @return array{cpu_per_core_hour: float, ram_per_gb_hour: float, disk_per_gb_hour: float, mailbox_per_month: float, bandwidth_per_gb: float}
     */
    public function usageRates(): array
    {
        $rates = config('usage_billing.rates', []);

        return [
            'cpu_per_core_hour' => (float) ($rates['cpu_per_core_hour'] ?? 0),
            'ram_per_gb_hour' => (float) ($rates['ram_per_gb_hour'] ?? 0),
            'disk_per_gb_hour' => (float) ($rates['disk_per_gb_hour'] ?? 0),
            'mailbox_per_month' => (float) ($rates['mailbox_per_month'] ?? 0),
            'bandwidth_per_gb' => (float) ($rates['bandwidth_per_gb'] ?? 0),
        ];
    }

    /**
     * @return array{cpu: float|null, memory_mb: int|null, disk_gb: float|null, mailboxes: int|null}
     */
    public function hardCaps(): array
    {
        $caps = config('usage_billing.hard_caps', []);

        return [
            'cpu' => isset($caps['cpu']) ? (float) $caps['cpu'] : null,
            'memory_mb' => isset($caps['memory_mb']) ? (int) $caps['memory_mb'] : null,
            'disk_gb' => isset($caps['disk_gb']) ? (float) $caps['disk_gb'] : null,
            'mailboxes' => isset($caps['mailboxes']) ? (int) $caps['mailboxes'] : null,
        ];
    }

    public function floorPriceMonthly(): float
    {
        return round((float) config('usage_billing.floor_price_monthly', 1500), 2);
    }

    public function gracePercent(): float
    {
        return max(0, (float) config('usage_billing.grace_percent', 10));
    }

    public function warnPercent(): float
    {
        return max(1, (float) config('usage_billing.warn_percent', 80));
    }

    public function autoIncludeEmail(): bool
    {
        return (bool) config('usage_billing.auto_include_email', true);
    }

    public function resolveEmailProduct(): ?Product
    {
        $configuredId = config('usage_billing.email_product_id');
        if ($configuredId) {
            $product = Product::query()
                ->where('id', (int) $configuredId)
                ->where('type', 'email_hosting')
                ->where('is_active', true)
                ->first();
            if ($product) {
                return $product;
            }
        }

        return Product::query()
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Pick the application product for a simplified (no package grid) deploy.
     */
    public function resolveAppProductForTemplate(ContainerTemplate $language): ?Product
    {
        $configuredId = config('usage_billing.default_app_product_id');
        if ($configuredId) {
            $product = Product::query()
                ->where('id', (int) $configuredId)
                ->where('type', 'container_hosting')
                ->where('is_active', true)
                ->first();
            if ($product) {
                return $product;
            }
        }

        $forTemplate = Product::query()
            ->where('is_active', true)
            ->where('type', 'container_hosting')
            ->where('container_template_id', $language->id)
            ->orderBy('monthly_price')
            ->orderBy('id')
            ->first();

        if ($forTemplate) {
            return $forTemplate;
        }

        return Product::query()
            ->where('is_active', true)
            ->where('type', 'container_hosting')
            ->orderBy('monthly_price')
            ->orderBy('id')
            ->first();
    }

    /**
     * Attributes to merge when creating a new platform container service under usage billing.
     *
     * @return array{billing_mode: string, billing_cycle: string, custom_price: float, included_limits: array, usage_rates: array}
     */
    public function newUsageServiceAttributes(?Product $product = null): array
    {
        // Stored floor for renewals / post–free-period checkouts (overage still stacks on top).
        $productMonthly = $product ? (float) ($product->monthly_price ?? 0) : 0;
        $floor = $productMonthly > 0 ? $productMonthly : $this->floorPriceMonthly();

        return [
            'billing_mode' => BillingMode::Usage->value,
            'billing_cycle' => 'monthly',
            'custom_price' => round($floor, 2),
            'included_limits' => $this->includedLimits(),
            'usage_rates' => $this->usageRates(),
        ];
    }

    /**
     * Checkout unit price for a usage cart line (0 when first free period applies).
     */
    public function checkoutHostingPrice(User $user, ?Product $product = null): float
    {
        $guard = app(UsageDeployGuardService::class);
        if (config('usage_billing.free_period.zero_checkout_hosting', true)
            && $guard->qualifiesForFreePeriod($user)) {
            return 0.0;
        }

        return $this->newUsageServiceAttributes($product)['custom_price'];
    }

    public function provisionCpuCap(): float
    {
        $cap = (float) config('usage_billing.abuse.provision_cpu', $this->hardCaps()['cpu'] ?? 2);

        return max(0.25, $cap);
    }

    public function provisionMemoryMbCap(): int
    {
        $cap = (int) config('usage_billing.abuse.provision_memory_mb', $this->hardCaps()['memory_mb'] ?? 2048);

        return max(256, $cap);
    }

    public function provisionDiskGbCap(): float
    {
        $cap = (float) config('usage_billing.abuse.provision_disk_gb', $this->includedLimits()['disk_gb'] ?? 20);

        return max(1.0, $cap);
    }

    /**
     * After creating a usage-mode container service: free-period dates, meta, domain lock.
     *
     * @param  array<string, mixed>  $cartItem
     */
    public function finalizeNewUsageContainerService(Service $service, User $user, array $cartItem): void
    {
        $guard = app(UsageDeployGuardService::class);
        $free = ! empty($cartItem['usage_free_period']);
        $days = $guard->freePeriodDays();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        if (! empty($cartItem['primary_domain'])) {
            $meta['primary_domain'] = $guard->normalizeFqdn((string) $cartItem['primary_domain']);
        }

        $meta['disk_limit_mb'] = (int) round($this->provisionDiskGbCap() * 1024);

        if ($free) {
            $meta['usage_free_period_granted'] = true;
            $meta['usage_free_period_ends_at'] = now()->addDays($days)->toIso8601String();
            $service->update([
                'commenced_at' => now(),
                'next_due_date' => now()->addDays($days),
                // Keep custom_price as renewal floor; first invoice is deferred via next_due_date.
                'service_meta' => $meta,
            ]);
        } else {
            $service->update([
                'commenced_at' => now(),
                'next_due_date' => now()->addMonth(),
                'service_meta' => $meta,
            ]);
        }

        $fqdn = $guard->primaryDomainForService($service->fresh());
        if ($fqdn) {
            $guard->lockDomain($fqdn, $user, $service->fresh());
        }
    }

    public function serviceUsesUsageBilling(Service $service): bool
    {
        $mode = $service->billing_mode;
        if ($mode instanceof BillingMode) {
            return $mode->isUsage();
        }

        return (string) $mode === BillingMode::Usage->value;
    }

    /**
     * Effective included container limits for overage math.
     *
     * @return array{cpu: float, memory_mb: float, disk_gb: float}
     */
    public function effectiveContainerIncluded(Service $service): array
    {
        $snap = is_array($service->included_limits) ? $service->included_limits : [];
        if ($snap !== []) {
            return [
                'cpu' => (float) ($snap['cpu'] ?? 1),
                'memory_mb' => (float) ($snap['memory_mb'] ?? 1024),
                'disk_gb' => (float) ($snap['disk_gb'] ?? 20),
            ];
        }

        $service->loadMissing(['product.containerTemplate', 'containerDeployment']);
        $product = $service->product;
        if ($product) {
            $fromProduct = $product->getIncludedContainerLimits(
                $product->containerTemplate,
                $service->containerDeployment
            );

            return [
                'cpu' => (float) ($fromProduct['cpu'] ?? 1),
                'memory_mb' => (float) ($fromProduct['memory_mb'] ?? 1024),
                'disk_gb' => (float) ($fromProduct['disk_gb'] ?? 20),
            ];
        }

        $defaults = $this->includedLimits();

        return [
            'cpu' => $defaults['cpu'],
            'memory_mb' => (float) $defaults['memory_mb'],
            'disk_gb' => $defaults['disk_gb'],
        ];
    }

    /**
     * @return array{cpu_per_core_hour: float, ram_per_gb_hour: float, disk_per_gb_hour: float, mailbox_per_month: float, bandwidth_per_gb: float}
     */
    public function effectiveRates(Service $service): array
    {
        $snap = is_array($service->usage_rates) ? $service->usage_rates : [];
        if ($snap !== []) {
            return array_merge($this->usageRates(), $snap);
        }

        $product = $service->product;
        $defaults = $this->usageRates();

        if ($product) {
            if ((float) ($product->cpu_overage_rate ?? 0) > 0) {
                $defaults['cpu_per_core_hour'] = (float) $product->cpu_overage_rate;
            }
            if ((float) ($product->ram_overage_rate ?? 0) > 0) {
                $defaults['ram_per_gb_hour'] = (float) $product->ram_overage_rate;
            }
            if ((float) ($product->disk_overage_rate ?? 0) > 0) {
                $defaults['disk_per_gb_hour'] = (float) $product->disk_overage_rate;
            }
        }

        return $defaults;
    }

    public function applyGrace(float $included): float
    {
        $grace = $this->gracePercent();

        return $included * (1 + ($grace / 100));
    }
}
