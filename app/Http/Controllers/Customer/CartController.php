<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Setting;
use App\Services\DomainInputParser;
use App\Services\NodeNameserverService;
use App\Services\ResellerCustomerCatalogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionClass;

class CartController extends Controller
{
    const CART_SESSION_KEY = 'cart';

    /**
     * Display the shopping cart
     */
    public function index()
    {
        $user = auth()->user();
        app(ResellerCustomerCatalogService::class)->sanitizeSessionCart($user);

        $cart = session(self::CART_SESSION_KEY, []);
        $catalogService = app(ResellerCustomerCatalogService::class);
        $cartItems = [];
        $subtotal = 0;
        $hasSharedHosting = false;

        foreach ($cart as $key => $item) {
            $item['key'] = $key;

            if ($item['type'] === 'product') {
                if ($catalogService->isResellerCustomer($user)) {
                    continue;
                }

                $product = Product::find($item['product_id']);
                if ($product) {
                    $item['name'] = $product->name;
                    $item['description'] = $product->description ?? $product->name;
                    $item['unit_price'] = $this->getProductPrice($product, $item['billing_cycle']);
                    $item['amount'] = $item['unit_price'];
                    $hasSharedHosting = $hasSharedHosting || $product->type === 'shared_hosting';
                } else {
                    continue; // Skip if product not found
                }
            } elseif ($item['type'] === 'reseller_product') {
                $resellerProduct = ResellerProduct::with('adminProduct')->find($item['reseller_product_id'] ?? null);
                if ($resellerProduct && $resellerProduct->product_id
                    && (! $catalogService->isResellerCustomer($user) || $resellerProduct->reseller_id === $user->reseller_id)) {
                    $item['name'] = $resellerProduct->name;
                    $item['description'] = $resellerProduct->description ?? $resellerProduct->name;
                    $item['unit_price'] = $resellerProduct->priceForBillingCycle($item['billing_cycle']);
                    $item['amount'] = $item['unit_price'] + (float) ($resellerProduct->setup_fee ?? 0);
                    $item['product_id'] = $resellerProduct->product_id;
                    $hasSharedHosting = $hasSharedHosting || ($resellerProduct->adminProduct?->type === 'shared_hosting');
                } else {
                    continue;
                }
            } elseif ($item['type'] === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'])->first();
                if ($extension) {
                    $price = $catalogService->domainRegistrationPrice($user, $extension, (int) $item['years']);
                    if ($price === null) {
                        continue;
                    }

                    $item['unit_price'] = $price;
                    $item['amount'] = $item['unit_price'];
                    $item['name'] = "{$item['domain']}{$item['extension']}";
                    $item['description'] = "Domain registration for {$item['years']} year(s)";
                } else {
                    continue; // Skip if extension not found
                }
            }

            $subtotal += $item['amount'];
            $cartItems[] = $item;
        }

        // Calculate tax
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        $defaultNameservers = app(NodeNameserverService::class)->platformDefaults();

        return view('customer.cart.index', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'itemCount' => count($cartItems),
            'defaultNameservers' => $defaultNameservers,
            'hasSharedHosting' => $hasSharedHosting,
        ]);
    }

    /**
     * Add item to cart (AJAX or form submission)
     */
    public function add(Request $request)
    {
        $user = auth()->user();
        $catalogService = app(ResellerCustomerCatalogService::class);
        $type = $request->get('type'); // 'product' or 'domain'

        if ($type === 'product') {
            if ($catalogService->isResellerCustomer($user)) {
                $response = [
                    'success' => false,
                    'message' => 'Order hosting from your reseller catalog instead of platform pricing.',
                ];

                if ($request->expectsJson()) {
                    return response()->json($response, 403);
                }

                return redirect()->route('customer.reseller-catalog.index')->with('error', $response['message']);
            }

            $request->validate([
                'product_id' => 'required|exists:products,id',
                'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            ]);

            $item = [
                'type' => 'product',
                'product_id' => $request->product_id,
                'billing_cycle' => $request->billing_cycle,
            ];
        } elseif ($type === 'domain') {
            $request->validate([
                'domain' => 'required|string|regex:/^[a-z0-9-]+$/i',
                'extension' => 'required|string|exists:domain_extensions,extension',
                'years' => 'required|integer|min:1|max:10',
            ]);

            // Verify domain extension exists and is enabled
            $extension = DomainExtension::where('extension', $request->extension)
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return response()->json([
                    'success' => false,
                    'message' => 'This domain extension is not available',
                ], 422);
            }

            $price = $catalogService->domainRegistrationPrice($user, $extension, (int) $request->years);
            if ($price === null) {
                $message = $catalogService->isResellerCustomer($user)
                    ? 'Your reseller has not set pricing for this domain extension.'
                    : 'Pricing not available for this registration period';

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 422);
                }

                return back()->with('error', $message);
            }

            $defaultNameservers = app(NodeNameserverService::class)->platformDefaults();

            $item = [
                'type' => 'domain',
                'domain' => strtolower($request->domain),
                'extension' => $request->extension,
                'years' => $request->years,
                'nameservers' => [
                    ...$defaultNameservers,
                    'use_default' => true,
                ],
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'Invalid item type',
            ];

            if ($request->expectsJson()) {
                return response()->json($response, 400);
            }

            return back()->with('error', $response['message']);
        }

        // Generate unique key
        $key = uniqid();
        $item['added_at'] = now()->toIso8601String();

        // Add to session cart
        $cart = session(self::CART_SESSION_KEY, []);
        $cart[$key] = $item;
        session([self::CART_SESSION_KEY => $cart]);

        $response = [
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Item added to cart',
        ];

        // Return JSON for AJAX requests, redirect for form submissions
        if ($request->expectsJson()) {
            return response()->json($response);
        }

        return redirect()->route('customer.cart.index')->with('success', $response['message']);
    }

    /**
     * Remove item from cart
     */
    public function remove(string $key)
    {
        $cart = session(self::CART_SESSION_KEY, []);
        unset($cart[$key]);
        session([self::CART_SESSION_KEY => $cart]);

        return back()->with('success', 'Item removed from cart');
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        session([self::CART_SESSION_KEY => []]);

        return back()->with('success', 'Cart cleared');
    }

    /**
     * Check domain availability (AJAX)
     */
    public function checkDomainAvailability(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:253',
            'extension' => 'nullable|string',
            'years' => 'nullable|integer|min:1|max:10',
        ]);

        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $parsed = app(DomainInputParser::class)->parse(
            (string) $request->domain,
            $request->extension,
            $allowedExtensions,
        );

        if ($parsed === null) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a valid domain name (e.g. example or example.com) and extension.',
                'available' => false,
            ], 422);
        }

        try {
            $domainSearch = new DomainSearchController;
            $fullDomain = $parsed['name'].$parsed['extension'];

            // Use reflection to call private method for availability check
            $reflection = new ReflectionClass($domainSearch);
            $method = $reflection->getMethod('checkAvailability');
            $method->setAccessible(true);

            $available = $method->invoke($domainSearch, $fullDomain);

            $years = max(1, min(10, (int) $request->input('years', 1)));

            // Get pricing for the selected registration period
            $extension = DomainExtension::where('extension', $parsed['extension'])->firstOrFail();
            $price = app(ResellerCustomerCatalogService::class)->domainRegistrationPrice(
                auth()->user(),
                $extension,
                $years,
            ) ?? 0;

            return response()->json([
                'success' => true,
                'available' => $available,
                'full_domain' => $fullDomain,
                'domain' => $parsed['name'],
                'extension' => $parsed['extension'],
                'years' => $years,
                'price' => $price,
                'message' => $available ? 'Domain is available!' : 'Domain is already taken',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking domain availability: '.$e->getMessage(),
                'available' => false,
            ], 500);
        }
    }

    /**
     * Get product price based on billing cycle
     */
    private function getProductPrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }

    /**
     * Update nameservers for a domain in cart
     */
    public function updateNameservers(string $key, Request $request): JsonResponse
    {
        $cart = session(self::CART_SESSION_KEY, []);

        if (! isset($cart[$key]) || $cart[$key]['type'] !== 'domain') {
            return response()->json(['success' => false, 'message' => 'Domain not found in cart'], 404);
        }

        $validated = $request->validate([
            'use_default' => 'required|boolean',
            'ns1' => 'required|string|min:3|max:253|regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i',
            'ns2' => 'nullable|string|min:3|max:253|regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i',
            'ns3' => 'nullable|string|min:3|max:253|regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i',
            'ns4' => 'nullable|string|min:3|max:253|regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i',
        ]);

        $cart[$key]['nameservers'] = [
            'ns1' => $validated['ns1'],
            'ns2' => $validated['ns2'] ?? null,
            'ns3' => $validated['ns3'] ?? null,
            'ns4' => $validated['ns4'] ?? null,
            'use_default' => (bool) $validated['use_default'],
        ];

        session([self::CART_SESSION_KEY => $cart]);

        return response()->json(['success' => true, 'message' => 'Nameservers updated']);
    }
}
