<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\Currency;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        // Type filter
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $products = $query->withCount('services')->paginate(15)->withQueryString();

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('admin.products.index', compact('products', 'currency', 'currencyCode'));
    }

    public function create()
    {
        return view('admin.products.create');
    }

    public function store(Request $request)
    {
        // Custom validation for resource_limits (can be JSON string or array for server configs)
        $resourceLimits = $request->input('resource_limits');
        $resourceLimitsRule = 'nullable';

        if ($resourceLimits !== null && !is_array($resourceLimits)) {
            // If it's a string, validate as JSON
            $resourceLimitsRule = 'nullable|json';
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:products,name',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'type' => 'required|in:' . implode(',', array_keys(Product::TYPES)),
            'container_template_id' => 'nullable|required_if:type,container_hosting|exists:container_templates,id',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'provisioning_driver_key' => 'nullable|string',
            'resource_limits' => $resourceLimitsRule,
            'is_active' => 'boolean',
            'visible_to_resellers' => 'boolean',
            'featured' => 'boolean',
            'overage_enabled' => 'boolean',
            'cpu_overage_rate' => 'nullable|numeric|min:0',
            'ram_overage_rate' => 'nullable|numeric|min:0',
        ]);

        // Auto-generate slug from name if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Parse JSON resource limits if string, or keep as array if already an array
        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        Product::create($validated);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product)
    {
        $product->load('services');

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('admin.products.show', compact('product', 'currency', 'currencyCode'));
    }

    public function edit(Product $product)
    {
        return view('admin.products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        // Custom validation for resource_limits (can be JSON string or array for server configs)
        $resourceLimits = $request->input('resource_limits');
        $resourceLimitsRule = 'nullable';

        if ($resourceLimits !== null && !is_array($resourceLimits)) {
            // If it's a string, validate as JSON
            $resourceLimitsRule = 'nullable|json';
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:products,name,' . $product->id,
            'slug' => 'required|string|max:255|unique:products,slug,' . $product->id,
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'type' => 'required|in:' . implode(',', array_keys(Product::TYPES)),
            'container_template_id' => 'nullable|required_if:type,container_hosting|exists:container_templates,id',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'provisioning_driver_key' => 'nullable|string',
            'resource_limits' => $resourceLimitsRule,
            'is_active' => 'boolean',
            'visible_to_resellers' => 'boolean',
            'featured' => 'boolean',
            'overage_enabled' => 'boolean',
            'cpu_overage_rate' => 'nullable|numeric|min:0',
            'ram_overage_rate' => 'nullable|numeric|min:0',
        ]);

        // Parse JSON resource limits if string, or keep as array if already an array
        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        $product->update($validated);

        return redirect()->route('admin.products.show', $product)
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', 'Product deleted successfully.');
    }
}
