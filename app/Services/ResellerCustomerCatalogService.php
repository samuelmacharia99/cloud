<?php

namespace App\Services;

use App\Http\Controllers\Customer\CartController;
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
            ->with('adminProduct')
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

                if (! $listing || ! $listing->product_id) {
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

    public function isPlatformCatalogRoute(string $routeName): bool
    {
        return in_array($routeName, [
            'customer.deploy-service',
            'customer.browse-services',
            'api.products',
        ], true);
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
                    ->map(fn (ResellerProduct $listing) => (object) [
                        'id' => $product->id,
                        'reseller_product_id' => $listing->id,
                        'name' => $listing->name,
                        'description' => $listing->description ?? $product->description,
                        'monthly_price' => (float) ($listing->monthly_price ?? 0),
                        'yearly_price' => $listing->yearly_price !== null ? (float) $listing->yearly_price : null,
                        'features' => $product->features ?? [],
                        'slug' => $product->slug,
                    ]);
            })
            ->values();
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
