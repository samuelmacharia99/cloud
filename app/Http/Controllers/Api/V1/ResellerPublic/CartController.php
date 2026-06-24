<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\CheckoutController;
use App\Services\PublicWebsiteApiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private PublicWebsiteApiContext $api,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:10',
            'items.*.type' => 'required|string|in:domain,service,reseller_product,product,reseller_package',
            'items.*.full_domain' => 'required_if:items.*.type,domain|nullable|string|max:253',
            'items.*.years' => 'nullable|integer|min:1|max:10',
            'items.*.id' => 'nullable|integer',
            'items.*.product_id' => 'nullable|integer',
            'items.*.reseller_product_id' => 'nullable|integer',
            'items.*.reseller_package_id' => 'nullable|integer',
            'items.*.billing_cycle' => 'nullable|string|in:monthly,quarterly,semi-annual,annual',
            'items.*.location_key' => 'nullable|string|max:100',
            'items.*.ip_count' => 'nullable|integer|min:1|max:'.config('server_options.max_ip_count', 8),
            'items.*.operating_system' => 'nullable|string|max:100',
        ]);

        $cart = $this->api->buildCartItems($validated['items']);

        if ($cart === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid items could be added to the cart. Check availability, pricing, and product IDs.',
            ], 422);
        }

        $session = [CheckoutController::CART_SESSION_KEY => $cart];

        if ($this->api->isReseller()) {
            $session['registration_reseller_id'] = $this->api->reseller()->id;
        } else {
            session()->forget('registration_reseller_id');
        }

        session($session);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'checkout_url' => $this->api->checkoutUrl(),
        ]);
    }
}
