<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerScopeService;
use App\Support\ResellerCartContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public const CART_KEY = 'reseller_cart';

    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerOrderService $orders,
    ) {}

    public function index(): View
    {
        $cart = session(self::CART_KEY, []);
        $items = [];
        $subtotal = 0;

        foreach ($cart as $key => $item) {
            if (in_array($item['type'] ?? 'domain', ['domain', 'domain_renewal'], true)) {
                $total = self::cartItemTotal($item);
                $subtotal += $total;
                $items[$key] = array_merge($item, ['total' => $total]);
            }
        }

        $taxEnabled = Setting::getValue('tax_enabled') === 'true';
        $taxRate = $taxEnabled ? (float) Setting::getValue('tax_rate', 0) : 0;
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        $cartContext = ResellerCartContext::summary();
        $checkoutCustomer = $this->resolveCheckoutCustomer();

        return view('reseller.cart.index', compact(
            'items',
            'subtotal',
            'tax',
            'taxEnabled',
            'taxRate',
            'total',
            'cartContext',
            'checkoutCustomer',
        ));
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
            'added_at' => now()->toIso8601String(),
        ];

        session()->put(self::CART_KEY, $cart);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'message' => 'Domain added for '.$customer->name,
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

    public static function cartItemTotal(array $item): float
    {
        if (($item['type'] ?? 'domain') === 'domain_renewal') {
            return (float) $item['price'];
        }

        if (isset($item['retail_total'])) {
            return (float) $item['retail_total'];
        }

        return (float) $item['price'] * (int) $item['years'];
    }

    private function resolveCheckoutCustomer(): ?User
    {
        if (! ResellerCartContext::isCustomerMode()) {
            return null;
        }

        $customerId = ResellerCartContext::customerId();
        if (! $customerId) {
            return null;
        }

        $customer = User::find($customerId);
        if (! $customer || ! $this->scope->ownsCustomer(auth()->user(), $customer)) {
            ResellerCartContext::setSelf();

            return null;
        }

        return $customer;
    }
}
