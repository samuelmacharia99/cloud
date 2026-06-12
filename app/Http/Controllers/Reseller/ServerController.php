<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function index()
    {
        $reseller = auth()->user();

        $services = Service::with('product')
            ->where('user_id', $reseller->id)
            ->whereHas('product', fn ($q) => $q->whereIn('type', ['vps', 'dedicated_server']))
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->latest()
            ->get();

        $vpsProducts = Product::where('type', 'vps')
            ->where('is_active', true)
            ->where('visible_to_resellers', true)
            ->whereNotNull('wholesale_monthly_price')
            ->orderBy('wholesale_monthly_price')
            ->get();

        $dedicatedProducts = Product::where('type', 'dedicated_server')
            ->where('is_active', true)
            ->where('visible_to_resellers', true)
            ->whereNotNull('wholesale_monthly_price')
            ->orderBy('wholesale_monthly_price')
            ->get();

        $currencyCode = Setting::getValue('currency', 'KES');
        $linuxDistros = config('server_options.linux_distributions');
        $maxIpCount = config('server_options.max_ip_count', 8);

        return view('reseller.servers.index', compact(
            'services',
            'vpsProducts',
            'dedicatedProducts',
            'currencyCode',
            'linuxDistros',
            'maxIpCount'
        ));
    }

    public function order(Request $request)
    {
        $validOs = implode(',', array_keys(config('server_options.linux_distributions')));

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'billing_cycle' => 'required|in:monthly,annual',
            'operating_system' => 'required|string|in:'.$validOs,
            'ip_count' => 'required|integer|min:1|max:'.config('server_options.max_ip_count', 8),
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $reseller = auth()->user();

        abort_if(! $product->is_active, 422, 'Product is not available.');
        abort_if(! Product::isServerType($product->type), 422, 'Invalid product type.');
        abort_if(! $product->visible_to_resellers, 403, 'Product not available for resellers.');

        $price = $this->getWholesalePrice($product, $validated['billing_cycle']);
        abort_if($price <= 0, 422, 'No wholesale price set for this product and billing cycle.');

        $taxBreakdown = TaxService::calculateResellerWholesale($price);
        $tax = $taxBreakdown['tax'];
        $total = $taxBreakdown['total'];
        $dueDays = (int) Setting::getValue('invoice_due_days', 14);
        $prefix = Setting::getValue('invoice_prefix', 'INV');

        $invoice = DB::transaction(function () use ($reseller, $product, $validated, $price, $tax, $total, $dueDays, $prefix, $taxBreakdown) {
            $year = now()->format('Y');
            $sequence = Invoice::whereYear('created_at', $year)->lockForUpdate()->count() + 1;
            $number = $prefix.'-'.$year.'-'.str_pad($sequence, 5, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'user_id' => $reseller->id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => now()->addDays($dueDays),
                'subtotal' => $taxBreakdown['subtotal'],
                'tax' => $tax,
                'total' => $total,
                'notes' => 'Reseller server order — '.$product->name.' ('.ucfirst($validated['billing_cycle']).')',
            ]);

            $service = Service::create([
                'user_id' => $reseller->id,
                'reseller_id' => $reseller->id,
                'product_id' => $product->id,
                'invoice_id' => $invoice->id,
                'name' => $product->name,
                'billing_cycle' => $validated['billing_cycle'],
                'status' => ServiceStatus::Pending,
                'next_due_date' => $this->getNextDueDate($validated['billing_cycle']),
                'provisioning_driver_key' => $product->provisioning_driver_key,
                'service_meta' => [
                    'operating_system' => $validated['operating_system'],
                    'ip_count' => (int) $validated['ip_count'],
                ],
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $product->id,
                'description' => $product->name.' — '.ucfirst($validated['billing_cycle']),
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
            ]);

            return $invoice;
        });

        return redirect()
            ->route('reseller.payment.select-method', $invoice)
            ->with('success', 'Order placed. Complete payment to provision your server.');
    }

    private function getWholesalePrice(Product $product, string $cycle): float
    {
        return match ($cycle) {
            'monthly' => (float) ($product->wholesale_monthly_price ?? 0),
            'annual' => (float) ($product->wholesale_yearly_price ?? ($product->wholesale_monthly_price * 12) ?? 0),
            default => 0,
        };
    }

    private function getNextDueDate(string $cycle): string
    {
        return match ($cycle) {
            'monthly' => now()->addMonth()->toDateString(),
            'annual' => now()->addYear()->toDateString(),
            default => now()->addMonth()->toDateString(),
        };
    }
}
