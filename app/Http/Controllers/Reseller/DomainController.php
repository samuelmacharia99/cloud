<?php

namespace App\Http\Controllers\Reseller;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reseller\Concerns\ResellerDomainAccess;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainAutoRenewService;
use App\Services\DomainAvailabilityService;
use App\Services\DomainRegistrantContactService;
use App\Services\DomainRenewalService;
use App\Services\Registrar\RegistrarFulfillmentService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerDomainOrderService;
use App\Services\ResellerDomainTransferService;
use App\Services\ResellerScopeService;
use App\Support\ResellerCartContext;
use App\Support\SessionCart;
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
        protected ResellerScopeService $scope,
        protected ResellerCustomerCatalogService $catalog,
        protected RegistrarFulfillmentService $registrarFulfillment,
    ) {}

    /**
     * List all domains owned by the reseller
     */
    public function index(Request $request)
    {
        $resellerId = auth()->id();

        $managedCustomerIds = User::query()
            ->where('reseller_id', $resellerId)
            ->pluck('id');

        $serviceCustomerIds = Service::where('reseller_id', $resellerId)
            ->distinct()
            ->pluck('user_id');

        $customerIds = $managedCustomerIds->merge($serviceCustomerIds)->unique();

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
                : (app(DomainAvailabilityService::class)->registrationBlockMessage($check) ?? 'Domain is already taken.'),
            'blocked_reason' => $check['blocked_reason'] ?? null,
        ]);
    }

    /**
     * Get wholesale pricing for a domain extension
     */
    public function getPricing(DomainExtension $extension, Request $request)
    {
        $reseller = auth()->user();
        $useRetail = $request->boolean('retail') || ResellerCartContext::isCustomerMode();

        if ($request->input('type') === 'transfer') {
            $orderService = app(ResellerDomainOrderService::class);
            $wholesaleLineTotal = $orderService->resolveTransferWholesaleAmount($extension->extension, 0);

            $lineTotal = $wholesaleLineTotal;
            if ($useRetail && ResellerCartContext::isCustomerMode()) {
                $customer = User::find(ResellerCartContext::customerId());
                if ($customer) {
                    $lineTotal = app(ResellerCustomerCatalogService::class)
                        ->domainTransferPrice($customer, $extension);
                }
            }

            return response()->json([
                'price' => $lineTotal,
                'line_total' => $lineTotal,
                'wholesale_price' => $wholesaleLineTotal,
                'wholesale_line_total' => $wholesaleLineTotal,
                'retail' => $useRetail,
                'renewal_price' => 0,
                'currency' => 'KES',
                'available' => $wholesaleLineTotal > 0,
                'type' => 'transfer',
            ]);
        }

        $period = max(1, (int) $request->get('period', 1));

        $wholesaleLineTotal = $this->customerOrders->wholesaleAmountForExtension($extension, $period);

        $lineTotal = $useRetail && $reseller
            ? $this->customerOrders->retailAmountForExtension($reseller, $extension, $period, $wholesaleLineTotal)
            : $wholesaleLineTotal;

        $unitPrice = round($lineTotal / $period, 2);
        $wholesaleUnitPrice = round($wholesaleLineTotal / $period, 2);

        $wholesalePricing = $extension->getWholesalePricing($period);
        $renewalPrice = $wholesalePricing
            ? (float) ($wholesalePricing->renewal_price ?? $wholesalePricing->price)
            : 0;

        if ($useRetail && ResellerCartContext::isCustomerMode()) {
            $customer = User::find(ResellerCartContext::customerId());
            if ($customer) {
                $renewalRetail = app(ResellerCustomerCatalogService::class)
                    ->domainRenewalPrice($customer, $extension, $period);
                if ($renewalRetail !== null) {
                    $renewalPrice = $renewalRetail;
                }
            }
        }

        return response()->json([
            'price' => $unitPrice,
            'line_total' => $lineTotal,
            'wholesale_price' => $wholesaleUnitPrice,
            'wholesale_line_total' => $wholesaleLineTotal,
            'retail' => $useRetail,
            'renewal_price' => $renewalPrice,
            'currency' => 'KES',
            'available' => $wholesaleLineTotal > 0,
            'type' => 'registration',
        ]);
    }

    public function show(Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $registry = $this->registrarFulfillment->refreshLiveRegistryDetails($domain);

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

        $nameservers = $registry['nameservers'];
        $eppCode = $registry['epp_code'];
        $contacts = app(DomainRegistrantContactService::class);
        $registrant = $contacts->normalize(
            $registry['registrant'] !== []
                ? $registry['registrant']
                : ($domain->user ? $contacts->fromUser($domain->user) : [])
        );

        return view('reseller.domains.show', compact(
            'domain',
            'transferTargets',
            'dnsRecords',
            'nameservers',
            'eppCode',
            'registry',
            'registrant',
        ));
    }

    public function toggleAutoRenew(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $validated = $request->validate([
            'auto_renew' => 'required|boolean',
        ]);

        $enabled = (bool) $validated['auto_renew'];
        $payer = $domain->user;
        $autoRenew = app(DomainAutoRenewService::class);

        try {
            $autoRenew->setEnabled($domain, $enabled, $request->user());
        } catch (InsufficientFundsException $e) {
            $label = $payer ? $autoRenew->prepaidLabel($payer) : 'prepaid balance';

            return back()->with(
                'error',
                "Auto-renew needs enough {$label} to cover this domain when it expires. {$e->getMessage()}. Top up first."
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $enabled
                ? 'Auto-renew is on. Keep enough prepaid balance for this domain before expiry.'
                : 'Auto-renew is off.'
        );
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

        $nameservers = [
            'ns1' => $validated['nameserver_1'],
            'ns2' => $validated['nameserver_2'] ?? null,
            'ns3' => $validated['nameserver_3'] ?? null,
            'ns4' => $validated['nameserver_4'] ?? null,
        ];

        $result = $this->registrarFulfillment->updateDomainNameservers($domain, $nameservers);

        if (! $result['success']) {
            return back()->with('error', $this->registrarFulfillment->concealProviderMessage($result['message']))->withInput();
        }

        $domain->update([
            'nameserver_1' => $validated['nameserver_1'],
            'nameserver_2' => $validated['nameserver_2'] ?? null,
            'nameserver_3' => $validated['nameserver_3'] ?? null,
            'nameserver_4' => $validated['nameserver_4'] ?? null,
        ]);

        $flashKey = $result['pushed'] ? 'success' : 'warning';

        return back()->with($flashKey, $this->registrarFulfillment->concealProviderMessage($result['message']));
    }

    public function updateRegistrant(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $contacts = app(DomainRegistrantContactService::class);
        $validated = $request->validate($contacts->rules('registrant'));
        $result = $this->registrarFulfillment->updateDomainRegistrant($domain, $validated['registrant']);

        if (! $result['success']) {
            return back()->with('error', $this->registrarFulfillment->concealProviderMessage($result['message']))->withInput();
        }

        return back()->with(
            $result['pushed'] ? 'success' : 'warning',
            $this->registrarFulfillment->concealProviderMessage($result['message'])
        );
    }

    public function updateRegistryOptions(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $request->validate([
            'registry_locked' => 'required|boolean',
            'whois_privacy' => 'required|boolean',
        ]);

        if ($domain->registry_locked && ! $request->boolean('registry_locked')) {
            $request->validate([
                'confirm_unlock' => 'accepted',
            ], [
                'confirm_unlock.accepted' => 'Confirm that unlocking this domain allows a transfer to start with the EPP code.',
            ]);
        }

        $result = $this->registrarFulfillment->updateDomainRegistryOptions(
            $domain,
            $request->boolean('registry_locked'),
            $request->boolean('whois_privacy'),
        );

        if (! $result['success']) {
            return back()->with('error', $this->registrarFulfillment->concealProviderMessage($result['message']));
        }

        return back()->with('success', $this->registrarFulfillment->concealProviderMessage($result['message']));
    }

    public function initiateTransfer(Request $request, Domain $domain)
    {
        $this->assertResellerCanManageDomain($domain);

        $reseller = auth()->user();

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
            $this->domainTransfer->transferBetweenOwnedCustomers($domain, $fromCustomer, $toCustomer, $reseller);

            return back()->with('success', "Domain transferred to {$toCustomer->name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not transfer domain: '.$e->getMessage());
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
            $reseller = auth()->user();
            $billingCustomer = $this->resolveRenewalBillingCustomer($domain, $reseller);
            $payer = $billingCustomer ?? $reseller;
            $existingInvoice = $this->renewalService->openRenewalInvoiceFor($domain, $payer);

            if ($existingInvoice) {
                return response()->json([
                    'success' => true,
                    'reused_invoice' => true,
                    'message' => 'This domain already has an open renewal invoice.',
                    'redirect' => $billingCustomer
                        ? route('reseller.customer-invoices.show', $existingInvoice)
                        : route('reseller.invoices.show', $existingInvoice),
                ]);
            }

            $wholesaleAmount = $this->renewalService->wholesaleRenewalAmount($domain, $years);
            $cart = session()->get(CartController::CART_KEY, []);

            foreach ($cart as $item) {
                if (($item['type'] ?? 'domain') === 'domain_renewal' && (int) ($item['domain_id'] ?? 0) === $domain->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This domain is already in your cart for renewal.',
                    ], 422);
                }
            }

            $cartItem = [
                'type' => 'domain_renewal',
                'domain_id' => $domain->id,
                'domain' => $domain->name,
                'extension' => $domain->extension,
                'years' => $years,
                'wholesale_total' => $wholesaleAmount,
                'added_at' => now()->toIso8601String(),
            ];

            if (ResellerCartContext::isCustomerMode() && ! $billingCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select a valid customer for whitelabel checkout first.',
                ], 422);
            }

            if ($billingCustomer) {
                if ((int) $domain->user_id !== (int) $billingCustomer->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This domain does not belong to the selected customer. Switch cart billing mode or choose another domain.',
                    ], 422);
                }

                $extension = $domain->domainExtension;
                if (! $extension) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Domain extension not configured.',
                    ], 422);
                }

                $retailAmount = $this->catalog->domainRenewalPrice($billingCustomer, $extension, $years);
                if ($retailAmount === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Renewal pricing is not configured for this extension and period.',
                    ], 422);
                }

                $cartItem['price'] = $retailAmount;
                $cartItem['retail_total'] = $retailAmount;
                $cartItem['billing_customer_id'] = $billingCustomer->id;
            } else {
                $cartItem['price'] = $wholesaleAmount;
            }

            $key = SessionCart::newLineKey('renew');
            $cart[$key] = $cartItem;

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

    private function resolveRenewalBillingCustomer(Domain $domain, User $reseller): ?User
    {
        if (ResellerCartContext::isCustomerMode()) {
            $customer = User::find(ResellerCartContext::customerId());

            return ($customer && $this->scope->ownsCustomer($reseller, $customer)) ? $customer : null;
        }

        $owner = $domain->user;
        if ($owner && (int) $owner->id !== (int) $reseller->id && $this->scope->ownsCustomer($reseller, $owner)) {
            return $owner;
        }

        return null;
    }
}
