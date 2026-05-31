<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\Setting;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function show()
    {
        $cart = session(CartController::CART_KEY, []);

        if (empty($cart)) {
            return redirect()->route('reseller.cart.index')
                ->with('warning', 'Your cart is empty');
        }

        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if ($item['type'] === 'domain') {
                $total = $item['price'] * $item['years'];
                $subtotal += $total;
                $items[$key] = array_merge($item, ['total' => $total]);
            }
        }

        $taxEnabled = Setting::getValue('tax_enabled') === 'true';
        $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        $user = auth()->user();

        return view('reseller.checkout.index', compact('items', 'subtotal', 'tax', 'taxEnabled', 'taxRate', 'total', 'user'));
    }

    public function process(Request $request)
    {
        $cart = session(CartController::CART_KEY, []);

        if (empty($cart)) {
            return redirect()->route('reseller.cart.index')
                ->with('error', 'Your cart is empty');
        }

        $reseller = auth()->user();
        $subtotal = 0;
        $invoiceItems = [];
        $domainOrders = [];

        try {
            \DB::beginTransaction();

            foreach ($cart as $item) {
                if ($item['type'] === 'domain') {
                    $extension = DomainExtension::where('extension', $item['extension'])->first();

                    if (! $extension) {
                        throw new \Exception("Extension {$item['extension']} not found");
                    }

                    $wholesalePrice = $extension->pricing()
                        ->where('tier', 'wholesale')
                        ->where('period_years', $item['years'])
                        ->first();

                    if (! $wholesalePrice) {
                        throw new \Exception("No wholesale pricing for {$item['extension']} ({$item['years']} years)");
                    }

                    $wholesaleAmount = $wholesalePrice->price * $item['years'];
                    $subtotal += $wholesaleAmount;

                    // Create domain
                    $domain = Domain::create([
                        'user_id' => $reseller->id,
                        'name' => $item['domain'],
                        'extension' => $item['extension'],
                        'status' => 'pending',
                        'type' => 'registration',
                        'auto_renew' => false,
                    ]);

                    // Create reseller domain order
                    $order = ResellerDomainOrder::create([
                        'reseller_id' => $reseller->id,
                        'customer_id' => $reseller->id,
                        'domain_id' => $domain->id,
                        'domain_name' => $item['domain'],
                        'extension' => $item['extension'],
                        'years' => $item['years'],
                        'wholesale_amount' => $wholesaleAmount,
                        'retail_amount' => 0,
                        'status' => 'queued',
                    ]);

                    $domainOrders[] = $order->id;

                    // Create invoice item with domain order reference
                    $invoiceItems[] = [
                        'description' => $item['domain'].$item['extension'].' ('.$item['years'].' year'.($item['years'] > 1 ? 's' : '').')',
                        'quantity' => 1,
                        'unit_price' => $wholesaleAmount,
                        'custom_options' => ['domain_order_id' => $order->id],
                    ];
                }
            }

            $taxEnabled = Setting::getValue('tax_enabled') === 'true';
            $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
            $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
            $total = $subtotal + $tax;

            // Create invoice
            $invoice = Invoice::create([
                'user_id' => $reseller->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'status' => 'unpaid',
                'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                'subtotal' => $subtotal,
                'tax' => $taxEnabled ? $tax : 0,
                'total' => $total,
                'notes' => 'Domain registration order',
            ]);

            // Create invoice items
            foreach ($invoiceItems as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => null,
                    'product_type' => 'Domain',
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'amount' => $itemData['quantity'] * $itemData['unit_price'],
                    'custom_options' => $itemData['custom_options'],
                ]);
            }

            \DB::commit();

            // Link domain orders to this invoice for self-purchase push flow
            ResellerDomainOrder::whereIn('id', $domainOrders)->update([
                'customer_invoice_id' => $invoice->id,
            ]);

            session()->forget(CartController::CART_KEY);

            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Order created successfully. Please proceed to payment.');
        } catch (\Exception $e) {
            \DB::rollBack();

            return redirect()->route('reseller.checkout.show')
                ->withInput()
                ->with('error', 'Failed to create order: '.$e->getMessage());
        }
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-".str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
