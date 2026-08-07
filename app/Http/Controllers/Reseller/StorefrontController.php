<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerLandingService;
use App\Services\ResellerPublicApiService;
use App\Services\ResellerStorefrontPromoService;
use App\Services\TaxService;
use App\Services\TwoFactorService;
use App\Services\UserCurrencyService;
use App\Support\SessionCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Same-origin storefront helpers for the reseller branding domain landing page.
 * Does not require the public website API to be enabled.
 */
class StorefrontController extends Controller
{
    public function __construct(
        private ResellerPublicApiService $api,
        private ResellerLandingService $landing,
        private ResellerStorefrontPromoService $promo,
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

    public function showCart(): View|RedirectResponse
    {
        $reseller = $this->currentReseller();

        if (! $this->landing->isEnabled($reseller)) {
            return redirect()->route('login');
        }

        $formatted = $this->formatCartForDisplay(auth()->user());
        $branding = app(ResellerBrandingResolver::class)->forReseller($reseller);

        return view('public.cart', [
            'reseller' => $reseller,
            'branding' => $branding,
            'landing' => $this->landing->config($reseller),
            'cartItems' => $formatted['items'],
            'itemCount' => count($formatted['items']),
            'subtotal' => $formatted['subtotal'],
            'discount' => $formatted['discount'],
            'discountLabel' => $formatted['discount_label'],
            'promoCode' => $formatted['promo_code'],
            'promoConfigured' => $formatted['promo_configured'],
            'tax' => $formatted['tax'],
            'taxEnabled' => $formatted['tax_enabled'],
            'taxRate' => $formatted['tax_rate'],
            'total' => $formatted['total'],
            'currency' => $formatted['currency'],
            'currencyCode' => $formatted['currency_code'],
            'continueUrl' => route('home'),
            'checkoutUrl' => route('customer.checkout.show'),
            'loginUrl' => route('login'),
            'registerUrl' => route('register'),
        ]);
    }

    /**
     * WordPress-friendly deep link: GET /{slug}/cart adds the product and opens the cart.
     */
    public function addProductBySlug(Request $request, string $productSlug): RedirectResponse
    {
        $reseller = $this->currentReseller();

        if (! $this->landing->isEnabled($reseller)) {
            return redirect()->route('login');
        }

        $billingCycle = (string) $request->query('billing_cycle', $request->query('billing', 'monthly'));
        if (! in_array($billingCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)) {
            $billingCycle = 'monthly';
        }

        $listing = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('slug', $productSlug)
            ->where('is_active', true)
            ->first();

        if (! $listing || ! $listing->isOrderable()) {
            return redirect()
                ->route('home')
                ->with('error', 'That product is not available to order.');
        }

        $newItems = $this->api->buildCartItems($reseller, [[
            'type' => 'reseller_product',
            'id' => $listing->id,
            'reseller_product_id' => $listing->id,
            'billing_cycle' => $billingCycle,
        ]]);

        if ($newItems === []) {
            return redirect()
                ->route('home')
                ->with('error', 'That product could not be added to the cart.');
        }

        $this->appendCartItems($reseller, $newItems);

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', $listing->name.' added to cart.');
    }

    public function addToCart(Request $request): JsonResponse|RedirectResponse
    {
        $reseller = $this->currentReseller();

        if (! $this->landing->isEnabled($reseller)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Storefront is not enabled.'], 404);
            }

            return redirect()->route('login');
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
            'items.*.epp_code' => 'nullable|string|min:5|max:64',
            'items.*.old_registrar' => 'nullable|string|min:2|max:100',
            'items.*.old_registrar_url' => 'nullable|string|max:255',
        ]);

        $newItems = $this->api->buildCartItems($reseller, $validated['items']);

