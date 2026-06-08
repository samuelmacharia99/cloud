<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reseller\Concerns\ResellerDomainAccess;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainRenewalService;
use App\Services\ResellerCustomerOrderService;
use App\Support\ResellerCartContext;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    use ResellerDomainAccess;

    public function __construct(
        protected DomainRenewalService $renewalService,
        protected ResellerCustomerOrderService $customerOrders,
    ) {}

    /**
     * List all domains owned by the reseller
     */
    public function index(Request $request)
    {
        $resellerId = auth()->id();

        // Get customer IDs managed by this reseller (via services)
        $customerIds = Service::where('reseller_id', $resellerId)
            ->distinct()
            ->pluck('user_id');

        // Get all domains: those owned by the reseller or their managed customers
        // Also include domains where reseller_id = $resellerId (manually added domains)
        $domains = Domain::where(function ($q) use ($resellerId, $customerIds) {
            $q->where('user_id', $resellerId)
                ->orWhereIn('user_id', $customerIds)
                ->orWhere('reseller_id', $resellerId);
        })
            ->with('domainExtension', 'user')
            ->orderByDesc('created_at')
            ->paginate(15);

        // Get enabled domain extensions with wholesale and reseller pricing
        $extensions = DomainExtension::with([
            'pricing' => fn ($q) => $q->where('tier', 'wholesale'),
            'resellerPricing' => fn ($q) => $q->where('reseller_id', $resellerId),
        ])
            ->where('enabled', true)
            ->orderBy('extension')
            ->get();

        // Default period for pricing display
        $selectedPeriod = $request->get('period', 1);

        $cartCustomers = User::query()
            ->where('reseller_id', $resellerId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        if ($request->filled('customer')) {
            $customer = $cartCustomers->firstWhere('id', (int) $request->customer);
            if ($customer) {
                ResellerCartContext::setCustomer($customer->id);
                ResellerCartContext::setCustomerName($customer->name);
            }
        }

        return view('reseller.domains.index', [
            'domains' => $domains,
            'extensions' => $extensions,
            'knownExtensions' => $extensions->pluck('extension')->values(),
            'selectedPeriod' => $selectedPeriod,
            'periods' => [1, 2, 3, 5, 10],
            'cartContext' => ResellerCartContext::summary(),
            'cartCustomers' => $cartCustomers,
        ]);
    }

    /**
     * Get wholesale pricing for a domain extension
     */
    public function getPricing(DomainExtension $extension, Request $request)
    {
        $period = max(1, (int) $request->get('period', 1));
        $reseller = auth()->user();

        $wholesaleLineTotal = $this->customerOrders->wholesaleAmountForExtension($extension, $period);
        $useRetail = $request->boolean('retail') || ResellerCartContext::isCustomerMode();

        $lineTotal = $useRetail && $reseller
            ? $this->customerOrders->retailAmountForExtension($reseller, $extension, $period, $wholesaleLineTotal)
            : $wholesaleLineTotal;

        $unitPrice = round($lineTotal / $period, 2);
        $wholesaleUnitPrice = round($wholesaleLineTotal / $period, 2);

        $wholesalePricing = $extension->getWholesalePricing($period);
        $renewalPrice = $wholesalePricing
            ? (float) ($wholesalePricing->renewal_price ?? $wholesalePricing->price)
            : 0;

        return response()->json([
            'price' => $unitPrice,
            'line_total' => $lineTotal,
            'wholesale_price' => $wholesaleUnitPrice,
            'wholesale_line_total' => $wholesaleLineTotal,
            'retail' => $useRetail,
            'renewal_price' => $renewalPrice,
            'currency' => 'KES',
            'available' => $wholesaleLineTotal > 0,
        ]);
    }

    public function addRenewalToCart(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $validated = $request->validate([
            'years' => 'required|integer|min:1|max:10',
        ]);

        try {
            $years = (int) $validated['years'];
            $amount = $this->renewalService->wholesaleRenewalAmount($domain, $years);
            $cart = session()->get(CartController::CART_KEY, []);

            foreach ($cart as $item) {
                if (($item['type'] ?? 'domain') === 'domain_renewal' && (int) ($item['domain_id'] ?? 0) === $domain->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This domain is already in your cart for renewal.',
                    ], 422);
                }
            }

            $key = uniqid('renew_', true);
            $cart[$key] = [
                'type' => 'domain_renewal',
                'domain_id' => $domain->id,
                'domain' => $domain->name,
                'extension' => $domain->extension,
                'years' => $years,
                'price' => $amount,
                'added_at' => now()->toIso8601String(),
            ];

            session()->put(CartController::CART_KEY, $cart);

            return response()->json([
                'success' => true,
                'item_count' => count($cart),
                'message' => 'Domain renewal added to cart',
                'redirect' => route('reseller.cart.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
