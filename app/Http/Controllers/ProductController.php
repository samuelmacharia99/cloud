<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin')->only(['create', 'store', 'edit', 'update', 'destroy']);
    }

    public function index()
    {
        $products = Product::where('is_active', true)
            ->orderBy('order')
            ->paginate(12);

        return view('products.index', compact('products'));
    }

    public function show(Product $product)
    {
        return view('products.show', compact('product'));
    }

    public function create()
    {
        return view('products.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:products',
            'description' => 'nullable|string',
            'category' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'setup_fee' => 'nullable|numeric|min:0',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        Product::create($validated);

        return redirect()->route('products.index')
            ->with('success', 'Product created successfully.');
    }

    public function edit(Product $product)
    {
        return view('products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => "required|string|unique:products,slug,{$product->id}",
            'description' => 'nullable|string',
            'category' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'setup_fee' => 'nullable|numeric|min:0',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return redirect()->route('products.show', $product)
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Product deleted successfully.');
    }
}
