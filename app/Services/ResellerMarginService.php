<?php

namespace App\Services;

use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerMarginEntry;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceCurrencyService;
use Illuminate\Support\Collection;

class ResellerMarginService
{
    /**
     * Record whitelabel margin when a managed customer invoice is settled via credits.
     */
    public function recordFromSettledInvoice(User $reseller, Invoice $invoice): void
    {
        $invoice->loadMissing('items.product');

        if (! $this->isManagedCustomerInvoice($reseller, $invoice)) {
            return;
        }

        foreach ($invoice->items as $item) {
            if (ResellerMarginEntry::where('invoice_id', $invoice->id)
                ->whereNull('payment_id')
                ->where('description', $item->description)
                ->exists()) {
                continue;
            }

            $this->recordLineItemMargin($reseller, $invoice, null, $item, 1.0);
        }
    }

    /**
     * Record whitelabel margin earned when a managed customer payment completes.
     */
    public function recordFromPayment(User $reseller, Payment $payment): void
    {
        $invoice = $payment->invoice()->with('items.product')->first();

        if (! $invoice || ! $this->isManagedCustomerInvoice($reseller, $invoice)) {
            return;
        }

        $share = $this->paymentShareOfInvoice($payment, $invoice);

        foreach ($invoice->items as $item) {
            if (ResellerMarginEntry::where('payment_id', $payment->id)->where('description', $item->description)->exists()) {
                continue;
            }

            $this->recordLineItemMargin($reseller, $invoice, $payment, $item, $share);
        }
    }

