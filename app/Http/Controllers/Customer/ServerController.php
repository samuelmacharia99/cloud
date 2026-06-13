<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ServerProductConfigService;
use App\Services\UserCurrencyService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * Show customer's servers and available server products
     */
    public function index(Request $request)
    {
        $selectedType = $request->query('type'); // vps or dedicated_server
        if ($selectedType && ! in_array($selectedType, ['vps', 'dedicated_server'], true)) {
            $selectedType = null;
        }

        // Load user's server services
        $services = auth()->user()->services()
            ->with('product')
            ->get()
            ->filter(function ($service) use ($selectedType) {
                if (! $service->product || ! Product::isServerType($service->product->type)) {
                    return false;
                }
                if ($selectedType && $service->product->type !== $selectedType) {
                    return false;
                }

                return true;
            })
            ->sortByDesc('created_at')
            ->values();

        $catalogService = app(ResellerCustomerCatalogService::class);
        $resellerListings = $catalogService->activeCatalogKeyedByProductId(auth()->user());

        $productQuery = Product::query()->where('is_active', true);
        $catalogService->scopePlatformProducts($productQuery, auth()->user());

        $vpsProducts = (clone $productQuery)->where('type', 'vps')
            ->orderBy('monthly_price')
            ->get();

        $dedicatedProducts = (clone $productQuery)->where('type', 'dedicated_server')
            ->orderBy('monthly_price')
            ->get();

        // Filter products by type if selected
        if ($selectedType === 'vps') {
            $dedicatedProducts = collect();
        } elseif ($selectedType === 'dedicated_server') {
            $vpsProducts = collect();
        }

        $currency = app(UserCurrencyService::class)->model(auth()->user());
        $currencyCode = $currency->code;
        $currencySymbol = $currency->symbol ?? $currencyCode;

        $linuxDistros = config('server_options.linux_distributions');
        $maxIpCount = config('server_options.max_ip_count', 8);

        return view('customer.servers.index', compact(
            'services',
            'vpsProducts',
            'dedicatedProducts',
            'resellerListings',
            'currencySymbol',
            'selectedType',
            'linuxDistros',
            'maxIpCount'
        ));
    }

    /**
     * Add server product to cart and redirect to checkout
     */
    public function order(Request $request)
    {
        $validOs = implode(',', array_keys(config('server_options.linux_distributions')));

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'billing_cycle' => 'required|in:monthly,annual',
            'operating_system' => 'required|string|in:'.$validOs,
            'location_key' => 'nullable|string|max:100',
            'ip_count' => 'required|integer|min:1|max:'.config('server_options.max_ip_count', 8),
        ]);

        $user = auth()->user();
        $catalogService = app(ResellerCustomerCatalogService::class);
        $product = Product::findOrFail($validated['product_id']);
        $configService = app(ServerProductConfigService::class);

        if (! $product->is_active) {
            return back()->withErrors(['error' => 'This product is no longer available.']);
        }

        if (! Product::isServerType($product->type)) {
            return back()->withErrors(['error' => 'Invalid product type.']);
        }

        $listing = $catalogService->findListingForProduct($user, $product->id);
        if ($catalogService->isResellerCustomer($user) && ! $listing) {
            return redirect()->route('customer.catalog.index')
                ->with('error', 'This server plan is not available for ordering.');
        }

        $locations = $configService->locations($product);
        $locationKey = $validated['location_key'] ?? ($locations[0]['key'] ?? null);
        if ($locationKey === null || ! $configService->location($product, $locationKey)) {
            return back()->withErrors(['error' => 'Please select a valid datacenter location.']);
        }

        try {
            $configService->resolveOrderPricing(
                $product,
                $listing,
                $locationKey,
                (int) $validated['ip_count'],
                $validated['billing_cycle'],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        if ($validated['billing_cycle'] === 'annual') {
            $location = $configService->location($product, $locationKey);
            $resolved = $configService->resolvedLocationPrices($product, $location, $listing, false);
            $yearlyAvailable = (float) $resolved['yearly'] > 0;

            if (! $yearlyAvailable) {
                return back()->withErrors(['error' => 'Annual billing is not available for this product.']);
            }
        }

        $location = $configService->location($product, $locationKey);
        $cart = session('cart', []);
        $cartKey = uniqid();

        $serverMeta = [
            'billing_cycle' => $validated['billing_cycle'],
            'operating_system' => $validated['operating_system'],
            'location_key' => $locationKey,
            'location_name' => $location['name'] ?? null,
            'location_city' => $location['city'] ?? null,
            'ip_count' => (int) $validated['ip_count'],
            'added_at' => now()->toIso8601String(),
        ];

        if ($listing) {
            $cart[$cartKey] = array_merge([
                'type' => 'reseller_product',
                'reseller_product_id' => $listing->id,
                'product_id' => $listing->product_id,
                'reseller_id' => $listing->reseller_id,
            ], $serverMeta);
        } else {
            $cart[$cartKey] = array_merge([
                'type' => 'product',
                'product_id' => $product->id,
            ], $serverMeta);
        }

        session(['cart' => $cart]);

        return redirect()->route('customer.checkout.show')->with('success', 'Server added to cart!');
    }
}
