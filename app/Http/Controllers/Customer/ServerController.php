<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Currency;

class ServerController extends Controller
{
    /**
     * Show customer's servers and available server products
     */
    public function index()
    {
        // Load user's server services
        $services = auth()->user()->services()
            ->with('product')
            ->get()
            ->filter(function ($service) {
                return $service->product && Product::isServerType($service->product->type);
            })
            ->sortByDesc('created_at')
            ->values();

        // Load available VPS products
        $vpsProducts = Product::where('type', 'vps')
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        // Load available Dedicated Server products
        $dedicatedProducts = Product::where('type', 'dedicated_server')
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        // Get currency information
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)
            ->where('is_active', true)
            ->first();
        $currencySymbol = $currency?->symbol ?? $currencyCode;

        return view('customer.servers.index', compact(
            'services',
            'vpsProducts',
            'dedicatedProducts',
            'currencySymbol'
        ));
    }

    /**
     * Add server product to cart and redirect to checkout
     */
    public function order(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'billing_cycle' => 'required|in:monthly,annual',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        // Guard: Product must be active
        if (!$product->is_active) {
            return back()->withErrors(['error' => 'This product is no longer available.']);
        }

        // Guard: Product must be a server type
        if (!Product::isServerType($product->type)) {
            return back()->withErrors(['error' => 'Invalid product type.']);
        }

        // Guard: Annual billing requires yearly price
        if ($validated['billing_cycle'] === 'annual' && !$product->yearly_price) {
            return back()->withErrors(['error' => 'Annual billing is not available for this product.']);
        }

        // Add to session cart
        $cart = session('cart', []);
        $cartKey = uniqid();
        $cart[$cartKey] = [
            'type' => 'product',
            'product_id' => $product->id,
            'billing_cycle' => $validated['billing_cycle'],
            'added_at' => now()->toIso8601String(),
        ];
        session(['cart' => $cart]);

        return redirect()->route('customer.checkout.show')->with('success', 'Server added to cart!');
    }
}
