<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\CheckoutController;
use App\Services\ResellerPublicApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private ResellerPublicApiService $publicApi,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:10',
            'items.*.type' => 'required|string|in:domain,service,reseller_product',
            'items.*.full_domain' => 'required_if:items.*.type,domain|nullable|string|max:253',
            'items.*.years' => 'nullable|integer|min:1|max:10',
            'items.*.id' => 'nullable|integer',
            'items.*.reseller_product_id' => 'nullable|integer',
            'items.*.billing_cycle' => 'nullable|string|in:monthly,quarterly,semi-annual,annual',
        ]);

        $reseller = app('currentReseller');
        $cart = $this->publicApi->buildCartItems($reseller, $validated['items']);

        if ($cart === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid items could be added to the cart. Check availability, pricing, and product IDs.',
            ], 422);
        }

        session([
            CheckoutController::CART_SESSION_KEY => $cart,
            'registration_reseller_id' => $reseller->id,
        ]);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'checkout_url' => $this->publicApi->checkoutUrl($reseller),
        ]);
    }
}