        if ($newItems === []) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid items could be added to the cart. Check availability and pricing.',
                ], 422);
            }

            return back()->with('error', 'No valid items could be added to the cart.');
        }

        $cart = $this->appendCartItems($reseller, $newItems);

        $payload = [
            'success' => true,
            'item_count' => count($cart),
            'message' => count($newItems) === 1 ? 'Item added to cart.' : 'Items added to cart.',
            'cart_url' => route('reseller.public.store.cart.show'),
            'checkout_url' => route('customer.checkout.show'),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', $payload['message']);
    }

    /**
     * @param  list<array<string, mixed>>  $newItems
     * @return array<string, mixed>
     */
    private function appendCartItems(User $reseller, array $newItems): array
    {
        $cart = SessionCart::storefront();
        if (! is_array($cart)) {
            $cart = [];
        }

        foreach ($newItems as $item) {
            $cart[SessionCart::newLineKey('sf')] = $item;
        }

        SessionCart::putStorefront($cart);
        session(['registration_reseller_id' => $reseller->id]);

        return $cart;
    }

    public function removeFromCart(Request $request, string $key): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        $cart = SessionCart::storefront();
        if (is_array($cart) && array_key_exists($key, $cart)) {
            unset($cart[$key]);
            SessionCart::putStorefront($cart);
        }

        $redirectToCheckout = $request->input('redirect_to') === 'checkout';

        if ($cart === []) {
            return redirect()
                ->route('reseller.public.store.cart.show')
                ->with('success', 'Your cart is empty.');
        }

        if ($redirectToCheckout) {
            return redirect()
                ->route('customer.checkout.show')
                ->with('success', 'Item removed from cart.');
        }

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', 'Item removed from cart.');
    }

    public function clearCart(): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        SessionCart::clearStorefront();
        $this->promo->forget();

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', 'Cart cleared.');
    }

    public function updateCartItem(Request $request, string $key): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        $validated = $request->validate([
            'years' => 'nullable|integer|min:1|max:10',
            'billing_cycle' => 'nullable|string|in:monthly,quarterly,semi-annual,annual',
        ]);

        $cart = SessionCart::storefront();
        if (! is_array($cart) || ! isset($cart[$key]) || ! is_array($cart[$key])) {
            return redirect()
                ->route('reseller.public.store.cart.show')
                ->with('error', 'Cart item not found.');
        }

        $item = $cart[$key];
        $type = $item['type'] ?? '';

        if ($type === 'domain' && isset($validated['years'])) {
            $years = (int) $validated['years'];
            $extension = DomainExtension::where('extension', $item['extension'] ?? '')->first();
            if (! $extension) {
                return back()->with('error', 'Domain extension not found.');
            }

            $price = $this->api->retailPrice($reseller, $extension, $years);
            if ($price === null) {
                return back()->with('error', 'No retail price for that registration period.');
            }

            $item['years'] = $years;
            $item['price'] = $price;
            $cart[$key] = $item;
        } elseif (in_array($type, ['reseller_product', 'service'], true) && isset($validated['billing_cycle'])) {
            $item['billing_cycle'] = $validated['billing_cycle'];
            $cart[$key] = $item;
        } else {
            return back()->with('error', 'That cart line cannot be updated.');
        }

        SessionCart::putStorefront($cart);

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', 'Cart updated.');
    }

    public function applyPromo(Request $request): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        $validated = $request->validate([
            'promo_code' => 'required|string|max:32',
        ]);

        if (! $this->promo->matches($reseller, $validated['promo_code'])) {
            return back()->with('error', 'That promo code is not valid.');
        }

        $this->promo->remember($validated['promo_code']);

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', 'Promo code applied.');
    }

    public function removePromo(): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        $this->promo->forget();

        return redirect()
            ->route('reseller.public.store.cart.show')
            ->with('success', 'Promo code removed.');
    }

    /**
     * Log in an existing customer at checkout, then continue to pay.
     */
    public function loginAtCheckout(LoginRequest $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $reseller = $this->currentReseller();
        abort_unless($this->landing->isEnabled($reseller), 404);

        $request->authenticate();
        $user = Auth::user();

        if ($user->is_admin || $user->is_reseller) {
            Auth::logout();

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Use the staff portal to sign in with this account.']);
        }

        if ((int) ($user->reseller_id ?? 0) !== (int) $reseller->id) {
            Auth::logout();

            $company = $reseller->settings['branding']['company_name'] ?? 'this provider';

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This account is not registered with '.$company.'.']);
        }

        if ($user->two_factor_enabled) {
            $delivery = $twoFactorService->sendCode($user);
            if (! TwoFactorService::deliverySucceeded($delivery)) {
                Auth::logout();

                return back()->withErrors(['email' => 'Failed to send 2FA code by email or SMS. Please try again.']);
            }

            $request->session()->put('two_factor_user_id', $user->id);
            $request->session()->put('two_factor_delivery', $delivery);
            $request->session()->put('url.intended', route('customer.checkout.show'));
            Auth::logout();

            return redirect()->route('auth.two-factor.verify');
        }

        $request->session()->regenerate();
        session(['registration_reseller_id' => $reseller->id]);

        return redirect()
            ->route('customer.checkout.show')
            ->with('success', 'Welcome back. Review your order and complete payment.');
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     subtotal: float,
     *     discount: float,
     *     discount_label: string|null,
     *     promo_code: string|null,
     *     promo_configured: bool,
     *     tax: float,
     *     tax_enabled: bool,
     *     tax_rate: float|int|string|null,
     *     total: float,
     *     currency: mixed,
     *     currency_code: string
     * }
     */
    private function formatCartForDisplay(?User $user): array
    {
        $reseller = $this->currentReseller();
        $sessionCart = SessionCart::storefront();
        $cartItems = [];
        $subtotal = 0.0;

        if (! is_array($sessionCart)) {
            $sessionCart = [];
        }

        foreach ($sessionCart as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? '';
            $row = ['key' => $key, 'type' => $type];

            if ($type === 'domain') {
                $extension = DomainExtension::where('extension', $item['extension'] ?? '')->first();
                if (! $extension) {
                    continue;
                }

                $years = (int) ($item['years'] ?? 1);
                $price = isset($item['price'])
                    ? (float) $item['price']
                    : $this->api->retailPrice($reseller, $extension, $years);

                if ($price === null) {
                    continue;
                }

                $row['name'] = ($item['domain'] ?? '').($item['extension'] ?? '');
                $row['description'] = $years.' year'.($years === 1 ? '' : 's').' registration';
                $row['years'] = $years;
                $row['editable_years'] = true;
                $row['full_domain'] = $item['full_domain'] ?? $row['name'];
                $row['amount'] = $price;
            } elseif ($type === 'domain_transfer') {
                $extension = DomainExtension::where('extension', $item['extension'] ?? '')->first();
                $years = (int) ($item['years'] ?? 1);
                $price = isset($item['price'])
                    ? (float) $item['price']
                    : ($extension ? $this->api->transferRetailPrice($reseller, $extension) : null);

                if ($price === null) {
                    continue;
                }

                $row['name'] = $item['full_domain'] ?? (($item['domain'] ?? '').($item['extension'] ?? ''));
                $row['description'] = 'Domain transfer'.(filled($item['old_registrar'] ?? null) ? ' from '.$item['old_registrar'] : '');
                $row['years'] = $years;
                $row['amount'] = $price;
            } elseif ($type === 'reseller_product' || $type === 'service') {
                $listing = ResellerProduct::query()
                    ->where('id', $item['reseller_product_id'] ?? $item['id'] ?? null)
                    ->where('reseller_id', $reseller->id)
                    ->first();

                if (! $listing) {
                    continue;
                }

                $cycle = (string) ($item['billing_cycle'] ?? 'monthly');
                $amount = match ($cycle) {
                    'annual' => (float) ($listing->yearly_price ?? ($listing->monthly_price * 12)),
                    'quarterly' => (float) ($listing->monthly_price * 3),
                    'semi-annual' => (float) ($listing->monthly_price * 6),
                    default => (float) $listing->monthly_price,
                };
                $amount += (float) ($listing->setup_fee ?? 0);

                $row['name'] = $listing->name;
                $row['description'] = $listing->description ?? Product::typeLabel((string) $listing->type);
                $row['billing_cycle'] = $cycle;
                $row['editable_cycle'] = true;
                $row['amount'] = $amount;
            } else {
                continue;
            }

            $subtotal += $row['amount'];
            $cartItems[] = $row;
        }

        $promo = $this->promo->resolve($reseller, $subtotal);
        $taxable = max(0.0, $subtotal - $promo['discount']);
        $taxBreakdown = TaxService::calculateForUser($taxable, $user);
        $currency = app(UserCurrencyService::class)->model($user);

        return [
            'items' => $cartItems,
            'subtotal' => $subtotal,
            'discount' => $promo['discount'],
            'discount_label' => $promo['label'],
            'promo_code' => $promo['code'],
            'promo_configured' => $this->promo->configuredPromo($reseller) !== null,
            'tax' => (float) $taxBreakdown['tax'],
            'tax_enabled' => (bool) $taxBreakdown['enabled'],
            'tax_rate' => $taxBreakdown['rate'],
            'total' => (float) $taxBreakdown['total'],
            'currency' => $currency,
            'currency_code' => $currency->code,
        ];
    }

    private function currentReseller(): User
    {
        abort_unless(app()->bound('currentReseller'), 404);

        return app('currentReseller');
    }
}
