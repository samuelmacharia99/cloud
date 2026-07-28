<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\CheckoutController;
use App\Models\User;
use App\Services\ResellerLandingService;
use App\Services\ResellerPublicApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same-origin storefront helpers for the reseller branding domain landing page.
 * Does not require the public website API to be enabled.
 */
class StorefrontController extends Controller
{
    public function __construct(
        private ResellerPublicApiService $api,
        private ResellerLandingService $landing,
    ) {}

    public function searchDomains(Request $request): JsonResponse
    {
        $reseller = $this->currentReseller();
        $config = $this->landing->config($reseller);

        if (! $config['enabled'] || ! $config['show_domains']) {
            return response()->json(['success' => false, 'message' => 'Domain search is not available.'], 404);
        }

        $validated = $request->validate([
            'q' => 'required|string|min:2|max:253',
            'years' => 'nullable|integer|min:1|max:10',
        ]);

        return response()->json(
            $this->api->searchDomains($reseller, $validated['q'], (int) ($validated['years'] ?? 1))
        );
    }

    public function addToCart(Request $request): JsonResponse
    {
        $reseller = $this->currentReseller();

        if (! $this->landing->isEnabled($reseller)) {
            return response()->json(['success' => false, 'message' => 'Storefront is not enabled.'], 404);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1|max:10',
            'items.*.type' => 'required|string|in:domain,domain_transfer,service,reseller_product',
            'items.*.full_domain' => 'required_unless:items.*.type,service,reseller_product|nullable|string|max:253',
            'items.*.years' => 'nullable|integer|min:1|max:10',
            'items.*.id' => 'nullable|integer',
            'items.*.reseller_product_id' => 'nullable|integer',
            'items.*.billing_cycle' => 'nullable|string|in:monthly,quarterly,semi-annual,annual',
            'items.*.domain' => 'nullable|string|max:253',
        ]);

        $cart = $this->api->buildCartItems($reseller, $validated['items']);

        if ($cart === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid items could be added to the cart. Check availability and pricing.',
            ], 422);
        }

        session([
            CheckoutController::CART_SESSION_KEY => $cart,
            'registration_reseller_id' => $reseller->id,
        ]);

        return response()->json([
            'success' => true,
            'item_count' => count($cart),
            'checkout_url' => route('customer.checkout.show'),
        ]);
    }

    private function currentReseller(): User
    {
        abort_unless(app()->bound('currentReseller'), 404);

        return app('currentReseller');
    }
}
