<?php

namespace App\Services\Checkout;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerCustomerCatalogService;

/**
 * Builds a hosting-plan cart line the same way for every entry point (the
 * cart page's Add, the deploy page's plan choice), with the tenancy and
 * catalogue rules in one place: reseller customers order their reseller's
 * listings only, platform customers order platform application hosting only.
 */
class CartLineFactory
{
    public const BILLING_CYCLES = ['monthly', 'quarterly', 'semi-annual', 'annual'];

    public function __construct(
        private ResellerCustomerCatalogService $catalog,
    ) {}

    /**
     * @return array{type: string, product_id: int, billing_cycle: string}
     *
     * @throws CartLineException
     */
    public function forProduct(User $user, int $productId, string $billingCycle): array
    {
        if ($this->catalog->isResellerCustomer($user)) {
            throw new CartLineException('Please order hosting through Deploy New Service or the services catalog.', 403);
        }

        $this->assertCycle($billingCycle);

        $product = Product::query()->whereKey($productId)->where('is_active', true)->first();
        if (! $product) {
            throw new CartLineException('That plan is no longer available.', 422);
        }
        if ($product->type === 'shared_hosting') {
            throw new CartLineException('Shared DirectAdmin hosting is no longer available. Please deploy with application hosting.', 422);
        }

        return [
            'type' => 'product',
            'product_id' => (int) $product->id,
            'billing_cycle' => $billingCycle,
        ];
    }

    /**
     * @return array{type: string, reseller_product_id: int, product_id: ?int, reseller_id: int, billing_cycle: string}
     *
     * @throws CartLineException
     */
    public function forResellerProduct(User $user, int $listingId, string $billingCycle): array
    {
        if (! $this->catalog->isResellerCustomer($user)) {
            throw new CartLineException('Reseller catalog items are only available to reseller customers.', 403);
        }

        $this->assertCycle($billingCycle);

        $listing = ResellerProduct::query()
            ->whereKey($listingId)
            ->where('reseller_id', $user->reseller_id)
            ->where('is_active', true)
            ->first();

        if (! $listing || ! $listing->isOrderable()) {
            throw new CartLineException('This catalog item is not available for ordering.', 422);
        }

        return [
            'type' => 'reseller_product',
            'reseller_product_id' => (int) $listing->id,
            'product_id' => $listing->provisionProduct()?->id,
            'reseller_id' => (int) $listing->reseller_id,
            'billing_cycle' => $billingCycle,
        ];
    }

    private function assertCycle(string $billingCycle): void
    {
        if (! in_array($billingCycle, self::BILLING_CYCLES, true)) {
            throw new CartLineException('Choose a billing cycle.', 422);
        }
    }
}
