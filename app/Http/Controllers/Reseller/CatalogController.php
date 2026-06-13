<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerDiskUsageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    /** @var list<string> */
    private const ADMIN_CATALOG_TYPES = ['vps', 'dedicated_server', 'container_hosting'];

    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
    ) {}

    public function index()
    {
        $catalogItems = ResellerProduct::where('reseller_id', auth()->id())
            ->with('adminProduct.containerTemplate')
            ->paginate(15);

        return view('reseller.catalog.index', compact('catalogItems'));
    }

    public function create()
    {
        return view('reseller.catalog.create', $this->catalogFormViewData());
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

        $catalogItem->load('adminProduct.containerTemplate');

        return view('reseller.catalog.show', compact('catalogItem'));
    }

    public function edit(ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $catalogItem->load('adminProduct.containerTemplate');

        return view('reseller.catalog.edit', array_merge(
            $this->catalogFormViewData(),
            ['catalogItem' => $catalogItem],
        ));
    }

    public function update(Request $request, ResellerProduct $catalogItem)
    {
        if ($catalogItem->reseller_id !== auth()->id()) {
            abort(404);
        }

        $validated = $this->validateCatalogItem($request, $catalogItem);

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
    private function catalogFormViewData(): array
    {
        $adminProducts = Product::query()
            ->where('visible_to_resellers', true)
            ->where('is_active', true)
            ->whereIn('type', self::ADMIN_CATALOG_TYPES)
            ->with('containerTemplate')
            ->orderBy('type')
            ->orderBy('order')
            ->get();

        $reseller = auth()->user();
        $directAdminPackageResult = $this->resellerDirectAdmin->listAssignablePackages($reseller);
        $diskUsage = app(ResellerDiskUsageService::class);
        $diskPoolUsage = [
            'pool_gb' => $diskUsage->diskPoolGb($reseller),
            'used_gb' => $diskUsage->collectCurrentUsage($reseller)['total_used_gb'],
            'percent' => $diskUsage->poolUsagePercent($reseller),
        ];

        $customProductTypes = collect(Product::TYPES)
            ->except(self::ADMIN_CATALOG_TYPES)
            ->all();

        return [
            'adminProducts' => $adminProducts,
            'productTypes' => Product::TYPES,
            'customProductTypes' => $customProductTypes,
            'directAdminBinding' => $this->resellerDirectAdmin->hasDirectAdminBinding($reseller),
            'directAdminPackages' => $directAdminPackageResult['packages'],
            'directAdminPackagesError' => $directAdminPackageResult['error'],
            'diskPoolUsage' => $diskPoolUsage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCatalogItem(Request $request, ?ResellerProduct $existing = null): array
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
            'is_active' => 'nullable|boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        if (($validated['type'] ?? '') === 'container_hosting') {
            if ($existing && ! filled($validated['product_id'] ?? null)) {
                $validated['product_id'] = $existing->product_id;
                $validated['container_template_id'] = $existing->container_template_id;
            }

            if (! filled($validated['product_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'product_id' => 'Select a container package from the admin catalog.',
                ]);
            }
        }

        if (filled($validated['product_id'] ?? null)) {
            $product = Product::with('containerTemplate')->findOrFail($validated['product_id']);
            if (! $product->visible_to_resellers || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_id' => 'This product is not available for resellers.',
                ]);
            }

            if (! in_array($product->type, self::ADMIN_CATALOG_TYPES, true)) {
                throw ValidationException::withMessages([
                    'product_id' => 'This platform product cannot be added through the reseller catalog.',
                ]);
            }

            if ($product->type !== $validated['type']) {
                throw ValidationException::withMessages([
                    'product_id' => 'Selected platform product does not match the catalog item type.',
                ]);
            }

            if ($product->type === 'container_hosting') {
                if (! $product->container_template_id) {
                    throw ValidationException::withMessages([
                        'product_id' => 'This container package is missing a tech stack. Choose another or contact support.',
                    ]);
                }

                $validated['container_template_id'] = $product->container_template_id;
                $validated['resource_limits'] = null;
                $validated['database_template_id'] = null;
            }
        } else {
            $validated['container_template_id'] = null;
            $validated['database_template_id'] = null;
            $validated['resource_limits'] = null;
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

        return $this->normalizePricingFields($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePricingFields(array $validated): array
    {
        foreach (['monthly_price', 'yearly_price'] as $field) {
            if (! array_key_exists($field, $validated) || $validated[$field] === '' || $validated[$field] === null) {
                $validated[$field] = null;

                continue;
            }

            $validated[$field] = (float) $validated[$field];
        }

        $validated['setup_fee'] = filled($validated['setup_fee'] ?? null)
            ? (float) $validated['setup_fee']
            : 0.0;

        return $validated;
    }
}
