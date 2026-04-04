<?php

namespace App\Http\Controllers\Customer;

use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class CartController extends Controller
{
    const CART_SESSION_KEY = 'cart';

    /**
     * Display the shopping cart
     */
    public function index()
    {
        $cart = session(self::CART_SESSION_KEY, []);
        $cartItems = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            $item['key'] = $key;

            if ($item['type'] === 'product') {
                $product = Product::find($item['product_id']);
                if ($product) {
                    $item['name'] = $product->name;
                    $item['description'] = $product->description ?? $product->name;
                    $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                    $item['amount'] = $item['unit_price'];
                } else {
                    continue; // Skip if product not found
                }
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if ($extension) {
                    $pricing = $extension->getRetailPricing($item['years']);
                    $item['unit_price'] = $pricing ? (float) $pricing->price : 0;
                    $item['amount'] = $item['unit_price'];
                    $item['description'] = "{$item['domain']}{$item['extension']} for {$item['years']} year(s)";
                } else {
                    continue; // Skip if extension not found
                }
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        // Calculate tax
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        return view('customer.cart.index', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'itemCount' => count($cartItems),
        ]);
    }

    /**
     * Add item to cart (AJAX)
     */
    public function add(Request $request)
    {
        $type = $request->get('type'); // 'product' or 'domain'

        if ($type === 'product') {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            ]);

            $item = [
                'type' => 'product',
                'product_id' => $request->product_id,
                'billing_cycle' => $request->billing_cycle,
            ];
        } elseif ($type === 'domain') {
            $request->validate([
                'domain' => 'required|string',
                'extension' => 'required|string|exists:domain_extensions,extension',
                'years' => 'required|integer|min:1|max:10',
            ]);

            $item = [
                'type' => 'domain',
                'domain' => strtolower($request->domain),
                'extension' => $request->extension,
                'years' => $request->years,
            ];
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid item type',
            ], 400);
        }

        // Generate unique key
        $key = uniqid();
        $item['added_at'] = now()->toIso8601String();

        // Add to session cart
        $cart = session(self::CART_SESSION_KEY, []);
        $cart[$key] = $item;
        session([self::CART_SESSION_KEY => $cart]);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Item added to cart',
        ]);
    }

    /**
     * Remove item from cart
     */
    public function remove(string $key)
    {
        $cart = session(self::CART_SESSION_KEY, []);
        unset($cart[$key]);
        session([self::CART_SESSION_KEY => $cart]);

        return back()->with('success', 'Item removed from cart');
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        session([self::CART_SESSION_KEY => []]);
        return back()->with('success', 'Cart cleared');
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
}
