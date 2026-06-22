<?php

namespace App\Services;

use App\Models\DomainExtension;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerProduct;
use App\Models\User;
use Illuminate\Support\Collection;

class ResellerPublicApiService
{
    public function __construct(
        private DomainAvailabilityService $availability,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function isEnabled(User $reseller): bool
    {
        $settings = $this->settings($reseller);

        return (bool) ($settings['enabled'] ?? false)
            && filled($this->brandingResolver->forReseller($reseller)['custom_domain'] ?? null);
    }

    /**
     * @return array{enabled: bool, allowed_origins: list<string>, updated_at: mixed}
     */
    public function settings(User $reseller): array
    {
        $stored = $reseller->settings['public_api'] ?? [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'allowed_origins' => array_values(array_filter($stored['allowed_origins'] ?? [])),
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $allowedOrigins
     */
    public function updateSettings(User $reseller, bool $enabled, array $allowedOrigins = []): void
    {
        $settings = $reseller->settings ?? [];
        $settings['public_api'] = [
            'enabled' => $enabled,
            'allowed_origins' => array_values(array_unique(array_filter(array_map(
                fn (string $origin) => $this->normalizeOrigin($origin),
                $allowedOrigins,
            )))),
            'updated_at' => now()->toIso8601String(),
        ];

        $reseller->update(['settings' => $settings]);
    }

    public function portalBaseUrl(User $reseller): string
    {
        return rtrim($this->brandingResolver->portalUrl($reseller), '/');
    }

    public function checkoutUrl(User $reseller): string
    {
        return $this->portalBaseUrl($reseller).'/checkout';
    }

    /**
     * @return Collection<int, DomainExtension>
     */
    public function enabledExtensions(User $reseller, int $periodYears = 1): Collection
    {
        return DomainExtension::query()
            ->where('enabled', true)
            ->whereHas('resellerPricing', function ($query) use ($reseller, $periodYears) {
                $query->where('reseller_id', $reseller->id)
                    ->where('period_years', $periodYears)
                    ->where('enabled', true);
            })
            ->orderBy('extension')
            ->get()
            ->each->concealUpstreamProviderDetails();
    }

    public function retailPrice(User $reseller, DomainExtension $extension, int $years): ?float
    {
        $pricing = ResellerDomainPricing::query()
            ->where('reseller_id', $reseller->id)
            ->where('domain_extension_id', $extension->id)
            ->where('period_years', $years)
            ->where('enabled', true)
            ->first();

        return $pricing ? (float) $pricing->retail_price : null;
    }

    /**
     * @return array{success: bool, query: string, period_years: int, currency: string, results: list<array<string, mixed>>}
     */
    public function searchDomains(User $reseller, string $query, int $periodYears = 1): array
    {
        $query = str_replace(['www.', 'https://', 'http://'], '', strtolower(trim($query)));
        $periodYears = max(1, min(10, $periodYears));
        $extensions = $this->enabledExtensions($reseller, $periodYears);
        $allowedExtensionNames = $extensions->pluck('extension')->all();
        $results = [];
        $checkoutUrl = $this->checkoutUrl($reseller);

        if (str_contains($query, '.')) {
            $check = $this->availability->checkInput($query, null, $allowedExtensionNames);

            if ($check) {
                $extension = $extensions->firstWhere('extension', $check['extension']);

                if ($extension) {
                    $price = $this->retailPrice($reseller, $extension, $periodYears);

                    if ($price !== null) {
                        $results[] = $this->formatDomainResult($check, $extension, $price, $periodYears, $checkoutUrl);
                    }
                }
            }
        } else {
            foreach ($extensions as $extension) {
                $check = $this->availability->checkInput($query, $extension->extension, $allowedExtensionNames);

                if ($check === null) {
                    continue;
                }

                $price = $this->retailPrice($reseller, $extension, $periodYears);

                if ($price === null) {
                    continue;
                }

                $results[] = $this->formatDomainResult($check, $extension, $price, $periodYears, $checkoutUrl);
            }
        }

        usort($results, fn (array $a, array $b) => $b['available'] <=> $a['available']);

        return [
            'success' => true,
            'query' => $query,
            'period_years' => $periodYears,
            'currency' => 'KES',
            'checkout_url' => $checkoutUrl,
            'results' => $results,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExtensions(User $reseller, int $periodYears = 1): array
    {
        $periodYears = max(1, min(10, $periodYears));

        return $this->enabledExtensions($reseller, $periodYears)
            ->map(function (DomainExtension $extension) use ($reseller, $periodYears) {
                $price = $this->retailPrice($reseller, $extension, $periodYears);

                return [
                    'extension' => $extension->extension,
                    'description' => $extension->description,
                    'period_years' => $periodYears,
                    'price' => $price,
                    'currency' => 'KES',
                ];
            })
            ->filter(fn (array $row) => $row['price'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(User $reseller): array
    {
        return ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->with('adminProduct')
            ->orderBy('name')
            ->get()
            ->filter(fn (ResellerProduct $product) => $product->isOrderable())
            ->map(fn (ResellerProduct $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'type' => $product->type ?? $product->adminProduct?->type,
                'monthly_price' => (float) ($product->monthly_price ?? 0),
                'yearly_price' => $product->yearly_price !== null ? (float) $product->yearly_price : null,
                'setup_fee' => (float) ($product->setup_fee ?? 0),
                'currency' => 'KES',
                'billing_cycles' => ['monthly', 'quarterly', 'semi-annual', 'annual'],
                'features' => $product->features ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function buildCartItems(User $reseller, array $items): array
    {
        $cart = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? '';

            if ($type === 'domain') {
                $line = $this->buildDomainCartItem($reseller, $item);

                if ($line !== null) {
                    $cart[] = $line;
                }

                continue;
            }

            if ($type === 'service' || $type === 'reseller_product') {
                $line = $this->buildServiceCartItem($reseller, $item);

                if ($line !== null) {
                    $cart[] = $line;
                }
            }
        }

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function buildDomainCartItem(User $reseller, array $item): ?array
    {
        $fullDomain = strtolower(trim((string) ($item['full_domain'] ?? '')));
        $years = max(1, min(10, (int) ($item['years'] ?? 1)));

        if ($fullDomain === '') {
            return null;
        }

        $allowed = $this->enabledExtensions($reseller, $years)->pluck('extension')->all();
        $check = $this->availability->checkInput($fullDomain, null, $allowed);

        if ($check === null || ! $check['available']) {
            return null;
        }

        $extension = DomainExtension::where('extension', $check['extension'])->first();

        if (! $extension) {
            return null;
        }

        $price = $this->retailPrice($reseller, $extension, $years);

        if ($price === null) {
            return null;
        }

        return [
            'type' => 'domain',
            'domain' => $check['name'],
            'extension' => $check['extension'],
            'full_domain' => $check['full_domain'],
            'years' => $years,
            'price' => $price,
            'reseller_id' => $reseller->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function buildServiceCartItem(User $reseller, array $item): ?array
    {
        $listing = ResellerProduct::query()
            ->where('id', $item['reseller_product_id'] ?? $item['id'] ?? null)
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->first();

        if (! $listing || ! $listing->isOrderable()) {
            return null;
        }

        $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');

        if (! in_array($billingCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)) {
            return null;
        }

        return [
            'type' => 'reseller_product',
            'reseller_product_id' => $listing->id,
            'reseller_id' => $reseller->id,
            'billing_cycle' => $billingCycle,
        ];
    }

    /**
     * @param  array{name: string, extension: string, full_domain: string, available: bool}  $check
     * @return array<string, mixed>
     */
    private function formatDomainResult(
        array $check,
        DomainExtension $extension,
        float $price,
        int $periodYears,
        string $checkoutUrl,
    ): array {
        return [
            'domain' => $check['name'],
            'extension' => $check['extension'],
            'full_domain' => $check['full_domain'],
            'available' => (bool) $check['available'],
            'period_years' => $periodYears,
            'price' => $price,
            'currency' => 'KES',
            'checkout_url' => $checkoutUrl,
        ];
    }

    public function originAllowed(User $reseller, ?string $origin): bool
    {
        $allowed = $this->settings($reseller)['allowed_origins'];

        if ($allowed === [] || $origin === null || $origin === '') {
            return false;
        }

        return in_array($this->normalizeOrigin($origin), $allowed, true);
    }

    private function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        $origin = rtrim($origin, '/');

        return strtolower($origin);
    }
}
