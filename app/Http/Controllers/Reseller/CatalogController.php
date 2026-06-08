<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\ResellerDirectAdminService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
    ) {}

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
        $reseller = auth()->user();
        $directAdminBinding = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);
        $directAdminPackageResult = $this->resellerDirectAdmin->listAssignablePackages($reseller);

        return view('reseller.catalog.create', [
            'adminProducts' => $adminProducts,
            'productTypes' => $productTypes,
            'directAdminBinding' => $directAdminBinding,
            'directAdminPackages' => $directAdminPackageResult['packages'],
            'directAdminPackagesError' => $directAdminPackageResult['error'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCatalogItem($request);

        $validated['reseller_id'] = auth()->id();

        ResellerProduct::create($validated);

        return redirect()->route('reseller.catalog.index')
            ->with('success', 'Product added to your catalog successfully.');
    }

    public function show(ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $catalogItem->load('adminProduct');

        return view('reseller.catalog.show', compact('catalogItem'));
    }

    public function edit(ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $adminProducts = Product::where('visible_to_resellers', true)
            ->where('is_active', true)
            ->get();

        $productTypes = Product::TYPES;
        $reseller = auth()->user();
        $directAdminBinding = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);
        $directAdminPackageResult = $this->resellerDirectAdmin->listAssignablePackages($reseller);

        $catalogItem->load('adminProduct');

        return view('reseller.catalog.edit', [
            'catalogItem' => $catalogItem,
            'adminProducts' => $adminProducts,
            'productTypes' => $productTypes,
            'directAdminBinding' => $directAdminBinding,
            'directAdminPackages' => $directAdminPackageResult['packages'],
            'directAdminPackagesError' => $directAdminPackageResult['error'],
        ]);
    }

    public function update(Request $request, ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $validated = $this->validateCatalogItem($request);

        $catalogItem->update($validated);

        return redirect()->route('reseller.catalog.show', $catalogItem)
            ->with('success', 'Catalog item updated successfully.');
    }

    public function destroy(ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $catalogItem->delete();

        return redirect()->route('reseller.catalog.index')
            ->with('success', 'Catalog item deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCatalogItem(Request $request): array
    {
        $reseller = auth()->user();
        $packageResult = $this->resellerDirectAdmin->listAssignablePackages($reseller);
        $requiresDaPackage = $request->input('type') === 'shared_hosting'
            && $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)
            && $packageResult['packages'] !== [];

        $validated = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:'.implode(',', array_keys(Product::TYPES)),
            'direct_admin_package_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf($requiresDaPackage),
            ],
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        if (filled($validated['product_id'] ?? null)) {
            $product = Product::findOrFail($validated['product_id']);
            if (! $product->visible_to_resellers) {
                throw ValidationException::withMessages([
                    'product_id' => 'This product is not available for resellers.',
                ]);
            }
        }

        if (($validated['type'] ?? '') !== 'shared_hosting') {
            $validated['direct_admin_package_name'] = null;
        } elseif (filled($validated['direct_admin_package_name'] ?? null)) {
            $allowed = collect($packageResult['packages'])->pluck('name')->all();
            if (! in_array($validated['direct_admin_package_name'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'direct_admin_package_name' => 'Select a valid DirectAdmin package from your reseller account.',
                ]);
            }
        }

        return $validated;
    }
}
