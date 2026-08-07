<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Services\PublicWebsiteApiContext;
use App\Support\SessionCart;
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
            'items.*.type' => 'required|string|in:domain,domain_transfer,service,reseller_product,product,reseller_package',
            'items.*.full_domain' => 'required_unless:items.*.type,service,product,reseller_product,reseller_package|nullable|string|max:253',
            'items.*.years' => 'nullable|integer|min:1|max:10',
            'items.*.epp_code' => 'required_if:items.*.type,domain_transfer|nullable|string|min:5|max:255',
            'items.*.old_registrar' => 'required_if:items.*.type,domain_transfer|nullable|string|min:2|max:255',
            'items.*.old_registrar_url' => 'nullable|url|max:255',
            'items.*.id' => 'nullable|integer',
            'items.*.product_id' => 'nullable|integer',
            'items.*.reseller_product_id' => 'nullable|integer',
            'items.*.reseller_package_id' => 'nullable|integer',
            'items.*.billing_cycle' => 'nullable|string|in:monthly,quarterly,semi-annual,annual',
            'items.*.location_key' => 'nullable|string|max:100',
            'items.*.ip_count' => 'nullable|integer|min:1|max:'.config('server_options.max_ip_count', 8),
            'items.*.operating_system' => 'nullable|string|max:100',
            'items.*.domain' => 'nullable|string|max:253',
        ]);

        $cart = $this->api->buildCartItems($validated['items']);

        if ($cart === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid items could be added to the cart. Check availability, pricing, and product IDs.',
            ], 422);
        }

        // Normalize to keyed lines and write the context-appropriate bag only.
        $normalized = SessionCart::normalize($cart);
        if ($this->api->isReseller()) {
            SessionCart::putStorefront($normalized);
            session(['registration_reseller_id' => $this->api->reseller()->id]);
        } else {
            // Platform public API must not wipe an authenticated portal cart — merge instead.
            $merged = SessionCart::mergeIncoming(SessionCart::portal(), array_values($normalized));
            SessionCart::putPortal($merged);
            session()->forget('registration_reseller_id');
            $normalized = $merged;
        }

        return response()->json([
            'success' => true,
            'item_count' => count($normalized),
            'checkout_url' => $this->api->checkoutUrl(),
        ]);
    }
}
