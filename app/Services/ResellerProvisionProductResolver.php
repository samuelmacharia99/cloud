<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerProduct;

class ResellerProvisionProductResolver
{
    public const SHELL_PRODUCT_SLUG = 'platform-reseller-directadmin-hosting';

    public function resolve(ResellerProduct $listing): ?Product
    {
        if ($listing->product_id) {
            return $listing->adminProduct;
        }

        if ($listing->usesDirectAdminPackage()) {
            return $this->shellDirectAdminProduct();
        }

        return null;
    }

    public function isOrderable(ResellerProduct $listing): bool
    {
        if (! $listing->is_active) {
            return false;
        }

        return $this->resolve($listing) instanceof Product;
    }

    public function shellDirectAdminProduct(): Product
    {
        return Product::firstOrCreate(
            ['slug' => self::SHELL_PRODUCT_SLUG],
            [
                'name' => 'Reseller DirectAdmin Hosting (system)',
                'type' => 'shared_hosting',
                'provisioning_driver_key' => 'directadmin',
                'is_active' => false,
                'visible_to_resellers' => false,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'setup_fee' => 0,
            ]
        );
    }
}
