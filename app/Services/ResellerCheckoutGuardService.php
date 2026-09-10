<?php

namespace App\Services;

use App\Models\ResellerProduct;
use App\Models\User;

class ResellerCheckoutGuardService
{
    public function __construct(
        private ResellerDiskUsageService $diskUsage,
        private ResellerComputeUsageService $computeUsage,
    ) {}

    /**
     * The same checks, plus whether this particular cart still fits.
     *
     * A separate method rather than an optional argument on the one above: an
     * optional cart turns a call site somebody forgot to update into a check
     * that silently passes, which is the worst kind of limit.
     *
     * @param  array<string, array<string, mixed>>  $cart
     *
     * @throws \InvalidArgumentException
     */
    public function assertCartAllowed(User $customer, array $cart): void
    {
        $this->assertCheckoutAllowed($customer);

        if (! $customer->reseller_id) {
            return;
        }

        $reseller = User::query()->find($customer->reseller_id);
        if (! $reseller?->is_reseller) {
            return;
        }

        $requested = $this->requestedComputeForCart($reseller, $cart);
        if ($requested['cpu_cores'] <= 0 && $requested['memory_mb'] <= 0) {
            return;
        }

        if (! $this->computeUsage->checkHeadroom($reseller, $requested['cpu_cores'], $requested['memory_mb'])['allowed']) {
            // Deliberately vague about the provider's numbers: this message is
            // read by their customer, who is not entitled to their capacity.
            throw new \InvalidArgumentException(
                'Your provider does not have enough capacity for this plan right now. Contact them to upgrade.'
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $cart
     * @return array{cpu_cores: float, memory_mb: int}
     */
    private function requestedComputeForCart(User $reseller, array $cart): array
    {
        $cpu = 0.0;
        $memory = 0;

        foreach ($cart as $item) {
            if (($item['type'] ?? '') !== 'reseller_product') {
                continue;
            }

            $listing = ResellerProduct::query()
                ->where('id', $item['reseller_product_id'] ?? null)
                ->where('reseller_id', $reseller->id)
                ->first();

            if (! $listing || $listing->type !== 'container_hosting') {
                continue;
            }

            $requested = $this->computeUsage->requestedAllocationForListing(
                $listing,
                app(ResellerProvisionProductResolver::class)->resolve($listing),
            );

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $cpu += $requested['cpu_cores'] * $quantity;
            $memory += $requested['memory_mb'] * $quantity;
        }

        return ['cpu_cores' => round($cpu, 2), 'memory_mb' => $memory];
    }

    public function assertCheckoutAllowed(User $customer): void
    {
        if (! $customer->reseller_id) {
            return;
        }

        $reseller = User::query()->find($customer->reseller_id);
        if (! $reseller?->is_reseller) {
            throw new \InvalidArgumentException('Your account is not linked to an active reseller. Contact support.');
        }

        if ($reseller->isResellerSuspended()) {
            throw new \InvalidArgumentException(
                'Your provider\'s account is temporarily suspended. New orders cannot be placed until billing is restored.'
            );
        }

        if (! $reseller->hasResellerPackage()) {
            throw new \InvalidArgumentException(
                'Your provider has not activated a reseller package yet. New orders are unavailable.'
            );
        }

        if ($reseller->isAtServiceLimit()) {
            throw new \InvalidArgumentException(
                'Your provider has reached their service capacity. Contact them to upgrade before ordering more hosting.'
            );
        }

        if ($reseller->isAtUserLimit()) {
            throw new \InvalidArgumentException(
                'Your provider has reached their hosted user limit. Contact them to upgrade before ordering more hosting.'
            );
        }

        if ($this->diskUsage->isOverPool($reseller)) {
            throw new \InvalidArgumentException(
                'Your provider has exceeded their disk pool allocation. New hosting orders are temporarily unavailable.'
            );
        }
    }
}
