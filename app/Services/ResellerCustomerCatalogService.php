<?php

namespace App\Services;

use App\Http\Controllers\Customer\CartController;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResellerCustomerCatalogService
{
    public function isResellerCustomer(?User $user): bool
    {
        return $user !== null && $user->reseller_id !== null;
    }

    /**
     * @return Collection<int, ResellerProduct>
     */
    public function activeCatalog(User $user): Collection
    {
        if (! $this->isResellerCustomer($user)) {
            return collect();
        }

        return ResellerProduct::query()
            ->where('reseller_id', $user->reseller_id)
            ->where('is_active', true)
            ->with('adminProduct.containerTemplate')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    public function allowedProductIds(User $user): array
    {
        return $this->activeCatalog($user)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ResellerProduct>
     */
    public function activeCatalogKeyedByProductId(User $user): Collection
    {
        return $this->activeCatalog($user)
            ->filter(fn (ResellerProduct $item) => $item->product_id !== null)
            ->keyBy('product_id');
    }

    public function findListingForProduct(User $user, int $productId): ?ResellerProduct
    {
        return $this->activeCatalogKeyedByProductId($user)->get($productId);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePlatformProducts(Builder $query, User $user): Builder
    {
        if (! $this->isResellerCustomer($user)) {
            return $query;
        }

        $ids = $this->allowedProductIds($user);

        return $query->whereIn('products.id', $ids !== [] ? $ids : [-1]);
    }

    public function sanitizeSessionCart(User $user): void
    {
        if (! $this->isResellerCustomer($user)) {
            return;
        }

        $cart = session(CartController::CART_SESSION_KEY, []);
        $changed = false;

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? '') === 'product') {
                unset($cart[$key]);
                $changed = true;

                continue;
            }

            if (($item['type'] ?? '') === 'reseller_product') {
                $listing = ResellerProduct::query()
                    ->where('id', $item['reseller_product_id'] ?? null)
                    ->where('reseller_id', $user->reseller_id)
                    ->where('is_active', true)
                    ->first();

                if (! $listing || ! $listing->isOrderable()) {
                    unset($cart[$key]);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            session([CartController::CART_SESSION_KEY => $cart]);
        }
    }

    public function domainRegistrationPrice(User $user, DomainExtension $extension, int $years): ?float
    {
        if (! $this->isResellerCustomer($user)) {
            $pricing = $extension->getRetailPricing($years);

            return $pricing ? (float) $pricing->price : null;
        }

        $resellerPricing = ResellerDomainPricing::query()
            ->where('reseller_id', $user->reseller_id)
            ->where('domain_extension_id', $extension->id)
            ->where('period_years', $years)
            ->where('enabled', true)
            ->first();

        return $resellerPricing ? (float) $resellerPricing->retail_price : null;
    }

    public function domainRenewalPrice(User $user, DomainExtension $extension, int $years): ?float
    {
        if (! $this->isResellerCustomer($user)) {
            $pricing = $extension->getRetailPricing($years);

            return $pricing ? (float) ($pricing->renewal_price ?? $pricing->price) : null;
        }

        $resellerPricing = ResellerDomainPricing::query()
            ->where('reseller_id', $user->reseller_id)
            ->where('domain_extension_id', $extension->id)
            ->where('period_years', $years)
            ->where('enabled', true)
            ->first();

        return $resellerPricing ? $resellerPricing->effectiveRenewalRetailPrice() : null;
    }

    public function domainTransferPrice(User $user, DomainExtension $extension): float
    {
        if (! $this->isResellerCustomer($user)) {
            return (float) ($extension->transfer_price ?? 0);
        }

        $registrationRetail = $this->domainRegistrationPrice($user, $extension, 1);
        $platformTransfer = (float) ($extension->transfer_price ?? 0);

        if ($registrationRetail === null) {
            return $platformTransfer;
        }

        $platformRegistration = (float) ($extension->getRetailPricing(1)?->price ?? $platformTransfer);

        if ($platformRegistration <= 0) {
            return $registrationRetail;
        }

        $markupRatio = $registrationRetail / $platformRegistration;

        return round($platformTransfer * $markupRatio, 2);
    }

    public function isPlatformCatalogRoute(string $routeName): bool
    {
        return in_array($routeName, [
            'customer.deploy-service',
            'customer.browse-services',
            'api.products',
        ], true);
    }

    /**
     * @return Collection<int, object{
     *     id: int,
     *     reseller_product_id: int|null,
     *     name: string,
     *     description: string|null,
     *     monthly_price: float,
     *     yearly_price: float|null,
     *     features: array<int, string>,
     *     slug: string|null
     * }>
     */
    public function resolveTechstackProductsForResellerCustomer(
        User $user,
        ContainerTemplate $language,
        ?DatabaseTemplate $database,
        array $routing,
    ): Collection {
        $listings = $this->activeCatalog($user)
            ->filter(fn (ResellerProduct $listing) => $listing->isOrderable())
            ->filter(fn (ResellerProduct $listing) => $this->listingIsActiveForTechstack($listing))
            ->filter(fn (ResellerProduct $listing) => $this->listingMatchesDatabase($listing, $database?->id));

        $isDirectAdmin = ($routing['hosting_type'] ?? '') === 'directadmin';

        $sharedListings = $listings->where('type', 'shared_hosting');
        $containerListings = $listings
            ->where('type', 'container_hosting')
            ->filter(fn (ResellerProduct $listing) => $this->listingMatchesLanguageTemplate($listing, $language));

        $matched = $isDirectAdmin ? $sharedListings : $containerListings;

        if ($matched->isEmpty() && $isDirectAdmin && $containerListings->isNotEmpty()) {
            $matched = $containerListings;
        }

        return $matched
            ->map(fn (ResellerProduct $listing) => $this->mapListingToTechstackProduct($listing))
            ->values();
    }

    public function techstackEmptyMessage(User $user, ContainerTemplate $language, array $routing): string
    {
        $catalog = $this->activeCatalog($user);
        $containerForLanguage = $catalog->filter(
            fn (ResellerProduct $listing) => $listing->type === 'container_hosting'
                && $this->listingMatchesLanguageTemplate($listing, $language)
        );

        if ($catalog->isEmpty()) {
            return 'Your provider has not published any hosting plans yet.';
        }

        if (($routing['hosting_type'] ?? '') === 'directadmin' && $containerForLanguage->isNotEmpty()) {
            return 'Your provider sells container plans for '.$language->name.'. Choose a container database (PostgreSQL, MySQL container, etc.) or contact your provider for guidance.';
        }

        if ($catalog->where('type', 'container_hosting')->isNotEmpty()) {
            return 'No plans match this tech stack. Select the same language as your provider\'s package (for example '.$this->exampleContainerLanguageNames($catalog).').';
        }

        return 'No hosting plans are available for this tech stack.';
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, object{
     *     id: int,
     *     reseller_product_id: int|null,
     *     name: string,
     *     description: string|null,
     *     monthly_price: float,
     *     yearly_price: float|null,
     *     features: array<int, string>,
     *     slug: string|null
     * }>
     */
    public function mapProductsForTechstackDisplay(User $user, Collection $products, ?int $databaseId = null): Collection
    {
        if (! $this->isResellerCustomer($user)) {
            return $products->map(fn (Product $product) => (object) [
                'id' => $product->id,
                'reseller_product_id' => null,
                'name' => $product->name,
                'description' => $product->description,
                'monthly_price' => (float) $product->monthly_price,
                'yearly_price' => $product->yearly_price !== null ? (float) $product->yearly_price : null,
                'features' => $product->features ?? [],
                'slug' => $product->slug,
            ]);
        }

        $listings = $this->activeCatalog($user)
            ->filter(fn (ResellerProduct $item) => $item->product_id !== null);

        return $products
            ->flatMap(function (Product $product) use ($listings, $databaseId) {
                return $listings
                    ->where('product_id', $product->id)
                    ->filter(fn (ResellerProduct $listing) => $this->listingMatchesDatabase($listing, $databaseId))
                    ->map(fn (ResellerProduct $listing) => $this->mapListingToTechstackProduct($listing));
            })
            ->values();
    }

    private function listingIsActiveForTechstack(ResellerProduct $listing): bool
    {
        if ($listing->usesDirectAdminPackage()) {
            return true;
        }

        return (bool) $listing->adminProduct?->is_active;
    }

    private function listingMatchesLanguageTemplate(ResellerProduct $listing, ContainerTemplate $language): bool
    {
        $templateId = $listing->container_template_id ?? $listing->adminProduct?->container_template_id;

        return $templateId !== null && (int) $templateId === (int) $language->id;
    }

    /**
     * @return object{
     *     id: int,
     *     reseller_product_id: int,
     *     name: string,
     *     description: string|null,
     *     monthly_price: float,
     *     yearly_price: float|null,
     *     features: array<int, string>,
     *     slug: string|null
     * }
     */
    private function mapListingToTechstackProduct(ResellerProduct $listing): object
    {
        $product = $listing->provisionProduct();

        return (object) [
            'id' => $product->id,
            'reseller_product_id' => $listing->id,
            'name' => $listing->name,
            'description' => $listing->description ?? $product?->description,
            'monthly_price' => (float) ($listing->monthly_price ?? 0),
            'yearly_price' => $listing->yearly_price !== null ? (float) $listing->yearly_price : null,
            'features' => $listing->features ?? $product?->features ?? [],
            'slug' => $product?->slug,
        ];
    }

    /**
     * @param  Collection<int, ResellerProduct>  $catalog
     */
    private function exampleContainerLanguageNames(Collection $catalog): string
    {
        $names = $catalog
            ->where('type', 'container_hosting')
            ->map(fn (ResellerProduct $listing) => $listing->adminProduct?->containerTemplate?->name)
            ->filter()
            ->unique()
            ->take(3)
            ->values();

        return $names->isNotEmpty() ? $names->implode(', ') : 'your provider\'s listed stack';
    }

    private function listingMatchesDatabase(ResellerProduct $listing, ?int $databaseId): bool
    {
        if ($listing->database_template_id === null) {
            return true;
        }

        return $databaseId !== null && $listing->database_template_id === $databaseId;
    }

    public function isHostingCatalogType(?string $type): bool
    {
        return in_array($type, ['shared_hosting', 'container_hosting'], true);
    }
}
