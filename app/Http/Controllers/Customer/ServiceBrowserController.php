<?php

namespace App\Http\Controllers\Customer;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Services\Billing\UsageBillingProfileService;
use App\Services\Checkout\SharedHostingCheckoutService;
use App\Services\Customer\CustomerNextStepsService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TechStackRoutingService;
use App\Services\UserCurrencyService;
use App\Support\SharedHostingSales;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ServiceBrowserController extends Controller
{
    public function __construct(
        private ResellerCustomerCatalogService $catalogService,
    ) {}

    /**
     * Show techstack selection (language + database)
     */
    public function selectTechstack()
    {
        $languages = ContainerTemplate::active()
            ->reorder()
            ->orderByRaw("CASE slug
                WHEN 'wordpress' THEN 1
                WHEN 'nodejs' THEN 2
                WHEN 'python' THEN 3
                WHEN 'static-site' THEN 4
                ELSE 100
            END")
            ->orderBy('order')
            ->orderBy('name')
            ->get();
        $databases = DatabaseTemplate::active()->get();
        $cartCount = count(session('cart', []));

        return view('customer.select-techstack', [
            'languages' => $languages,
            'databases' => $databases,
            'cartCount' => $cartCount,
            'attachDomain' => app(SharedHostingCheckoutService::class)->attachDomainFromSession(),
        ]);
    }

    /**
     * Get available databases for selected language (AJAX)
     */
    public function getAvailableDatabases(Request $request, $languageId)
    {
        $language = ContainerTemplate::findOrFail($languageId);
        $deploymentPlatform = $request->query('deployment_platform');

        if ($deploymentPlatform && ! in_array($deploymentPlatform, ['shared', 'container'], true)) {
            return response()->json(['message' => 'Invalid deployment platform.'], 422);
        }

        if (TechStackRoutingService::supportsDeploymentPlatformChoice($language) && ! $deploymentPlatform) {
            return response()->json(['message' => 'Deployment platform is required.'], 422);
        }

        $databases = TechStackRoutingService::getAvailableDatabasesForLanguage($language, $deploymentPlatform);

        return response()->json([
            'databases' => $databases->map(fn ($db) => [
                'id' => $db->id,
                'name' => $db->name,
                'slug' => $db->slug,
                'type' => $db->type,
            ]),
        ]);
    }

    /**
     * Get available languages for selected database (AJAX)
     */
    public function getAvailableLanguages($databaseId)
    {
        $database = DatabaseTemplate::findOrFail($databaseId);
        $languages = TechStackRoutingService::getAvailableLanguagesForDatabase($database);

        return response()->json([
            'languages' => $languages->map(fn ($lang) => [
                'id' => $lang->id,
                'name' => $lang->name,
                'slug' => $lang->slug,
                'versions' => $lang->versions ?? [],
            ]),
        ]);
    }

    /**
     * Confirm techstack and show all available products (POST → redirect for safe refresh).
     */
    public function confirmTechstack(Request $request)
    {
        $validated = $request->validate([
            'language_id' => 'required|exists:container_templates,id',
            'database_id' => 'nullable|exists:database_templates,id',
            'deployment_platform' => 'nullable|in:shared,container',
        ]);

        $language = ContainerTemplate::findOrFail($validated['language_id']);
        $database = ! empty($validated['database_id'])
            ? DatabaseTemplate::findOrFail($validated['database_id'])
            : null;

        if (($validated['deployment_platform'] ?? null) === 'shared') {
            return back()->with('error', 'Shared DirectAdmin hosting is no longer available. Please choose application hosting.');
        }

        if ($database && $database->hosting_type !== 'container') {
            return back()->with('error', 'Selected database is not available for application hosting.');
        }

        if (! TechStackRoutingService::isValidCombination($language, $database)) {
            return back()->with('error', 'Invalid techstack combination selected');
        }

        $routing = TechStackRoutingService::determineHostingType(
            $language,
            $database,
            'container'
        );

        $user = $request->user();
        $products = $this->resolveTechstackProducts(
            $user,
            $language,
            $database,
            $routing,
        );

        if ($products->isEmpty()) {
            $message = $this->catalogService->isResellerCustomer($user)
                ? $this->catalogService->techstackEmptyMessage($user, $language, $routing)
                : 'No application hosting plans are available for this tech stack.';

            return back()->with('error', $message);
        }

        $techstackData = [
            'language_id' => $language->id,
            'language_name' => $language->name,
            'hosting_type' => 'container',
            'deployment_platform' => 'container',
        ];

        if ($database) {
            $techstackData['database_id'] = $database->id;
            $techstackData['database_name'] = $database->name;
        }

        session(['selected_techstack' => $techstackData]);

        $usageProfile = app(UsageBillingProfileService::class);
        if ($usageProfile->shouldUseUsageBillingForCustomer($user)) {
            return $this->queueUsageAppCheckout($request, $language);
        }

        return redirect()->route('customer.confirm-techstack');
    }

    /**
     * Show confirmed techstack packages (GET — safe to refresh).
     * Platform usage customers skip packages and go straight to checkout.
     */
    public function showConfirmTechstack(Request $request)
    {
        $techstack = session('selected_techstack');

        if (! is_array($techstack) || empty($techstack['language_id'])) {
            return redirect()->route('customer.select-techstack')
                ->with('error', 'Please select your tech stack first.');
        }

        $language = ContainerTemplate::find($techstack['language_id']);

        if (! $language) {
            session()->forget('selected_techstack');

            return redirect()->route('customer.select-techstack')
                ->with('error', 'Your tech stack selection expired. Please choose again.');
        }

        $database = ! empty($techstack['database_id'])
            ? DatabaseTemplate::find($techstack['database_id'])
            : null;

        $routing = TechStackRoutingService::determineHostingType(
            $language,
            $database,
            'container'
        );

        $user = $request->user();
        $products = $this->resolveTechstackProducts(
            $user,
            $language,
            $database,
            $routing,
        );

        if ($products->isEmpty()) {
            session()->forget('selected_techstack');

            return redirect()->route('customer.select-techstack')
                ->with('error', $this->catalogService->isResellerCustomer($user)
                    ? $this->catalogService->techstackEmptyMessage($user, $language, $routing)
                    : 'No application hosting plans are available for this tech stack.');
        }

        $usageProfile = app(UsageBillingProfileService::class);
        if ($usageProfile->shouldUseUsageBillingForCustomer($user)) {
            return $this->queueUsageAppCheckout($request, $language);
        }

        $currency = app(UserCurrencyService::class)->model($user);

        return view('customer.confirm-techstack', [
            'language' => $language,
            'database' => $database,
            'routing' => $routing,
            'products' => $products,
            'isResellerCustomer' => $this->catalogService->isResellerCustomer($user),
            'cartCount' => count(session('cart', [])),
            'currency' => $currency,
            'currencyCode' => $currency->code,
            'attachDomain' => app(SharedHostingCheckoutService::class)->attachDomainFromSession(),
        ]);
    }

    /**
     * Legacy route: domain is chosen on checkout now.
     */
    public function continueUsageDeploy(Request $request)
    {
        $user = $request->user();
        $usageProfile = app(UsageBillingProfileService::class);

        if (! $usageProfile->shouldUseUsageBillingForCustomer($user)) {
            return redirect()->route('customer.confirm-techstack');
        }

        $techstack = session('selected_techstack');
        if (! is_array($techstack) || empty($techstack['language_id'])) {
            return redirect()->route('customer.select-techstack')
                ->with('error', 'Please select your tech stack first.');
        }

        $language = ContainerTemplate::find($techstack['language_id']);
        if (! $language) {
            session()->forget('selected_techstack');

            return redirect()->route('customer.select-techstack')
                ->with('error', 'Your tech stack selection expired. Please choose again.');
        }

        return $this->queueUsageAppCheckout($request, $language);
    }

    /**
     * Auto-select usage product, add to cart (domain chosen at checkout), go to checkout.
     */
    private function queueUsageAppCheckout(Request $request, ContainerTemplate $language)
    {
        $user = $request->user();
        $usageProfile = app(UsageBillingProfileService::class);

        // Hard gate: never queue free/usage deploy for reseller customers.
        if (! $usageProfile->shouldUseUsageBillingForCustomer($user)) {
            return redirect()->route('customer.confirm-techstack');
        }

        $guard = app(\App\Services\Billing\UsageDeployGuardService::class);

        $product = $usageProfile->resolveAppProductForTemplate($language);
        if (! $product) {
            session()->forget('selected_techstack');

            return redirect()->route('customer.select-techstack')
                ->with('error', 'No application hosting product is available yet. Please contact support.');
        }

        try {
            $guard->assertCanStartDeploy($user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('customer.select-techstack')
                ->withErrors($e->errors())
                ->with('error', collect($e->errors())->flatten()->first());
        }

        $freeEligible = $guard->qualifiesForFreePeriod($user);
        $cart = session(CartController::CART_SESSION_KEY, []);

        // Avoid stacking duplicate usage lines for the same stack selection.
        foreach ($cart as $existing) {
            if (! empty($existing['usage_billing'])
                && (int) ($existing['product_id'] ?? 0) === (int) $product->id) {
                return redirect()->route('customer.checkout.show')
                    ->with('success', 'Continue checkout — choose register, transfer, or use your own domain.');
            }
        }

        $key = 'usage_'.bin2hex(random_bytes(8));
        $item = [
            'type' => 'product',
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'usage_billing' => true,
            'usage_free_period' => $freeEligible,
            'include_email' => $usageProfile->autoIncludeEmail(),
            'added_at' => now()->toIso8601String(),
        ];

        if ($request->filled('selected_version')) {
            $item['selected_version'] = (string) $request->input('selected_version');
        }

        $item = app(SharedHostingCheckoutService::class)->applyAttachDomainToHostingItem($item);
        $cart[$key] = $item;
        session([CartController::CART_SESSION_KEY => $cart]);

        $message = $freeEligible
            ? 'Application hosting added — first '.$guard->freePeriodDays().' days free. Choose a domain at checkout (register, transfer, or use your own).'
            : 'Application hosting added. Choose a domain at checkout to continue.';

        return redirect()->route('customer.checkout.show')->with('success', $message);
    }

    /**
     * @return Collection<int, mixed>
     */
    private function resolveTechstackProducts(
        $user,
        ContainerTemplate $language,
        ?DatabaseTemplate $database,
        array $routing,
    ) {
        if ($this->catalogService->isResellerCustomer($user)) {
            return $this->catalogService->resolveTechstackProductsForResellerCustomer(
                $user,
                $language,
                $database,
                $routing,
            );
        }

        // Platform customers only order application hosting (never DirectAdmin shared).
        $products = Product::query()
            ->where('is_active', true)
            ->where('type', 'container_hosting')
            ->where('container_template_id', $language->id)
            ->orderBy('order')
            ->get();

        return $this->catalogService->mapProductsForTechstackDisplay(
            $user,
            $products,
            $database?->id,
        );
    }

    /**
     * Redirect to techstack selection - primary deployment flow
     */
    public function index(Request $request)
    {
        // Always redirect to techstack selection first
        return redirect()->route('customer.select-techstack');
    }

    /**
     * Get available products for a techstack combination (AJAX)
     */
    public function getAvailableProducts(Request $request)
    {
        $request->validate([
            'type' => 'required|in:shared_hosting,container_hosting',
            'template_id' => 'nullable|exists:container_templates,id',
            'database_id' => 'nullable|exists:database_templates,id',
        ]);

        $user = $request->user();

        if ($this->catalogService->isResellerCustomer($user) && $request->template_id) {
            $language = ContainerTemplate::findOrFail($request->template_id);
            $database = $request->database_id
                ? DatabaseTemplate::findOrFail($request->database_id)
                : null;
            $routing = ['hosting_type' => $request->type === 'shared_hosting' ? 'directadmin' : 'container'];

            $products = $this->catalogService->resolveTechstackProductsForResellerCustomer(
                $user,
                $language,
                $database,
                $routing,
            );
        } else {
            if ($request->type === 'shared_hosting') {
                return response()->json([
                    'products' => [],
                    'message' => 'Shared DirectAdmin hosting is no longer available for platform customers.',
                ]);
            }

            $query = Product::where('type', $request->type)
                ->where('is_active', true);

            if ($request->template_id) {
                $query->where('container_template_id', $request->template_id);
            }

            $query = $this->catalogService->scopePlatformProducts($query, $user);
            $products = $this->catalogService->mapProductsForTechstackDisplay(
                $user,
                $query->orderBy('order')->get(),
                $request->integer('database_id') ?: null,
            );
        }

        return response()->json([
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'reseller_product_id' => $p->reseller_product_id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'monthly_price' => $p->monthly_price,
                'features' => $p->features ?? [],
            ]),
        ]);
    }

    /**
     * Browse all services without techstack selection
     */
    public function browse(Request $request)
    {
        // Get selected filter type from query params
        $selectedType = $request->get('type', null);

        // Get all active products
        $query = Product::where('is_active', true);

        if ($selectedType && $selectedType !== 'all') {
            $query->where('type', $selectedType);
        }

        if (! SharedHostingSales::enabled()) {
            $query->where('type', '!=', 'shared_hosting');
        }

        $products = $query->orderBy('category')->orderBy('order')->get();

        // Group products by type
        $groupedProducts = $products->groupBy('type');

        // Get all available types for filtering
        $allTypes = Product::where('is_active', true)
            ->when(! SharedHostingSales::enabled(), fn ($q) => $q->where('type', '!=', 'shared_hosting'))
            ->distinct()
            ->pluck('type')
            ->mapWithKeys(function ($type) {
                return [$type => Product::typeLabel($type)];
            })
            ->toArray();

        // Get cart item count from session
        $cartCount = count(session('cart', []));

        return view('customer.deploy-service', [
            'products' => $products,
            'groupedProducts' => $groupedProducts,
            'allTypes' => $allTypes,
            'selectedType' => $selectedType,
            'cartCount' => $cartCount,
        ]);
    }

    /**
     * Dedicated Email Hosting order page (Mailcow) — not part of tech stack.
     */
    public function emailHosting()
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('type', 'email_hosting')
            ->orderBy('order')
            ->orderBy('monthly_price')
            ->get();

        return view('customer.email-hosting', [
            'products' => $products,
            'cartCount' => count(session('cart', [])),
        ]);
    }

    /**
     * List the customer's email hosting services (inboxes hub).
     */
    public function emailInboxes(Request $request)
    {
        $services = $request->user()
            ->services()
            ->with(['product', 'node'])
            ->whereHas('product', fn ($q) => $q->where('type', 'email_hosting'))
            ->latest()
            ->get();

        $nextSteps = app(CustomerNextStepsService::class);
        $healthById = [];
        foreach ($services as $service) {
            $status = $service->status instanceof ServiceStatus
                ? $service->status
                : ServiceStatus::tryFrom((string) $service->status);

            if ($status === ServiceStatus::Active) {
                $healthById[$service->id] = $nextSteps->emailHealth($service);
            }
        }

        return view('customer.email-inboxes', [
            'services' => $services,
            'healthById' => $healthById,
        ]);
    }
}
