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
        $resourceLimits = $request->input('resource_limits');
        $resourceLimitsRule = 'nullable';

        if ($resourceLimits !== null && !is_array($resourceLimits)) {
            $resourceLimitsRule = 'nullable|json';
        }

        $rules = [
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
            'is_active' => 'boolean',
            'visible_to_resellers' => 'boolean',
            'featured' => 'boolean',
            'overage_enabled' => 'boolean',
            'cpu_overage_rate' => 'nullable|numeric|min:0',
            'ram_overage_rate' => 'nullable|numeric|min:0',
        ];

        if ($request->input('type') === 'shared_hosting') {
            $rules['direct_admin_package_id'] = 'required|exists:direct_admin_packages,id';
            $rules['resource_limits'] = 'nullable';
            $rules['wholesale_monthly_price'] = 'nullable';
            $rules['wholesale_yearly_price'] = 'nullable';
        } else {
            $rules['wholesale_monthly_price'] = 'nullable|numeric|min:0';
            $rules['wholesale_yearly_price'] = 'nullable|numeric|min:0';
            $rules['resource_limits'] = $resourceLimitsRule;
            $rules['direct_admin_package_id'] = 'nullable';
        }

        $validated = $request->validate($rules);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        if ($validated['type'] === 'shared_hosting') {
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['resource_limits'] = null;
        } else {
            $validated['direct_admin_package_id'] = null;
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
        $resourceLimits = $request->input('resource_limits');
        $resourceLimitsRule = 'nullable';

        if ($resourceLimits !== null && !is_array($resourceLimits)) {
            $resourceLimitsRule = 'nullable|json';
        }

        $rules = [
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
            'is_active' => 'boolean',
            'visible_to_resellers' => 'boolean',
            'featured' => 'boolean',
            'overage_enabled' => 'boolean',
            'cpu_overage_rate' => 'nullable|numeric|min:0',
            'ram_overage_rate' => 'nullable|numeric|min:0',
        ];

        if ($product->type === 'shared_hosting') {
            $rules['direct_admin_package_id'] = 'required|exists:direct_admin_packages,id';
            $rules['resource_limits'] = 'nullable';
            $rules['wholesale_monthly_price'] = 'nullable';
            $rules['wholesale_yearly_price'] = 'nullable';
        } else {
            $rules['wholesale_monthly_price'] = 'nullable|numeric|min:0';
            $rules['wholesale_yearly_price'] = 'nullable|numeric|min:0';
            $rules['resource_limits'] = $resourceLimitsRule;
            $rules['direct_admin_package_id'] = 'nullable';
        }

        $validated = $request->validate($rules);

        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        if ($product->type === 'shared_hosting') {
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['resource_limits'] = null;
        } else {
            $validated['direct_admin_package_id'] = null;
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