    /**
     * @return Collection<int, ResellerMarginEntry>
     */
    public function ledgerQuery(User $reseller, ?string $from = null, ?string $to = null)
    {
        $query = ResellerMarginEntry::query()
            ->where('reseller_id', $reseller->id)
            ->with(['customer', 'invoice', 'payment'])
            ->latest();

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    public function ledgerTotals(User $reseller, ?string $from = null, ?string $to = null): array
    {
        $query = ResellerMarginEntry::query()->where('reseller_id', $reseller->id);

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return [
            'retail_total' => round((float) $query->clone()->sum('retail_amount'), 2),
            'wholesale_total' => round((float) $query->clone()->sum('wholesale_amount'), 2),
            'margin_total' => round((float) $query->clone()->sum('margin_amount'), 2),
            'entry_count' => (int) $query->clone()->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogMarginRows(User $reseller): array
    {
        $products = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->with('adminProduct')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'name' => $product->name,
                'monthly_retail' => (float) ($product->monthly_price ?? 0),
                'monthly_wholesale' => $product->getWholesaleMonthlyCost(),
                'monthly_margin' => $product->getMonthlyMargin(),
                'yearly_retail' => (float) ($product->yearly_price ?? 0),
                'yearly_wholesale' => $product->getWholesaleYearlyCost(),
                'yearly_margin' => $product->getYearlyMargin(),
                'is_custom' => $product->isCustom(),
            ];
        }

        return $rows;
    }

    private function isManagedCustomerInvoice(User $reseller, Invoice $invoice): bool
    {
        $customer = $invoice->user;

        return $customer instanceof User
            && app(ResellerScopeService::class)->ownsCustomer($reseller, $customer);
    }

    private function paymentShareOfInvoice(Payment $payment, Invoice $invoice): float
    {
        if ((float) $invoice->total <= 0) {
            return 1.0;
        }

        $invoiceAmount = app(InvoiceCurrencyService::class)
            ->paymentAmountInInvoiceCurrency(
                $invoice,
                (float) $payment->amount,
                $payment->currency ?? config('currency.base', 'KES')
            );

        return min(1.0, $invoiceAmount / (float) $invoice->total);
    }

    private function recordLineItemMargin(
        User $reseller,
        Invoice $invoice,
        ?Payment $payment,
        InvoiceItem $item,
        float $share,
    ): void {
        [$type, $retail, $wholesale] = $this->resolveAmounts($reseller, $item);

        $retailPortion = round($retail * $share, 2);
        $wholesalePortion = round($wholesale * $share, 2);

        ResellerMarginEntry::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'payment_id' => $payment?->id,
            'entry_type' => $type,
            'description' => $item->description,
            'retail_amount' => $retailPortion,
            'wholesale_amount' => $wholesalePortion,
            'margin_amount' => round($retailPortion - $wholesalePortion, 2),
        ]);
    }

    /**
     * @return array{0: string, 1: float, 2: float}
     */
    private function resolveAmounts(User $reseller, InvoiceItem $item): array
    {
        $retail = (float) $item->amount;

        if ($item->product_type === 'Domain' && isset($item->custom_options['domain_order_id'])) {
            $order = ResellerDomainOrder::find($item->custom_options['domain_order_id']);
            if ($order) {
                return [
                    'domain',
                    $retail,
                    (float) $order->wholesale_amount,
                ];
            }
        }

        if ($item->product_type === 'Domain' && isset($item->custom_options['renewal_order_id'])) {
            $order = DomainRenewalOrder::find($item->custom_options['renewal_order_id']);
            if ($order && $order->isResellerManaged()) {
                return [
                    'domain_renewal',
                    $retail,
                    $order->effectiveWholesaleAmount(),
                ];
            }
        }

        if ($item->product_id) {
            $product = $item->product ?? Product::find($item->product_id);
            $wholesale = $this->wholesaleForProductLine($product, $item);

            return ['catalog', $retail, $wholesale];
        }

        $catalog = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('product_id', $item->product_id)
            ->first();

        if ($catalog && $catalog->adminProduct) {
            return ['catalog', $retail, $this->wholesaleForProductLine($catalog->adminProduct, $item)];
        }

        return ['custom', $retail, 0.0];
    }

    private function wholesaleForProductLine(?Product $product, InvoiceItem $item): float
    {
        if (! $product) {
            return 0.0;
        }

        $cycle = $this->inferBillingCycle($item->description);

        if ($product->type === 'container_hosting') {
            $months = match ($cycle) {
                'annual' => 12,
                'semi-annual' => 6,
                'quarterly' => 3,
                default => 1,
            };
            $monthly = $this->containerMonthlyWholesale($product, $item);

            return $monthly === null ? 0.0 : round($monthly * $months, 2);
        }

        return match ($cycle) {
            'annual' => (float) ($product->wholesale_yearly_price ?? (($product->wholesale_monthly_price ?? 0) * 12)),
            'quarterly' => (float) (($product->wholesale_monthly_price ?? 0) * 3),
            'semi-annual' => (float) (($product->wholesale_monthly_price ?? 0) * 6),
            default => (float) ($product->wholesale_monthly_price ?? 0),
        };
    }

    /**
     * The listing sold on this line, found through its service, priced by the rate card.
     */
    private function containerMonthlyWholesale(Product $product, InvoiceItem $item): ?float
    {
        $rateCard = app(ResellerContainerRateCard::class);
        $listing = null;
        if ($item->service_id) {
            $service = Service::query()->find($item->service_id);
            $meta = is_array($service?->service_meta) ? $service->service_meta : [];
            $listingId = (int) ($service?->reseller_product_id ?? $meta['reseller_product_id'] ?? 0);
            if ($listingId > 0) {
                $listing = ResellerProduct::query()->find($listingId);
            }
            if ($listing === null && is_array($meta['reseller_catalog_limits'] ?? null)) {
                return $rateCard->monthlyWholesaleForLimits($meta['reseller_catalog_limits']);
            }
        }
        if ($listing instanceof ResellerProduct) {
            return $rateCard->monthlyWholesaleForListing($listing);
        }
        $product->loadMissing('containerTemplate');
        $included = $product->getIncludedContainerLimits($product->containerTemplate);

        return $rateCard->monthlyWholesaleForLimits([
            'cpu' => $included['cpu'] ?? null,
            'memory_mb' => $included['memory_mb'] ?? null,
            'disk_gb' => $included['disk_gb'] ?? null,
            'bandwidth_gb' => $product->includedBandwidthGb() ?: null,
        ]);
    }

    private function inferBillingCycle(string $description): string
    {
        $lower = strtolower($description);

        if (str_contains($lower, 'annual')) {
            return 'annual';
        }

        if (str_contains($lower, 'semi-annual') || str_contains($lower, 'semi annual')) {
            return 'semi-annual';
        }

        if (str_contains($lower, 'quarterly')) {
            return 'quarterly';
        }

        return 'monthly';
    }
}
