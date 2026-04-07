<?php

namespace App\Http\Controllers\Customer;

use App\Models\Product;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Services\TechStackRoutingService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ServiceBrowserController extends Controller
{
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
    public function getAvailableDatabases($languageId)
    {
        $language = ContainerTemplate::findOrFail($languageId);
        $databases = TechStackRoutingService::getAvailableDatabasesForLanguage($language);

        return response()->json([
            'databases' => $databases->map(fn($db) => [
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
            'languages' => $languages->map(fn($lang) => [
                'id' => $lang->id,
                'name' => $lang->name,
                'slug' => $lang->slug,
                'versions' => $lang->versions ?? [],
            ]),
        ]);
    }

    /**
     * Confirm techstack and show products
     */
    public function confirmTechstack(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:container_templates,id',
            'database_id' => 'required|exists:database_templates,id',
        ]);

        $language = ContainerTemplate::findOrFail($request->language_id);
        $database = DatabaseTemplate::findOrFail($request->database_id);

        // Validate combination
        if (!TechStackRoutingService::isValidCombination($language, $database)) {
            return back()->with('error', 'Invalid techstack combination selected');
        }

        // Determine hosting type and get product
        $routing = TechStackRoutingService::determineHostingType($language, $database);
        $product = TechStackRoutingService::getRecommendedProduct($language, $database);

        if (!$product) {
            return back()->with('error', 'No hosting plan available for this techstack');
        }

        // Store selection in session temporarily
        session([
            'selected_techstack' => [
                'language_id' => $language->id,
                'language_name' => $language->name,
                'database_id' => $database->id,
                'database_name' => $database->name,
                'hosting_type' => $routing['hosting_type'],
            ]
        ]);

        $cartCount = count(session('cart', []));

        return view('customer.confirm-techstack', [
            'language' => $language,
            'database' => $database,
            'routing' => $routing,
            'product' => $product,
            'cartCount' => $cartCount,
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
        ]);

        $query = Product::where('type', $request->type)
            ->where('is_active', true);

        if ($request->template_id) {
            $query->where('container_template_id', $request->template_id);
        }

        $products = $query->orderBy('order')->get();

        return response()->json([
            'products' => $products->map(fn($p) => [
                'id' => $p->id,
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
