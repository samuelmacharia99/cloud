<?php

namespace App\Services;

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
    ): ?ResellerDomainOrder {
        if ($customer->reseller_id === null) {
            return ResellerDomainOrder::create([
                'reseller_id' => null,
                'customer_id' => $customer->id,
                'domain_id' => $domain->id,
                'customer_invoice_id' => $invoice->id,
                'domain_name' => $domainName,
                'extension' => $extension,
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

        return ResellerDomainOrder::create([
            'reseller_id' => $customer->reseller_id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => $domainName,
            'extension' => $extension,
            'years' => $years,
            'wholesale_amount' => $wholesaleAmount,
            'retail_amount' => $retailMargin,
            'status' => 'queued',
            'push_mode' => 'auto',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);
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

    public function invoiceItemAttributes(?ResellerDomainOrder $domainOrder): array
    {
        if ($domainOrder === null) {
            return [];
        }

        return [
            'product_type' => 'Domain',
            'custom_options' => [
                'domain_order_id' => $domainOrder->id,
                'reseller_id' => $domainOrder->reseller_id,
            ],
        ];
    }
}
