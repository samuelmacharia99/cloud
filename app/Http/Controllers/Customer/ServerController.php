<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ResellerCustomerCatalogService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * Show customer's servers and available server products
     */
    public function index(Request $request)
    {
        $selectedType = $request->query('type'); // vps or dedicated_server

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

        // Get currency information
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)
            ->where('is_active', true)
            ->first();
        $currencySymbol = $currency?->symbol ?? $currencyCode;

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
            'ip_count' => 'required|integer|min:1|max:'.config('server_options.max_ip_count', 8),
        ]);

        $user = auth()->user();
        $catalogService = app(ResellerCustomerCatalogService::class);
        $product = Product::findOrFail($validated['product_id']);

        if (! $product->is_active) {
            return back()->withErrors(['error' => 'This product is no longer available.']);
        }

        if (! Product::isServerType($product->type)) {
            return back()->withErrors(['error' => 'Invalid product type.']);
        }

        $listing = $catalogService->findListingForProduct($user, $product->id);
        if ($catalogService->isResellerCustomer($user) && ! $listing) {
            return redirect()->route('customer.reseller-catalog.index')
                ->with('error', 'This server is not offered by your reseller.');
        }

        if ($validated['billing_cycle'] === 'annual') {
            $yearlyAvailable = $catalogService->isResellerCustomer($user)
                ? (float) ($listing?->yearly_price ?? 0) > 0
                : (bool) $product->yearly_price;

            if (! $yearlyAvailable) {
                return back()->withErrors(['error' => 'Annual billing is not available for this product.']);
            }
        }

        $cart = session('cart', []);
        $cartKey = uniqid();

        if ($listing) {
            $cart[$cartKey] = [
                'type' => 'reseller_product',
                'reseller_product_id' => $listing->id,
                'product_id' => $listing->product_id,
                'reseller_id' => $listing->reseller_id,
                'billing_cycle' => $validated['billing_cycle'],
                'operating_system' => $validated['operating_system'],
                'ip_count' => (int) $validated['ip_count'],
                'added_at' => now()->toIso8601String(),
            ];
        } else {
            $cart[$cartKey] = [
                'type' => 'product',
                'product_id' => $product->id,
                'billing_cycle' => $validated['billing_cycle'],
                'operating_system' => $validated['operating_system'],
                'ip_count' => (int) $validated['ip_count'],
                'added_at' => now()->toIso8601String(),
            ];
        }

        session(['cart' => $cart]);

        return redirect()->route('customer.checkout.show')->with('success', 'Server added to cart!');
    }
}
