<?php

namespace App\Http\Controllers\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class CheckoutController extends Controller
{
    const CART_SESSION_KEY = 'cart';

    /**
     * Show checkout page
     */
    public function show()
    {
        $cart = session(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            return redirect()->route('customer.cart.index')->with('error', 'Your cart is empty');
        }

        // Prepare cart items with details
        $cartItems = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            $item['key'] = $key;

            if ($item['type'] === 'product') {
                $product = Product::find($item['product_id']);
                if (!$product) continue;

                $item['name'] = $product->name;
                $item['description'] = $product->description ?? $product->name;
                $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                $item['amount'] = $item['unit_price'];
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if (!$extension) continue;

                $pricing = $extension->getRetailPricing($item['years']);
                $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
                $item['amount'] = $item['unit_price'];
                $item['name'] = "{$item['domain']}{$item['extension']}";
                $item['description'] = "Domain registration for {$item['years']} year(s)";
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        if (empty($cartItems)) {
            return redirect()->route('customer.cart.index')->with('error', 'No valid items in cart');
        }

        // Calculate tax
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        return view('customer.checkout.index', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'user' => auth()->user(),
        ]);
    }

    /**
     * Process checkout and create order
     */
    public function process(Request $request)
    {
        $request->validate([
            'agree_terms' => 'required|accepted',
        ]);

        $cart = session(self::CART_SESSION_KEY, []);

        if (empty($cart)) {
            return back()->with('error', 'Your cart is empty');
        }

        $user = auth()->user();

        try {
            $order = \DB::transaction(function () use ($cart, $user) {
                // Get cart items with details
                $cartItems = [];
                $subtotal = 0;

                foreach ($cart as $key => $item) {
                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);
                        if (!$product) continue;

                        $price = $this->getProductPrice($product, $item['billing_cycle']);
                        $item['unit_price'] = $price;
                        $item['amount'] = $price;
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();
                        if (!$extension) continue;

                        $pricing = $extension->getRetailPricing($item['years']);
                        $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
                        $item['amount'] = $item['unit_price'];
                    }

                    $subtotal += $item['amount'];
                    $cartItems[] = $item;
                }

                if (empty($cartItems)) {
                    throw new \Exception('No valid items in cart');
                }

                // Calculate totals
                $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                $taxRate = (float) Setting::getValue('tax_rate', 0);
                $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
                $total = $subtotal + $tax;

                // Create Order
                $order = Order::create([
                    'user_id' => $user->id,
                    'order_number' => 'ORD-' . uniqid(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create Invoice
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                // Create OrderItems, Services, and Domains
                foreach ($cartItems as $item) {
                    if ($item['type'] === 'product') {
                        $product = Product::find($item['product_id']);

                        // Create OrderItem
                        $orderItem = OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'description' => $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                            'billing_cycle' => $item['billing_cycle'],
                            'custom_options' => [],
                        ]);

                        // Create Service
                        $service = Service::create([
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'order_item_id' => $orderItem->id,
                            'name' => $product->name,
                            'status' => 'pending',
                            'billing_cycle' => $item['billing_cycle'],
                            'next_due_date' => now()->addMonths($this->billingCycleMonths($item['billing_cycle'])),
                            'provisioning_driver_key' => $product->provisioning_driver_key,
                        ]);

                        // Create InvoiceItem
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_id' => $service->id,
                            'product_id' => $product->id,
                            'description' => $product->name,
                            'quantity' => 1,
                            'unit_price' => $item['unit_price'],
                            'amount' => $item['amount'],
                        ]);
                    } elseif ($item['type'] === 'domain') {
                        $extension = DomainExtension::where('extension', $item['extension'])->first();

                        // Create Domain
                        $domain = Domain::create([
                            'user_id' => $user->id,
                            'name' => $item['domain'],
                            'extension' => $item['extension'],
                            'status' => 'pending',
                        ]);

                        // Create Service for domain
                        $domainProduct = Product::where('type', 'domain')->first();
                        if ($domainProduct) {
                            $orderItem = OrderItem::create([
                                'order_id' => $order->id,
                                'product_id' => $domainProduct->id,
                                'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                                'quantity' => 1,
                                'unit_price' => $item['unit_price'],
                                'amount' => $item['amount'],
                                'billing_cycle' => 'annual',
                                'custom_options' => [
                                    'domain' => $item['domain'],
                                    'extension' => $item['extension'],
                                    'years' => $item['years'],
                                ],
                            ]);

                            $service = Service::create([
                                'user_id' => $user->id,
                                'product_id' => $domainProduct->id,
                                'order_item_id' => $orderItem->id,
                                'name' => "{$item['domain']}{$item['extension']}",
                                'status' => 'pending',
                                'billing_cycle' => 'annual',
                                'next_due_date' => now()->addDays($item['years'] * 365),
                                'service_meta' => [
                                    'domain_id' => $domain->id,
                                    'domain_name' => $item['domain'],
                                    'extension' => $item['extension'],
                                    'years' => $item['years'],
                                ],
                            ]);

                            InvoiceItem::create([
                                'invoice_id' => $invoice->id,
                                'service_id' => $service->id,
                                'product_id' => $domainProduct->id,
                                'description' => "{$item['domain']}{$item['extension']} ({$item['years']} year(s))",
                                'quantity' => 1,
                                'unit_price' => $item['unit_price'],
                                'amount' => $item['amount'],
                            ]);
                        }
                    }
                }

                return $order;
            });

            // Clear cart
            session([self::CART_SESSION_KEY => []]);

            return redirect()
                ->route('customer.invoices.show', $order->id ? Invoice::where('user_id', $user->id)->latest()->first() : null)
                ->with('success', 'Order placed successfully! Please pay your invoice to activate services.');
        } catch (\Exception $e) {
            \Log::error("Checkout failed: {$e->getMessage()}");
            return back()->with('error', 'Checkout failed: ' . $e->getMessage());
        }
    }

    /**
     * Get product price based on billing cycle
     */
    private function getProductPrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }

    /**
     * Convert billing cycle to months
     */
    private function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
