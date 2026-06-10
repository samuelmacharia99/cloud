<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $type = $request->input('type');
        $validated = $request->validate($this->validationRules($type));

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated = $this->normalizeValidatedProductData($validated, $type);

        Product::create($validated);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product)
    {
        $product->load('services.user');

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
        $type = $product->type;
        $validated = $request->validate($this->validationRules($type, $product));

        $validated = $this->normalizeValidatedProductData($validated, $type);

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

    public function duplicate(Product $product)
    {
        abort_if($product->type !== 'container_hosting', 404);

        $copy = $product->replicate();
        $copy->name = $this->uniqueCopyName($product->name);
        $copy->slug = $this->uniqueCopySlug($product->slug);
        $copy->is_active = false;
        $copy->featured = false;
        $copy->save();

        return redirect()
            ->route('admin.products.edit', $copy)
            ->with('success', 'Product duplicated. Review settings and activate when ready.');
    }

    private function validationRules(string $type, ?Product $product = null): array
    {
        $productId = $product?->id;

        $rules = [
            'name' => 'required|string|max:255|unique:products,name'.($productId ? ','.$productId : ''),
            'slug' => ($productId ? 'required' : 'nullable').'|string|max:255|unique:products,slug'.($productId ? ','.$productId : ''),
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'type' => 'required|in:'.implode(',', array_keys(Product::TYPES)),
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
            'disk_overage_rate' => 'nullable|numeric|min:0',
        ];

        if ($type === 'shared_hosting') {
            $rules['direct_admin_package_id'] = 'required|exists:direct_admin_packages,id';
            $rules['resource_limits'] = 'nullable';
            $rules['wholesale_monthly_price'] = 'nullable';
            $rules['wholesale_yearly_price'] = 'nullable';

            return $rules;
        }

        $rules['wholesale_monthly_price'] = 'nullable|numeric|min:0';
        $rules['wholesale_yearly_price'] = 'nullable|numeric|min:0';
        $rules['direct_admin_package_id'] = 'nullable';

        if ($type === 'container_hosting') {
            $rules['resource_limits'] = 'nullable|array';
            $rules['resource_limits.cpu'] = 'nullable|numeric|min:0';
            $rules['resource_limits.memory'] = 'nullable|integer|min:0';
            $rules['resource_limits.disk'] = 'nullable|numeric|min:0';

            return $rules;
        }

        $resourceLimits = request()->input('resource_limits');
        $rules['resource_limits'] = ($resourceLimits !== null && ! is_array($resourceLimits))
            ? 'nullable|json'
            : 'nullable';

        return $rules;
    }

    private function normalizeValidatedProductData(array $validated, string $type): array
    {
        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        if ($type === 'shared_hosting') {
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['resource_limits'] = null;

            return $validated;
        }

        if ($type === 'container_hosting') {
            $validated['setup_fee'] = 0;
            $validated['provisioning_driver_key'] = null;
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['direct_admin_package_id'] = null;
            $validated['resource_limits'] = $this->normalizeContainerResourceLimits($validated['resource_limits'] ?? null);

            return $validated;
        }

        $validated['direct_admin_package_id'] = null;

        return $validated;
    }

    private function normalizeContainerResourceLimits(?array $limits): ?array
    {
        if (! is_array($limits)) {
            return null;
        }

        $normalized = [];

        if (array_key_exists('cpu', $limits) && $limits['cpu'] !== '' && $limits['cpu'] !== null) {
            $normalized['cpu'] = (float) $limits['cpu'];
        }

        if (array_key_exists('memory', $limits) && $limits['memory'] !== '' && $limits['memory'] !== null) {
            $normalized['memory'] = (int) $limits['memory'];
        }

        if (array_key_exists('disk', $limits) && $limits['disk'] !== '' && $limits['disk'] !== null) {
            $normalized['disk'] = (float) $limits['disk'];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function uniqueCopyName(string $name): string
    {
        $base = preg_replace('/ \(Copy(?: \d+)?\)$/', '', $name) ?: $name;
        $candidate = $base.' (Copy)';
        $suffix = 2;

        while (Product::where('name', $candidate)->exists()) {
            $candidate = $base.' (Copy '.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCopySlug(string $slug): string
    {
        $base = preg_replace('/-copy(?:-\d+)?$/', '', $slug) ?: $slug;
        $candidate = $base.'-copy';
        $suffix = 2;

        while (Product::where('slug', $candidate)->exists()) {
            $candidate = $base.'-copy-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
