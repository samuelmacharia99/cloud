<?php

namespace App\Services;

use App\Enums\ResellerDomainOrderType;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\ResellerDomainOrder;
use App\Models\User;

class ResellerDomainOrderService
{
    public function createForCustomerCheckout(
        User $customer,
        Domain $domain,
        Invoice $invoice,
        string $domainName,
        string $extension,
        int $years,
        float $retailAmount,
        ResellerDomainOrderType $orderType = ResellerDomainOrderType::Registration,
    ): ?ResellerDomainOrder {
        if ($orderType === ResellerDomainOrderType::Transfer) {
            return $this->createForTransferCheckout(
                $customer,
                $domain,
                $invoice,
                $domainName,
                $extension,
                $retailAmount,
            );
        }

        if ($customer->reseller_id === null) {
            return ResellerDomainOrder::create([
                'reseller_id' => null,
                'customer_id' => $customer->id,
                'domain_id' => $domain->id,
                'customer_invoice_id' => $invoice->id,
                'domain_name' => $domainName,
                'extension' => $extension,
                'order_type' => ResellerDomainOrderType::Registration,
                'years' => $years,
                'wholesale_amount' => round($retailAmount, 2),
                'retail_amount' => 0,
                'status' => 'queued',
                'push_mode' => 'auto',
                'queued_at' => now(),
                'expires_at' => now()->addDays(10),
            ]);
        }

        $wholesaleAmount = $this->resolveWholesaleAmount($extension, $years, $retailAmount);
        $retailMargin = max(0, round($retailAmount - $wholesaleAmount, 2));

        return $this->createResellerManagedOrder(
            $customer,
            $domain,
            $invoice,
            $domainName,
            $extension,
            $years,
            $wholesaleAmount,
            $retailMargin,
            ResellerDomainOrderType::Registration,
        );
    }

    public function createForTransferCheckout(
        User $customer,
        Domain $domain,
        Invoice $invoice,
        string $domainName,
        string $extension,
        float $retailAmount,
    ): ?ResellerDomainOrder {
        if ($customer->reseller_id === null) {
            return null;
        }

        $wholesaleAmount = $this->resolveTransferWholesaleAmount($extension, $retailAmount);
        $retailMargin = max(0, round($retailAmount - $wholesaleAmount, 2));

        return $this->createResellerManagedOrder(
            $customer,
            $domain,
            $invoice,
            $domainName,
            $extension,
            1,
            $wholesaleAmount,
            $retailMargin,
            ResellerDomainOrderType::Transfer,
        );
    }

    /**
     * Attach a reseller domain order to a shared-hosting checkout domain line item.
     */
    public function createForHostingAddonLine(
        User $customer,
        Domain $domain,
        Invoice $invoice,
        string $domainName,
        string $extension,
        float $retailAmount,
        ResellerDomainOrderType $orderType,
        int $years = 1,
    ): ?ResellerDomainOrder {
        if ($customer->reseller_id === null) {
            return null;
        }

        if ($orderType === ResellerDomainOrderType::Transfer) {
            return $this->createForTransferCheckout(
                $customer,
                $domain,
                $invoice,
                $domainName,
                $extension,
                $retailAmount,
            );
        }

        return $this->createForCustomerCheckout(
            $customer,
            $domain,
            $invoice,
            $domainName,
            $extension,
            $years,
            $retailAmount,
            ResellerDomainOrderType::Registration,
        );
    }

    public function resolveWholesaleAmount(string $extension, int $years, float $fallback): float
    {
        $domainExtension = DomainExtension::where('extension', $extension)->first();

        if (! $domainExtension) {
            return round($fallback, 2);
        }

        $wholesalePricing = $domainExtension->getWholesalePricing($years);

        return round((float) ($wholesalePricing?->price ?? $fallback), 2);
    }

    public function resolveTransferWholesaleAmount(string $extension, float $fallback): float
    {
        $domainExtension = DomainExtension::where('extension', $extension)->first();

        if (! $domainExtension) {
            return round($fallback, 2);
        }

        return round((float) ($domainExtension->transfer_price ?? $fallback), 2);
    }

    public function invoiceItemAttributes(?ResellerDomainOrder $domainOrder): array
    {
        if ($domainOrder === null) {
            return [];
        }

        return [
            'product_type' => 'Domain',
            'domain_id' => $domainOrder->domain_id,
            'custom_options' => [
                'domain_order_id' => $domainOrder->id,
                'reseller_id' => $domainOrder->reseller_id,
                'order_type' => $domainOrder->order_type?->value ?? ResellerDomainOrderType::Registration->value,
                'domain_id' => $domainOrder->domain_id,
            ],
        ];
    }

    private function createResellerManagedOrder(
        User $customer,
        Domain $domain,
        Invoice $invoice,
        string $domainName,
        string $extension,
        int $years,
        float $wholesaleAmount,
        float $retailMargin,
        ResellerDomainOrderType $orderType,
    ): ResellerDomainOrder {
        $order = ResellerDomainOrder::create([
            'reseller_id' => $customer->reseller_id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => $domainName,
            'extension' => $extension,
            'order_type' => $orderType,
            'years' => $years,
            'wholesale_amount' => $wholesaleAmount,
            'retail_amount' => $retailMargin,
            'status' => 'queued',
            'push_mode' => 'auto',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        if ($domain->domain_order_id === null) {
            $domain->update([
                'reseller_id' => $customer->reseller_id,
                'domain_order_id' => $order->id,
            ]);
        }

        return $order;
    }
}
