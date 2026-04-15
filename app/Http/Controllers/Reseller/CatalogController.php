<?php

namespace App\Http\Controllers\Reseller;

use App\Models\Product;
use App\Models\ResellerProduct;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CatalogController extends Controller
{
    public function index()
    {
        $catalogItems = ResellerProduct::where('reseller_id', auth()->id())
            ->with('adminProduct')
            ->paginate(15);

        return view('reseller.catalog.index', compact('catalogItems'));
    }

    public function create()
    {
        $adminProducts = Product::where('visible_to_resellers', true)
            ->where('is_active', true)
            ->get();

        $productTypes = Product::TYPES;

        return view('reseller.catalog.create', compact('adminProducts', 'productTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:' . implode(',', array_keys(Product::TYPES)),
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        // If product_id provided, verify it is visible_to_resellers
        if ($validated['product_id']) {
            $product = Product::findOrFail($validated['product_id']);
            if (!$product->visible_to_resellers) {
                return back()->withErrors(['product_id' => 'This product is not available for resellers.']);
            }
        }

        $validated['reseller_id'] = auth()->id();

        ResellerProduct::create($validated);

        return redirect()->route('reseller.catalog.index')
            ->with('success', 'Product added to your catalog successfully.');
    }

    public function show(ResellerProduct $catalogItem)
    {
        // Check ownership
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $catalogItem->load('adminProduct');

        return view('reseller.catalog.show', compact('catalogItem'));
    }

    public function edit(ResellerProduct $catalogItem)
    {
        // Check ownership
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $adminProducts = Product::where('visible_to_resellers', true)
            ->where('is_active', true)
            ->get();

        $productTypes = Product::TYPES;

        $catalogItem->load('adminProduct');

        return view('reseller.catalog.edit', compact('catalogItem', 'adminProducts', 'productTypes'));
    }

    public function update(Request $request, ResellerProduct $catalogItem)
    {
        // Check ownership
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $validated = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:' . implode(',', array_keys(Product::TYPES)),
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        // If product_id provided, verify it is visible_to_resellers
        if ($validated['product_id']) {
            $product = Product::findOrFail($validated['product_id']);
            if (!$product->visible_to_resellers) {
                return back()->withErrors(['product_id' => 'This product is not available for resellers.']);
            }
        }

        $catalogItem->update($validated);

        return redirect()->route('reseller.catalog.show', $catalogItem)
            ->with('success', 'Catalog item updated successfully.');
    }

    public function destroy(ResellerProduct $catalogItem)
    {
        // Check ownership
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $catalogItem->delete();

        return redirect()->route('reseller.catalog.index')
            ->with('success', 'Catalog item deleted successfully.');
    }
}
