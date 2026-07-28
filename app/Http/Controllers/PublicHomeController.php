<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Customer\CheckoutController;
use App\Services\ResellerLandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicHomeController extends Controller
{
    public function __construct(
        private ResellerLandingService $landing,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $reseller = app()->bound('currentReseller') ? app('currentReseller') : null;

        if (! $reseller) {
            return redirect()->route('login');
        }

        if (! $this->landing->isEnabled($reseller)) {
            return redirect()->route('login');
        }

        $payload = $this->landing->storefrontPayload($reseller);
        $template = $payload['config']['template'];
        $cart = session(CheckoutController::CART_SESSION_KEY, []);

        return view($this->landing->viewName($template), [
            'reseller' => $reseller,
            'landing' => $payload['config'],
            'branding' => $payload['branding'],
            'extensions' => $payload['extensions'],
            'serviceGroups' => $payload['service_groups'],
            'searchUrl' => route('reseller.public.store.domains.search'),
            'cartUrl' => route('reseller.public.store.cart'),
            'cartPageUrl' => route('reseller.public.store.cart.show'),
            'cartCount' => is_array($cart) ? count($cart) : 0,
            'checkoutUrl' => route('customer.checkout.show'),
            'loginUrl' => route('login'),
            'registerUrl' => route('register'),
        ]);
    }
}
