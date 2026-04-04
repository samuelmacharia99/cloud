<?php

namespace App\Http\Controllers\Customer;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ServiceBrowserController extends Controller
{
    /**
     * Browse available services to deploy
     */
    public function index(Request $request)
    {
        // Get selected filter type from query params
        $selectedType = $request->get('type', null);

        // Get all active products
        $query = Product::where('is_active', true);

        if ($selectedType && $selectedType !== 'all') {
            $query->where('type', $selectedType);
        }

        $products = $query->orderBy('category')->orderBy('order')->get();

        // Group products by type
        $groupedProducts = $products->groupBy('type');

        // Get all available types for filtering
        $allTypes = Product::where('is_active', true)
            ->distinct()
            ->pluck('type')
            ->mapWithKeys(function ($type) {
                return [$type => Product::typeLabel($type)];
            })
            ->toArray();

        // Get cart item count from session
        $cartCount = count(session('cart', []));

        return view('customer.deploy-service', [
            'products' => $products,
            'groupedProducts' => $groupedProducts,
            'allTypes' => $allTypes,
            'selectedType' => $selectedType,
            'cartCount' => $cartCount,
        ]);
    }
}
