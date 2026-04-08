<?php

namespace App\Http\Controllers\Customer;

use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\DomainSearchController;
use Exception;
use ReflectionClass;

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
                    $item['name'] = "{$item['domain']}{$item['extension']}";
                    $item['description'] = "Domain registration for {$item['years']} year(s)";
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
     * Add item to cart (AJAX or form submission)
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
                'domain' => 'required|string|regex:/^[a-z0-9-]+$/i',
                'extension' => 'required|string|exists:domain_extensions,extension',
                'years' => 'required|integer|min:1|max:10',
            ]);

            // Verify domain extension exists and is enabled
            $extension = DomainExtension::where('extension', $request->extension)
                ->where('enabled', true)
                ->first();

            if (!$extension) {
                return response()->json([
                    'success' => false,
                    'message' => 'This domain extension is not available',
                ], 422);
            }

            // Verify pricing exists for this period
            $pricing = $extension->getRetailPricing($request->years);
            if (!$pricing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pricing not available for this registration period',
                ], 422);
            }

            $item = [
                'type' => 'domain',
                'domain' => strtolower($request->domain),
                'extension' => $request->extension,
                'years' => $request->years,
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'Invalid item type',
            ];

            if ($request->expectsJson()) {
                return response()->json($response, 400);
            }
            return back()->with('error', $response['message']);
        }

        // Generate unique key
        $key = uniqid();
        $item['added_at'] = now()->toIso8601String();

        // Add to session cart
        $cart = session(self::CART_SESSION_KEY, []);
        $cart[$key] = $item;
        session([self::CART_SESSION_KEY => $cart]);

        $response = [
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Item added to cart',
        ];

        // Return JSON for AJAX requests, redirect for form submissions
        if ($request->expectsJson()) {
            return response()->json($response);
        }

        return redirect()->route('customer.cart.index')->with('success', $response['message']);
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
     * Check domain availability (AJAX)
     */
    public function checkDomainAvailability(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|regex:/^[a-z0-9-]+$/i',
            'extension' => 'required|string|exists:domain_extensions,extension',
        ]);

        try {
            $domainSearch = new DomainSearchController();
            $fullDomain = $request->domain . $request->extension;

            // Use reflection to call private method for availability check
            $reflection = new \ReflectionClass($domainSearch);
            $method = $reflection->getMethod('checkAvailability');
            $method->setAccessible(true);

            $available = $method->invoke($domainSearch, $fullDomain);

            // Get pricing
            $extension = DomainExtension::where('extension', $request->extension)->firstOrFail();
            $pricing = $extension->getRetailPricing(1);
            $price = $pricing ? (float) $pricing->price : 0;

            return response()->json([
                'success' => true,
                'available' => $available,
                'full_domain' => $fullDomain,
                'price' => $price,
                'message' => $available ? 'Domain is available!' : 'Domain is already taken',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking domain availability: ' . $e->getMessage(),
                'available' => false,
            ], 500);
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
}
