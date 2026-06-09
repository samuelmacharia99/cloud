<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ContainerTemplate;
use App\Models\Currency;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TechStackRoutingService;
use Illuminate\Http\Request;

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
        $languages = ContainerTemplate::active()->get();
        $databases = DatabaseTemplate::active()->get();
        $cartCount = count(session('cart', []));

        return view('customer.select-techstack', [
            'languages' => $languages,
            'databases' => $databases,
            'cartCount' => $cartCount,
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

        if (TechStackRoutingService::isLaravel($language) && ! $deploymentPlatform) {
            return response()->json(['message' => 'Deployment platform is required for Laravel.'], 422);
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
     * Confirm techstack and show all available products
     */
    public function confirmTechstack(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:container_templates,id',
            'database_id' => 'nullable|exists:database_templates,id',
            'deployment_platform' => 'nullable|in:shared,container',
        ]);

        $language = ContainerTemplate::findOrFail($request->language_id);
        $database = $request->database_id ? DatabaseTemplate::findOrFail($request->database_id) : null;

        if (TechStackRoutingService::isLaravel($language) && ! $request->deployment_platform) {
            return back()->with('error', 'Please choose shared or container hosting for Laravel.');
        }

        if (TechStackRoutingService::isLaravel($language) && $database) {
            $expectedHosting = $request->deployment_platform === 'shared' ? 'directadmin' : 'container';
            if ($database->hosting_type !== $expectedHosting) {
                return back()->with('error', 'Selected database does not match the chosen hosting platform.');
            }
        }

        // Validate combination
        if (! TechStackRoutingService::isValidCombination($language, $database)) {
            return back()->with('error', 'Invalid techstack combination selected');
        }

        // Determine hosting type
        $routing = TechStackRoutingService::determineHostingType(
            $language,
            $database,
            $request->deployment_platform
        );

        $user = $request->user();

        if ($this->catalogService->isResellerCustomer($user)) {
            $products = $this->catalogService->resolveTechstackProductsForResellerCustomer(
                $user,
                $language,
                $database,
                $routing,
            );
        } else {
            $productsQuery = Product::where('is_active', true);

            if ($routing['hosting_type'] === 'directadmin') {
                $productsQuery->where('type', 'shared_hosting');
            } else {
                $productsQuery->where('type', 'container_hosting')
                    ->where('container_template_id', $language->id);
            }

            $products = $this->catalogService->mapProductsForTechstackDisplay(
                $user,
                $productsQuery->orderBy('order')->get(),
                $database?->id,
            );
        }

        if ($products->isEmpty()) {
            $message = $this->catalogService->isResellerCustomer($user)
                ? $this->catalogService->techstackEmptyMessage($user, $language, $routing)
                : 'No hosting plans are available for this tech stack.';

            return back()->with('error', $message);
        }

        // Store selection in session temporarily
        $techstackData = [
            'language_id' => $language->id,
            'language_name' => $language->name,
            'hosting_type' => $routing['hosting_type'],
        ];
        if ($request->deployment_platform) {
            $techstackData['deployment_platform'] = $request->deployment_platform;
        }
        if ($database) {
            $techstackData['database_id'] = $database->id;
            $techstackData['database_name'] = $database->name;
        }
        session(['selected_techstack' => $techstackData]);

        $cartCount = count(session('cart', []));

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('customer.confirm-techstack', [
            'language' => $language,
            'database' => $database,
            'routing' => $routing,
            'products' => $products,
            'isResellerCustomer' => $this->catalogService->isResellerCustomer($user),
            'cartCount' => $cartCount,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
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

        $products = $query->orderBy('category')->orderBy('order')->get();

        // Group products by type
        $groupedProducts = $products->groupBy('type');

        // Get all available types for filtering
        $allTypes = Product::where('is_active', true)
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
}
