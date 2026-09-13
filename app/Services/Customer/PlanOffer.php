<?php

namespace App\Services\Customer;

/**
 * A plan the customer can buy on the deploy page, platform product or
 * reseller listing alike. `productId` is always the platform product that
 * provisions it; `resellerProductId` is set for reseller customers.
 */
final class PlanOffer
{
    /**
     * @param  list<string>  $features
     * @param  array<string, mixed>  $resourceLimits
     */
    public function __construct(
        public readonly int $productId,
        public readonly ?int $resellerProductId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly float $monthlyPrice,
        public readonly ?float $yearlyPrice,
        public readonly array $features,
        public readonly array $resourceLimits,
        public readonly ?int $pinnedTemplateId,
        public readonly ?string $pinnedTemplateName,
        public readonly int $eligibleStackCount,
        public readonly bool $featured = false,
    ) {}

    public function key(): string
    {
        return $this->resellerProductId ? 'listing-'.$this->resellerProductId : 'product-'.$this->productId;
    }
}
