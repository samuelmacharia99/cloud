<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ResellerProduct;
use Illuminate\Http\Request;

class ResellerCatalogController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if (! $user->reseller_id) {
            return redirect()->route('customer.browse-services')
                ->with('info', 'Browse our standard hosting catalog.');
        }

        $products = ResellerProduct::query()
            ->where('reseller_id', $user->reseller_id)
            ->where('is_active', true)
            ->with('adminProduct')
            ->orderBy('name')
            ->get();

        return view('customer.reseller-catalog.index', compact('products'));
    }

    public function addToCart(Request $request, ResellerProduct $resellerProduct)
    {
        $user = auth()->user();

        if ($user->reseller_id !== $resellerProduct->reseller_id || ! $resellerProduct->is_active) {
            abort(404);
        }

        if (! $resellerProduct->isOrderable()) {
            return back()->with('error', 'This catalog item is not available for ordering.');
        }

        $validated = $request->validate([
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
        ]);

        $provisionProduct = $resellerProduct->provisionProduct();

        $cart = session(CartController::CART_SESSION_KEY, []);
        $key = uniqid('rp_');
        $cart[$key] = [
            'type' => 'reseller_product',
            'reseller_product_id' => $resellerProduct->id,
            'product_id' => $provisionProduct?->id,
            'reseller_id' => $resellerProduct->reseller_id,
            'billing_cycle' => $validated['billing_cycle'],
            'added_at' => now()->toIso8601String(),
        ];
        session([CartController::CART_SESSION_KEY => $cart]);

        return redirect()->route('customer.cart.index')
            ->with('success', 'Item added to cart.');
    }
}
