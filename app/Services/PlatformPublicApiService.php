<?php

namespace App\Services;

use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Setting;
use Illuminate\Support\Collection;

class PlatformPublicApiService
{
    public function __construct(
        private DomainAvailabilityService $availability,
        private ResellerBrandingResolver $brandingResolver,
        private PublicApiCatalogSerializer $catalogSerializer,
    ) {}

    public function isEnabled(): bool
    {
        return filter_var(Setting::getValue('public_website_api_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{enabled: bool, allowed_origins: list<string>}
     */
    public function settings(): array
    {
        $origins = Setting::getValue('public_website_api_allowed_origins', '[]');
        $decoded = is_string($origins) ? json_decode($origins, true) : $origins;

        return [
            'enabled' => $this->isEnabled(),
            'allowed_origins' => array_values(array_filter(is_array($decoded) ? $decoded : [])),
        ];
    }

    /**
     * @param  list<string>  $allowedOrigins
     */
    public function updateSettings(bool $enabled, array $allowedOrigins = []): void
    {
        Setting::setValue('public_website_api_enabled', $enabled ? '1' : '0');
        Setting::setValue('public_website_api_allowed_origins', json_encode(
            array_values(array_unique(array_filter(array_map(
                static fn (string $origin) => strtolower(rtrim(trim($origin), '/')),
                $allowedOrigins,
            ))))
        ));
    }

    public function apiBaseUrl(): string
    {
        return $this->brandingResolver->publicApiBaseUrl().'/api/v1/public';
    }

    public function checkoutUrl(): string
    {
        return $this->brandingResolver->publicApiBaseUrl().'/domain-checkout';
    }

    /**
     * @return Collection<int, DomainExtension>
     */
    public function enabledExtensions(int $periodYears = 1): Collection
    {
        return DomainExtension::query()
            ->where('enabled', true)
            ->orderBy('extension')
            ->get()
            ->filter(fn (DomainExtension $extension) => $this->retailPrice($extension, $periodYears) !== null)
            ->each->concealUpstreamProviderDetails();
    }

    public function retailPrice(DomainExtension $extension, int $years): ?float
    {
        $pricing = $extension->getRetailPricing($years);

        return $pricing && $pricing->enabled ? (float) $pricing->price : null;
    }

    /**
     * @return array{success: bool, query: string, period_years: int, currency: string, results: list<array<string, mixed>>}
     */
    public function searchDomains(string $query, int $periodYears = 1): array
    {
        $query = str_replace(['www.', 'https://', 'http://'], '', strtolower(trim($query)));
        $periodYears = max(1, min(10, $periodYears));
        $extensions = $this->enabledExtensions($periodYears);
        $allowedExtensionNames = $extensions->pluck('extension')->all();
        $results = [];
        $checkoutUrl = $this->checkoutUrl();

        if (str_contains($query, '.')) {
            $check = $this->availability->checkInput($query, null, $allowedExtensionNames);

            if ($check) {
                $extension = $extensions->firstWhere('extension', $check['extension']);

                if ($extension) {
                    $price = $this->retailPrice($extension, $periodYears);

                    if ($price !== null) {
                        $results[] = $this->formatDomainResult($check, $price, $periodYears, $checkoutUrl);
                    }
                }
            }
        } else {
            foreach ($extensions as $extension) {
                $check = $this->availability->checkInput($query, $extension->extension, $allowedExtensionNames);

                if ($check === null) {
                    continue;
                }

                $price = $this->retailPrice($extension, $periodYears);

                if ($price === null) {
                    continue;
                }

                $results[] = $this->formatDomainResult($check, $price, $periodYears, $checkoutUrl);
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
    public function listExtensions(int $periodYears = 1): array
    {
        $periodYears = max(1, min(10, $periodYears));

        return $this->enabledExtensions($periodYears)
            ->map(function (DomainExtension $extension) use ($periodYears) {
                return [
                    'extension' => $extension->extension,
                    'description' => $extension->description,
                    'period_years' => $periodYears,
                    'price' => $this->retailPrice($extension, $periodYears),
                    'currency' => 'KES',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => $this->catalogSerializer->formatPlatformProduct($product))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listResellerPackages(?string $billingCycle = null): array
    {
        $query = ResellerPackage::query()
            ->where('active', true)
            ->orderBy('price');

        if ($billingCycle !== null && $billingCycle !== '') {
            $query->where('billing_cycle', $billingCycle);
        }

        return $query
            ->get()
            ->map(fn (ResellerPackage $package) => $this->catalogSerializer->formatResellerPackage($package))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function buildCartItems(array $items): array
    {
        $cart = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? '';

            if ($type === 'domain') {
                $line = $this->buildDomainCartItem($item);

                if ($line !== null) {
                    $cart[] = $line;
                }

                continue;
            }

            if (in_array($type, ['service', 'product'], true)) {
                $line = $this->buildServiceCartItem($item);

                if ($line !== null) {
                    $cart[] = $line;
                }

                continue;
            }

            if ($type === 'reseller_package') {
                $line = $this->buildResellerPackageCartItem($item);

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
    private function buildDomainCartItem(array $item): ?array
    {
        $fullDomain = strtolower(trim((string) ($item['full_domain'] ?? '')));
        $years = max(1, min(10, (int) ($item['years'] ?? 1)));

        if ($fullDomain === '') {
            return null;
        }

        $allowed = $this->enabledExtensions($years)->pluck('extension')->all();
        $check = $this->availability->checkInput($fullDomain, null, $allowed);

        if ($check === null || ! $check['available']) {
            return null;
        }

        $extension = DomainExtension::where('extension', $check['extension'])->first();

        if (! $extension) {
            return null;
        }

        $price = $this->retailPrice($extension, $years);

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
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function buildServiceCartItem(array $item): ?array
    {
        $product = Product::query()
            ->where('id', $item['product_id'] ?? $item['id'] ?? null)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            return null;
        }

        $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');

        if (! in_array($billingCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)) {
            return null;
        }

        $serverFields = $this->catalogSerializer->serverCartFields($product, $item);
        if ($serverFields === null) {
            return null;
        }

        return array_merge([
            'type' => 'product',
            'product_id' => $product->id,
            'billing_cycle' => $billingCycle,
        ], $serverFields);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function buildResellerPackageCartItem(array $item): ?array
    {
        $package = ResellerPackage::query()
            ->where('id', $item['reseller_package_id'] ?? $item['id'] ?? null)
            ->where('active', true)
            ->first();

        if (! $package) {
            return null;
        }

        $amounts = app(ResellerPackageSubscriptionService::class)->calculateAmounts((float) $package->price);

        return [
            'type' => 'reseller_package',
            'reseller_package_id' => $package->id,
            'billing_cycle' => $package->billing_cycle,
            'price' => (float) $package->price,
            'subtotal' => $amounts['subtotal'],
            'tax' => $amounts['tax'],
            'total' => $amounts['total'],
        ];
    }

    /**
     * @param  array{name: string, extension: string, full_domain: string, available: bool}  $check
     * @return array<string, mixed>
     */
    private function formatDomainResult(array $check, float $price, int $periodYears, string $checkoutUrl): array
    {
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

    public function originAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        $normalized = strtolower(rtrim(trim($origin), '/'));
        $platformOrigin = strtolower($this->brandingResolver->publicApiBaseUrl());

        if ($normalized === $platformOrigin) {
            return true;
        }

        $allowed = $this->settings()['allowed_origins'];

        if ($allowed === []) {
            return false;
        }

        return in_array($normalized, $allowed, true);
    }
}
