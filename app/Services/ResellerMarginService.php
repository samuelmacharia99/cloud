<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerMarginEntry;
use App\Models\ResellerProduct;
use App\Models\User;
use Illuminate\Support\Collection;

class ResellerMarginService
{
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

        return min(1.0, (float) $payment->amount / (float) $invoice->total);
    }

    private function recordLineItemMargin(
        User $reseller,
        Invoice $invoice,
        Payment $payment,
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
            'payment_id' => $payment->id,
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
                    (float) ($order->retail_amount ?: $retail),
                    (float) $order->wholesale_amount,
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

        if ($product->type === 'container_hosting') {
            return 0.0;
        }

        $cycle = $this->inferBillingCycle($item->description);

        return match ($cycle) {
            'annual' => (float) ($product->wholesale_yearly_price ?? (($product->wholesale_monthly_price ?? 0) * 12)),
            'quarterly' => (float) (($product->wholesale_monthly_price ?? 0) * 3),
            'semi-annual' => (float) (($product->wholesale_monthly_price ?? 0) * 6),
            default => (float) ($product->wholesale_monthly_price ?? 0),
        };
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
