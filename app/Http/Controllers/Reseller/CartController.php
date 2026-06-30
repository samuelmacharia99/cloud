<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerDomainOrderService;
use App\Services\ResellerNameserverService;
use App\Services\ResellerScopeService;
use App\Services\TaxService;
use App\Support\ResellerCartContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public const CART_KEY = 'reseller_cart';

    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerOrderService $orders,
        private DomainAvailabilityService $availability,
        private ResellerNameserverService $nameservers,
    ) {}

    public function index(): View
    {
        $cart = session(self::CART_KEY, []);
        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if (in_array($item['type'] ?? 'domain', ['domain', 'domain_transfer', 'domain_renewal'], true)) {
                $total = self::cartItemTotal($item);
                $subtotal += $total;
                $items[$key] = array_merge($item, ['total' => $total]);
            }
        }

        $taxBreakdown = TaxService::calculateResellerWholesale($subtotal);

        $cartContext = ResellerCartContext::summary();
        $checkoutCustomer = ResellerCartContext::resolveCheckoutCustomer(auth()->user(), $cart, $this->scope);
        $resellerDefaults = $this->nameservers->defaultsForReseller(auth()->user());
        $platformDefaults = $this->nameservers->platformDefaults();

        return view('reseller.cart.index', [
            'items' => $items,
            'subtotal' => $taxBreakdown['subtotal'],
            'tax' => $taxBreakdown['tax'],
            'taxEnabled' => $taxBreakdown['enabled'],
            'taxRate' => $taxBreakdown['rate'],
            'taxName' => $taxBreakdown['name'],
            'total' => $taxBreakdown['total'],
            'cartContext' => $cartContext,
            'checkoutCustomer' => $checkoutCustomer,
            'resellerDefaults' => $resellerDefaults,
            'platformDefaults' => $platformDefaults,
        ]);
    }

    public function setContext(Request $request): RedirectResponse|JsonResponse
    {
        $reseller = auth()->user();

        if ($request->input('mode') === 'self' || ! $request->filled('customer_id')) {
            ResellerCartContext::setSelf();

            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'mode' => 'self']);
            }

            return back()->with('success', 'Cart set to your wholesale account.');
        }

        $customer = User::findOrFail($request->integer('customer_id'));
        if (! $this->scope->ownsCustomer($reseller, $customer)) {
            abort(404);
        }

        ResellerCartContext::setCustomer($customer->id);
        ResellerCartContext::setCustomerName($customer->name);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'customer',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]);
        }

        return back()->with('success', "Cart will bill {$customer->name} at your retail prices.");
    }

    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'extension' => 'required|string',
            'years' => 'required|integer|min:1|max:10',
            'price' => 'required|numeric|min:0',
        ]);

        if (ResellerCartContext::isCustomerMode()) {
            return $this->addForCustomer($validated);
        }

        $extension = DomainExtension::query()
            ->where('extension', $validated['extension'])
            ->where('enabled', true)
            ->first();

        if (! $extension) {
            return response()->json(['success' => false, 'message' => 'Extension not available.'], 422);
        }

        if (! $this->availability->isAvailable($validated['domain'], $validated['extension'])) {
            return response()->json([
                'success' => false,
                'message' => 'This domain is not available for registration.',
            ], 422);
        }

        $expectedWholesaleTotal = $this->orders->wholesaleAmountForExtension($extension, (int) $validated['years']);
        if ($expectedWholesaleTotal <= 0) {
            return response()->json(['success' => false, 'message' => 'Wholesale pricing not configured for this extension.'], 422);
        }

        $expectedUnitPrice = round($expectedWholesaleTotal / (int) $validated['years'], 2);
        if (abs((float) $validated['price'] - $expectedUnitPrice) > 0.02) {
            return response()->json([
                'success' => false,
                'message' => 'Price mismatch. Refresh search and try again.',
            ], 422);
        }

        $cart = session()->get(self::CART_KEY, []);
        $key = uniqid();

        $cart[$key] = [
            'type' => 'domain',
            'domain' => strtolower($validated['domain']),
            'extension' => $validated['extension'],
            'years' => (int) $validated['years'],
            'price' => $expectedUnitPrice,
            'nameservers' => $this->nameservers->cartDefaultPayload(auth()->user()),
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain added to cart',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function addForCustomer(array $validated): JsonResponse
    {
        $customerId = ResellerCartContext::customerId();
        if (! $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Select a customer for whitelabel checkout first.',
            ], 422);
        }

        $reseller = auth()->user();
        $customer = User::findOrFail($customerId);
        if (! $this->scope->ownsCustomer($reseller, $customer)) {
            abort(404);
        }

        $extension = DomainExtension::query()
            ->where('extension', $validated['extension'])
            ->where('enabled', true)
            ->first();

        if (! $extension) {
            return response()->json(['success' => false, 'message' => 'Extension not available.'], 422);
        }

        $expectedRetail = $this->orders->retailAmountForExtension(
            $reseller,
            $extension,
            (int) $validated['years'],
        );

        $submittedTotal = (float) $validated['price'] * (int) $validated['years'];
        if (abs($submittedTotal - $expectedRetail) > 0.02) {
            return response()->json([
                'success' => false,
                'message' => 'Price mismatch. Refresh and try again.',
            ], 422);
        }

        $cart = session()->get(self::CART_KEY, []);
        $key = uniqid('cust_', true);

        $cart[$key] = [
            'type' => 'domain',
            'domain' => strtolower($validated['domain']),
            'extension' => $validated['extension'],
            'years' => (int) $validated['years'],
            'price' => round($expectedRetail / (int) $validated['years'], 2),
            'retail_total' => $expectedRetail,
            'nameservers' => $this->nameservers->cartDefaultPayload($reseller),
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain added for '.$customer->name,
        ]);
    }

    public function addTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'extension' => 'required|string',
            'price' => 'required|numeric|min:0',
            'epp_code' => 'required|string|min:5|max:255',
            'old_registrar' => 'required|string|min:2|max:255',
            'old_registrar_url' => 'nullable|url|max:255',
        ]);

        $extension = DomainExtension::query()
            ->where('extension', $validated['extension'])
            ->where('enabled', true)
            ->first();

        if (! $extension) {
            return response()->json(['success' => false, 'message' => 'Extension not available.'], 422);
        }

        $orderService = app(ResellerDomainOrderService::class);
        $expectedWholesale = $orderService->resolveTransferWholesaleAmount($extension->extension, 0);

        if ($expectedWholesale <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Wholesale transfer pricing is not configured for this extension.',
            ], 422);
        }

        if (ResellerCartContext::isCustomerMode()) {
            return $this->addTransferForCustomer($validated, $extension, $expectedWholesale);
        }

        if (abs((float) $validated['price'] - $expectedWholesale) > 0.02) {
            return response()->json([
                'success' => false,
                'message' => 'Price mismatch. Refresh and try again.',
            ], 422);
        }

        $cart = session()->get(self::CART_KEY, []);
        $key = uniqid('xfer_', true);

        $cart[$key] = [
            'type' => 'domain_transfer',
            'domain' => strtolower($validated['domain']),
            'extension' => $validated['extension'],
            'years' => 1,
            'price' => $expectedWholesale,
            'epp_code' => $validated['epp_code'],
            'old_registrar' => $validated['old_registrar'],
            'old_registrar_url' => $validated['old_registrar_url'] ?? null,
            'nameservers' => $this->nameservers->cartDefaultPayload(auth()->user()),
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain transfer added to cart',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function addTransferForCustomer(array $validated, DomainExtension $extension, float $expectedWholesale): JsonResponse
    {
        $customerId = ResellerCartContext::customerId();
        if (! $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Select a customer for whitelabel checkout first.',
            ], 422);
        }

        $reseller = auth()->user();
        $customer = User::findOrFail($customerId);
        if (! app(ResellerScopeService::class)->ownsCustomer($reseller, $customer)) {
            abort(404);
        }

        $expectedRetail = app(ResellerCustomerCatalogService::class)
            ->domainTransferPrice($customer, $extension);

        if (abs((float) $validated['price'] - $expectedRetail) > 0.02) {
            return response()->json([
                'success' => false,
                'message' => 'Price mismatch. Refresh and try again.',
            ], 422);
        }

        $cart = session()->get(self::CART_KEY, []);
        $key = uniqid('xfer_', true);

        $cart[$key] = [
            'type' => 'domain_transfer',
            'domain' => strtolower($validated['domain']),
            'extension' => $validated['extension'],
            'years' => 1,
            'price' => $expectedRetail,
            'retail_total' => $expectedRetail,
            'wholesale_total' => $expectedWholesale,
            'epp_code' => $validated['epp_code'],
            'old_registrar' => $validated['old_registrar'],
            'old_registrar_url' => $validated['old_registrar_url'] ?? null,
            'nameservers' => $this->nameservers->cartDefaultPayload($reseller),
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain transfer added for '.$customer->name,
        ]);
    }

    public function remove(string $key): RedirectResponse
    {
        $cart = session(self::CART_KEY, []);
        unset($cart[$key]);
        session()->put(self::CART_KEY, $cart);

        return redirect()->route('reseller.cart.index')
            ->with('success', 'Item removed from cart');
    }

    public function clear(): RedirectResponse
    {
        session()->forget(self::CART_KEY);

        return redirect()->route('reseller.cart.index')
            ->with('success', 'Cart cleared');
    }

    public function updateNameservers(string $key, Request $request): JsonResponse
    {
        $cart = session(self::CART_KEY, []);

        if (! isset($cart[$key])) {
            return response()->json(['success' => false, 'message' => 'Cart item not found.'], 404);
        }

        if (! $this->nameservers->itemNeedsNameservers($cart[$key])) {
            return response()->json(['success' => false, 'message' => 'This item does not support nameservers.'], 422);
        }

        try {
            $cart[$key]['nameservers'] = $this->nameservers->parseSubmitted(
                $request->all(),
                auth()->user(),
            );
            session()->put(self::CART_KEY, $cart);

            return response()->json(['success' => true, 'message' => 'Nameservers saved.']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Invalid nameservers.',
            ], 422);
        }
    }

    public static function cartItemTotal(array $item): float
    {
        if (($item['type'] ?? 'domain') === 'domain_renewal') {
            return (float) ($item['retail_total'] ?? $item['price']);
        }

        if (($item['type'] ?? 'domain') === 'domain_transfer') {
            return (float) ($item['retail_total'] ?? $item['price']);
        }

        if (isset($item['retail_total'])) {
            return (float) $item['retail_total'];
        }

        return (float) $item['price'] * (int) $item['years'];
    }

    private function resolveCheckoutCustomer(): ?User
    {
        return ResellerCartContext::resolveCheckoutCustomer(
            auth()->user(),
            session(self::CART_KEY, []),
            $this->scope,
        );
    }
}
