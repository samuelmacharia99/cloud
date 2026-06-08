<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reseller\Concerns\ResellerDomainAccess;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use App\Services\DomainRenewalService;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerDomainTransferService;
use App\Support\ResellerCartContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    use ResellerDomainAccess;

    public function __construct(
        protected DomainRenewalService $renewalService,
        protected ResellerCustomerOrderService $customerOrders,
        protected DomainAvailabilityService $availability,
        protected ResellerDomainTransferService $domainTransfer,
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
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(15);

        $domains->getCollection()->each->concealUpstreamProviderDetails();

        // Get enabled domain extensions with wholesale and reseller pricing
        $extensions = DomainExtension::with([
            'pricing' => fn ($q) => $q->where('tier', 'wholesale'),
            'resellerPricing' => fn ($q) => $q->where('reseller_id', $resellerId),
        ])
            ->where('enabled', true)
            ->orderBy('extension')
            ->get()
            ->each->concealUpstreamProviderDetails();

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
     * Check whether a domain name is available to register.
     */
    public function checkAvailability(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:253',
        ]);

        $allowedExtensions = DomainExtension::query()
            ->where('enabled', true)
            ->pluck('extension')
            ->all();

        $check = $this->availability->checkInput($validated['domain'], null, $allowedExtensions);

        if ($check === null) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a valid domain with a supported extension.',
                'available' => false,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'available' => $check['available'],
            'full_domain' => $check['full_domain'],
            'name' => $check['name'],
            'extension' => $check['extension'],
            'source' => $check['source'],
            'message' => $check['available']
                ? 'Domain is available for registration.'
                : 'Domain is already taken.',
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

    public function show(Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $domain->load(['user', 'dnsZones.records', 'pendingTransferRecipient']);
        $domain->concealUpstreamProviderDetails();

        $resellerId = auth()->id();
        $transferTargets = User::query()
            ->where('reseller_id', $resellerId)
            ->where('id', '!=', $domain->user_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $zone = $domain->dnsZones->first();
        $dnsRecords = $zone?->records()->orderBy('type')->orderBy('name')->get() ?? collect();

        return view('reseller.domains.show', compact('domain', 'transferTargets', 'dnsRecords'));
    }

    public function updateNameservers(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $validated = $request->validate([
            'nameserver_1' => 'required|string|min:3|max:253',
            'nameserver_2' => 'nullable|string|min:3|max:253',
            'nameserver_3' => 'nullable|string|min:3|max:253',
            'nameserver_4' => 'nullable|string|min:3|max:253',
        ]);

        $domain->update([
            'nameserver_1' => $validated['nameserver_1'],
            'nameserver_2' => $validated['nameserver_2'] ?? null,
            'nameserver_3' => $validated['nameserver_3'] ?? null,
            'nameserver_4' => $validated['nameserver_4'] ?? null,
        ]);

        return back()->with('success', 'Nameservers updated. Changes may take up to 48 hours to propagate.');
    }

    public function initiateTransfer(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $reseller = auth()->user();

        if ($domain->pending_transfer_to_user_id) {
            return back()->with('error', 'A transfer is already pending approval for this domain.');
        }

        $validated = $request->validate([
            'to_customer_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('reseller_id', $reseller->id)
                    ->where('id', '!=', $domain->user_id)),
            ],
        ]);

        $toCustomer = User::query()->findOrFail($validated['to_customer_id']);
        $fromCustomer = $domain->user;

        if (! $fromCustomer) {
            return back()->with('error', 'Domain owner not found.');
        }

        try {
            $this->domainTransfer->initiate($domain, $fromCustomer, $toCustomer, $reseller);

            return back()->with('success', "Transfer request sent to {$toCustomer->name}. They must approve before ownership changes.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not initiate transfer: '.$e->getMessage());
        }
    }

    public function destroy(Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $fullName = $domain->name.$domain->extension;
        $domain->delete();

        return redirect()->route('reseller.domains.index')
            ->with('success', "Domain {$fullName} has been removed from your account.");
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
