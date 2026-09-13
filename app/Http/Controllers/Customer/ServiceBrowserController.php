<?php

namespace App\Http\Controllers\Customer;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Services\Checkout\CartLineException;
use App\Services\Checkout\CartLineFactory;
use App\Services\Checkout\SharedHostingCheckoutService;
use App\Services\Customer\CustomerNextStepsService;
use App\Services\Customer\StackEligibilityService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TechStackRoutingService;
use App\Services\UserCurrencyService;
use App\Support\SessionCart;
use App\Support\SharedHostingSales;
use Illuminate\Http\RedirectResponse;
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
        $languages = ContainerTemplate::offeredForNewDeploy()->catalogOrder()->get();
        $databases = DatabaseTemplate::active()->get();
        $cartCount = count(SessionCart::portal());

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
     * Stack builder options for selected language (AJAX).
     */
    public function getStackOptions(Request $request, $languageId)
    {
        $language = ContainerTemplate::findOrFail($languageId);
        $framework = $request->query('framework');

        if ($framework !== null && $framework !== '' && ! is_string($framework)) {
            return response()->json(['message' => 'Invalid framework.'], 422);
        }

        return response()->json(
            TechStackRoutingService::stackOptionsPayload(
                $language,
                is_string($framework) && $framework !== '' ? $framework : null
            )
        );
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
            'framework' => 'nullable|string|max:64',
            'frontend' => ['nullable', 'string', 'max:64'],
            'project_id' => ['nullable', 'integer', 'exists:customer_projects,id'],
            'selected_version' => ['nullable', 'string', 'max:32'],
        ]);

        $language = ContainerTemplate::findOrFail($validated['language_id']);
        if (! $language->isOfferedForNewDeploy()) {
            return back()->with('error', 'That runtime is not available for new deploys.');
        }
        $database = ! empty($validated['database_id'])
            ? DatabaseTemplate::findOrFail($validated['database_id'])
            : null;

        if (($validated['deployment_platform'] ?? null) === 'shared') {
            return back()->with('error', 'Shared DirectAdmin hosting is no longer available. Please choose application hosting.');
        }

        if ($database && $database->hosting_type !== 'container') {
            return back()->with('error', 'Selected database is not available for application hosting.');
        }

        $framework = $validated['framework'] ?? null;
        $frontend = $validated['frontend'] ?? null;

        if (! TechStackRoutingService::isValidStackSelection($language, $framework, $frontend, $database)) {
            return back()->with('error', 'Invalid techstack combination selected');
        }

        $requiredVersions = TechStackRoutingService::requiredSelectedVersions($language);
        $allowedVersions = TechStackRoutingService::allowedSelectedVersions($language);
        $selectedVersion = $validated['selected_version'] ?? null;
        if ($requiredVersions !== [] && ! in_array((string) $selectedVersion, $requiredVersions, true)) {
            return back()->with('error', 'Choose a '.strtolower(TechStackRoutingService::versionPickerPayload($language)['label']).'.');
        }
        if ($selectedVersion !== null && $selectedVersion !== ''
            && ! in_array($selectedVersion, $allowedVersions, true)) {
            return back()->withErrors(['selected_version' => 'The selected runtime version is not supported.'])->withInput();
        }

        $roles = TechStackRoutingService::resolveDefaultRoles($language, $framework, $frontend);

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

        // Plan-first flow: the plan was chosen on the deploy page, so it must
        // be one of the plans that can run this stack.
        $plan = session(DeployServiceController::SESSION_PLAN);
        $chosenPlan = null;
        if (is_array($plan) && ! empty($plan['product_id'])) {
            $chosenPlan = $products->first(fn (object $product) => (int) $product->id === (int) $plan['product_id']
                && (int) ($product->reseller_product_id ?? 0) === (int) ($plan['reseller_product_id'] ?? 0));

            // A pinned plan only lists in its own stack's products, but a
            // resource shortfall does not filter products; check it here too.
            $choice = app(StackEligibilityService::class)->forPlan(
                isset($plan['container_template_id']) ? (int) $plan['container_template_id'] : null,
                StackEligibilityService::limitsFromResourceLimits(is_array($plan['resource_limits'] ?? null) ? $plan['resource_limits'] : []),
                collect([$language]),
            )->first();

            if ($chosenPlan === null || ! $choice?->eligible) {
                $reason = $choice && ! $choice->eligible ? ' '.$choice->reason : '';

                return redirect()->route('customer.deploy-service.stack')
                    ->with('error', ($plan['name'] ?? 'That plan').' cannot run '.$language->name.'.'.$reason.' Choose another stack, or go back and pick another plan.');
            }
        }

        $techstackData = [
            'language_id' => $language->id,
            'language_name' => $language->name,
            'language_slug' => $language->slug,
            'backend' => $roles['backend'],
            'framework' => $roles['framework'],
            'frontend' => $roles['frontend'],
            'hosting_type' => 'container',
            'deployment_platform' => 'container',
            'stack_builder_version' => (int) config('stack_builder.version', 1),
        ];

        if ($selectedVersion !== null && $selectedVersion !== '') {
            $techstackData['selected_version'] = $selectedVersion;
        }
        if ($language->slug === 'nodejs') {
            $techstackData['node_version_source'] = $selectedVersion !== null && $selectedVersion !== ''
                ? 'manual'
                : 'auto';
        }

        $projectId = (int) ($validated['project_id'] ?? ($plan['project_id'] ?? 0));
        if ($projectId > 0) {
            $ownedProject = CustomerProject::query()
                ->where('user_id', $user->id)
                ->whereKey($projectId)
                ->first();
            if ($ownedProject) {
                $techstackData['project_id'] = $ownedProject->id;
            }
        }

        if ($database) {
            $techstackData['database_id'] = $database->id;
            $techstackData['database_name'] = $database->name;
        }

        session(['selected_techstack' => $techstackData]);

        if ($chosenPlan !== null) {
            return $this->addChosenPlanToCart($user, $plan, $chosenPlan, $language->name);
        }

        return redirect()->route('customer.confirm-techstack');
    }

    /**
     * Plan-first flow: the confirm page's plan cards are moot when the plan
     * is already chosen, so the line goes straight to the cart.
     *
     * @param  array<string, mixed>  $plan
     */
    private function addChosenPlanToCart($user, array $plan, object $chosenPlan, string $stackName): RedirectResponse
    {
        try {
            $factory = app(CartLineFactory::class);
            $item = ! empty($chosenPlan->reseller_product_id)
                ? $factory->forResellerProduct($user, (int) $chosenPlan->reseller_product_id, (string) $plan['billing_cycle'])
                : $factory->forProduct($user, (int) $chosenPlan->id, (string) $plan['billing_cycle']);
        } catch (CartLineException $e) {
            return redirect()->route('customer.deploy-service')->with('error', $e->getMessage());
        }

        SessionCart::append(SessionCart::portalKey(), $item, SessionCart::newLineKey('c'));
        session()->forget(DeployServiceController::SESSION_PLAN);

        return redirect()->route('customer.cart.index')
            ->with('success', ($chosenPlan->name ?? 'Plan').' with '.$stackName.' added to your cart. Check out to deploy.');
    }

    /**
     * Show confirmed techstack packages (GET — safe to refresh).
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

        $currency = app(UserCurrencyService::class)->model($user);

        return view('customer.confirm-techstack', [
            'language' => $language,
            'database' => $database,
            'routing' => $routing,
            'products' => $products,
            'isResellerCustomer' => $this->catalogService->isResellerCustomer($user),
            'cartCount' => count(SessionCart::portal()),
            'currency' => $currency,
            'currencyCode' => $currency->code,
            'attachDomain' => app(SharedHostingCheckoutService::class)->attachDomainFromSession(),
            'stackSummary' => TechStackRoutingService::selectionSummary($techstack),
            'stackSelection' => $techstack,
        ]);
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
            ->forTechstackLanguage((int) $language->id)
            ->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')
            ->orderBy('name')
            ->get();

        return $this->catalogService->mapProductsForTechstackDisplay(
            $user,
            $products,
            $database?->id,
        );
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
                $query->forTechstackLanguage((int) $request->template_id);
            }

            $query = $this->catalogService->scopePlatformProducts($query, $user);
            $products = $this->catalogService->mapProductsForTechstackDisplay(
                $user,
                $query->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')->orderBy('name')->get(),
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

        $products = $query->orderBy('category')
            ->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')
            ->orderBy('name')
            ->get();

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
        $cartCount = count(SessionCart::portal());

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
            ->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')
            ->orderBy('name')
            ->get();

        return view('customer.email-hosting', [
            'products' => $products,
            'cartCount' => count(SessionCart::portal()),
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
