<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class CartController extends Controller
{
    const CART_KEY = 'reseller_cart';

    public function index()
    {
        $cart = session(self::CART_KEY, []);
        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if (in_array($item['type'] ?? 'domain', ['domain', 'domain_renewal'], true)) {
                $total = $this->cartItemTotal($item);
                $subtotal += $total;
                $items[$key] = array_merge($item, ['total' => $total]);
            }
        }

        $taxEnabled = Setting::getValue('tax_enabled') === 'true';
        $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        return view('reseller.cart.index', compact('items', 'subtotal', 'tax', 'taxEnabled', 'taxRate', 'total'));
    }

    public function add(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'extension' => 'required|string',
            'years' => 'required|integer|min:1|max:10',
            'price' => 'required|numeric|min:0',
        ]);

        $cart = session()->get(self::CART_KEY, []);
        $key = uniqid();

        $cart[$key] = [
            'type' => 'domain',
            'domain' => strtolower($validated['domain']),
            'extension' => $validated['extension'],
            'years' => (int) $validated['years'],
            'price' => (float) $validated['price'],
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain added to cart',
        ]);
    }

    public function remove(string $key)
    {
        $cart = session(self::CART_KEY, []);
        unset($cart[$key]);
        session()->put(self::CART_KEY, $cart);

        return redirect()->route('reseller.cart.index')
            ->with('success', 'Item removed from cart');
    }

    public function clear()
    {
        session()->forget(self::CART_KEY);

        return redirect()->route('reseller.cart.index')
            ->with('success', 'Cart cleared');
    }

    public static function cartItemTotal(array $item): float
    {
        if (($item['type'] ?? 'domain') === 'domain_renewal') {
            return (float) $item['price'];
        }

        return (float) $item['price'] * (int) $item['years'];
    }
}
