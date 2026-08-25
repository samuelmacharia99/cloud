<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminDomainRequest;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\DomainRenewalOrder;
use App\Models\User;
use App\Services\Admin\AdminDomainUpdateService;
use App\Services\DomainRegistrantContactService;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\Registrar\CosmotownInventorySyncService;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DomainController extends Controller
{
    public function index(Request $request)
    {
        $query = Domain::with('user', 'domainExtension', 'reseller');

        // Search by domain name
        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");

                // Support searching by full domain (e.g. "example.co.ke")
                // while data is stored as separate name + extension columns.
                if (str_contains($search, '.')) {
                    $parts = explode('.', ltrim($search, '.'), 2);
                    $sld = $parts[0] ?? '';
                    $tld = $parts[1] ?? '';

                    if ($sld !== '' && $tld !== '') {
                        $q->orWhere(function ($domainQ) use ($sld, $tld) {
                            $domainQ->where('name', 'like', "%{$sld}%")
                                ->where('extension', 'like', '%.'.$tld.'%');
                        });
                    }
                }
            });
        }

        // Filter by extension
        if ($request->filled('extension')) {
            $query->where('extension', $request->extension);
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by registrar
        if ($request->filled('registrar')) {
            $query->where('registrar', $request->registrar);
        }

        // Filter by owner (user search)
        if ($request->filled('owner')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->owner}%")
                    ->orWhere('email', 'like', "%{$request->owner}%");
            });
        }

        // Filter by expiry date range
        if ($request->filled('expires_from')) {
            $query->whereDate('expires_at', '>=', $request->expires_from);
        }
        if ($request->filled('expires_to')) {
            $query->whereDate('expires_at', '<=', $request->expires_to);
        }

        // Filter by registration date range
        if ($request->filled('registered_from')) {
            $query->whereDate('registered_at', '>=', $request->registered_from);
        }
        if ($request->filled('registered_to')) {
            $query->whereDate('registered_at', '<=', $request->registered_to);
        }

        // Filter by days until expiry
        if ($request->filled('expiry_warning')) {
            $days = (int) $request->expiry_warning;
            $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
        }

        $domains = $query->latest()->paginate(20)->withQueryString();

        // Get distinct values for filter dropdowns
        $extensions = DomainExtension::where('enabled', true)->orderBy('extension')->pluck('extension');
        $statuses = ['active', 'expired', 'suspended'];
        $registrars = Domain::distinct()->pluck('registrar')->filter()->sort();
        $cosmotownRegistrar = app(CosmotownInventorySyncService::class)->activeCosmotownRegistrar();

        return view('admin.domains.index', compact('domains', 'extensions', 'statuses', 'registrars', 'cosmotownRegistrar'));
    }

    public function pricing(Request $request)
    {
        $extensions = DomainExtension::with('pricing')->orderBy('extension')->get();
        $periods = [1, 2, 3, 5, 10];

        // If showing pricing for a specific extension
        $selectedExtension = null;
        if ($request->filled('extension_id')) {
            $selectedExtension = DomainExtension::findOrFail($request->extension_id);
        }

        return view('admin.domains.pricing', compact('extensions', 'periods', 'selectedExtension'));
    }

    public function syncCosmotownInventory(CosmotownInventorySyncService $sync)
    {
        $this->authorize('viewAny', Domain::class);

        $result = $sync->sync();
        $key = ($result['success'] || $result['updated'] > 0) ? 'success' : 'error';

        return redirect()
            ->route('admin.domains.index')
            ->with($key, $result['message']);
    }

    public function storePricing(Request $request)
    {
        $validated = $request->validate([
            'domain_extension_id' => 'required|exists:domain_extensions,id',
            'period_years' => 'required|in:1,2,3,5,10',
            'retail_price' => 'required|numeric|min:0',
            'retail_renewal_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'required|numeric|min:0',
            'wholesale_renewal_price' => 'nullable|numeric|min:0',
        ]);

        // Save retail pricing (domains don't have setup fees)
        DomainPricing::updateOrCreate(
            [
                'domain_extension_id' => $validated['domain_extension_id'],
                'period_years' => $validated['period_years'],
                'tier' => 'retail',
            ],
            [
                'price' => $validated['retail_price'],
                'renewal_price' => $validated['retail_renewal_price'] ?? $validated['retail_price'],
                'setup_fee' => 0,
                'enabled' => true,
            ]
        );

        // Save wholesale pricing (domains don't have setup fees)
        DomainPricing::updateOrCreate(
            [
                'domain_extension_id' => $validated['domain_extension_id'],
                'period_years' => $validated['period_years'],
                'tier' => 'wholesale',
            ],
            [
                'price' => $validated['wholesale_price'],
                'renewal_price' => $validated['wholesale_renewal_price'] ?? $validated['wholesale_price'],
                'setup_fee' => 0,
                'enabled' => true,
            ]
        );

        return redirect()->route('admin.domains.pricing', ['extension_id' => $validated['domain_extension_id']])
            ->with('success', 'Pricing updated successfully.');
    }

    public function storeExtension(Request $request)
    {
        $validated = $request->validate([
            'extension' => 'required|string|unique:domain_extensions,extension',
            'description' => 'nullable|string',
            'registrar' => 'required|string',
            'registration_price' => 'required|numeric|min:0',
            'renewal_price' => 'required|numeric|min:0',
            'registration_price_wholesale' => 'required|numeric|min:0',
            'renewal_price_wholesale' => 'required|numeric|min:0',
            'transfer_price' => 'nullable|numeric|min:0',
            'dns_management' => 'nullable|boolean',
            'auto_renewal' => 'nullable|boolean',
        ]);

        // Set checkbox values and defaults
        $validated['dns_management'] = $request->has('dns_management');
        $validated['auto_renewal'] = $request->has('auto_renewal');
        $validated['enabled'] = true;
        $validated['transfer_price'] = $validated['transfer_price'] ?? 0;

        // Create the domain extension
        $extension = DomainExtension::create($validated);

        // Auto-populate pricing for each period
        $periods = [1, 2, 3, 5, 10];
        foreach ($periods as $period) {
            // Retail pricing
            DomainPricing::create([
                'domain_extension_id' => $extension->id,
                'period_years' => $period,
                'tier' => 'retail',
                'price' => $validated['registration_price'] * $period,
                'renewal_price' => $validated['renewal_price'] * $period,
                'setup_fee' => 0,
                'renewal_setup_fee' => 0,
                'enabled' => true,
            ]);

            // Wholesale pricing
            DomainPricing::create([
                'domain_extension_id' => $extension->id,
                'period_years' => $period,
                'tier' => 'wholesale',
                'price' => $validated['registration_price_wholesale'] * $period,
                'renewal_price' => $validated['renewal_price_wholesale'] * $period,
                'setup_fee' => 0,
                'renewal_setup_fee' => 0,
                'enabled' => true,
            ]);
        }

        return redirect()->route('admin.domains.pricing')
            ->with('success', 'Domain extension added successfully with pricing for all periods.');
    }

    public function show(Domain $domain)
    {
        $registry = app(RegistrarFulfillmentService::class)->refreshLiveRegistryDetails($domain);

        $domain->load('user', 'domainExtension', 'dnsZones');

        $nameservers = $registry['nameservers'];
        $eppCode = $registry['epp_code'];
        $registrant = app(DomainRegistrantContactService::class)->normalize(
            $registry['registrant'] !== []
                ? $registry['registrant']
                : ($domain->user ? app(DomainRegistrantContactService::class)->fromUser($domain->user) : [])
        );

        return view('admin.domains.show', compact('domain', 'nameservers', 'eppCode', 'registry', 'registrant'));
    }

    public function updateNameservers(Request $request, Domain $domain)
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'nameserver_1' => 'required|string|min:3|max:253',
            'nameserver_2' => 'required|string|min:3|max:253',
            'nameserver_3' => 'nullable|string|min:3|max:253',
            'nameserver_4' => 'nullable|string|min:3|max:253',
        ]);

        $nameservers = [
            'ns1' => $validated['nameserver_1'],
            'ns2' => $validated['nameserver_2'],
            'ns3' => $validated['nameserver_3'] ?? null,
            'ns4' => $validated['nameserver_4'] ?? null,
        ];

        $result = app(RegistrarFulfillmentService::class)->updateDomainNameservers($domain, $nameservers);

        if (! $result['success']) {
            return back()->with('error', $result['message'])->withInput();
        }

        $domain->update([
            'nameserver_1' => $validated['nameserver_1'],
            'nameserver_2' => $validated['nameserver_2'],
            'nameserver_3' => $validated['nameserver_3'] ?? null,
            'nameserver_4' => $validated['nameserver_4'] ?? null,
        ]);

        $flashKey = $result['pushed'] ? 'success' : 'warning';

        return back()->with($flashKey, $result['message']);
    }

    public function updateRegistrant(Request $request, Domain $domain)
    {
        $this->authorize('update', $domain);

        $contacts = app(DomainRegistrantContactService::class);
        $validated = $request->validate($contacts->rules('registrant'));
        $result = app(RegistrarFulfillmentService::class)->updateDomainRegistrant($domain, $validated['registrant']);

        if (! $result['success']) {
            return back()->with('error', $result['message'])->withInput();
        }

        return back()->with($result['pushed'] ? 'success' : 'warning', $result['message']);
    }

    public function updateRegistryOptions(Request $request, Domain $domain)
    {
        $this->authorize('update', $domain);

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

        $result = app(RegistrarFulfillmentService::class)->updateDomainRegistryOptions(
            $domain,
            $request->boolean('registry_locked'),
            $request->boolean('whois_privacy'),
        );

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    public function unmatchedCosmotown(CosmotownInventorySyncService $sync)
    {
        $this->authorize('viewAny', Domain::class);

        try {
            $unmatched = $sync->unmatchedAtRegistrar();
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('admin.domains.index')->with('error', $e->getMessage());
        }

        $customers = User::query()
            ->where('is_admin', false)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);

        return view('admin.domains.cosmotown-unmatched', compact('unmatched', 'customers'));
    }

    public function importCosmotown(Request $request, CosmotownInventorySyncService $sync)
    {
        $this->authorize('create', Domain::class);

        $validated = $request->validate([
            'fqdn' => 'required|string|max:253',
            'user_id' => 'required|integer|exists:users,id',
            'confirm_no_invoice' => 'accepted',
        ], [
            'confirm_no_invoice.accepted' => 'Confirm this domain is already at Cosmotown and should be attached without creating an invoice.',
        ]);

        $owner = User::query()->findOrFail($validated['user_id']);

        try {
            $domain = $sync->importToCustomer(
                $validated['fqdn'],
                $owner,
                $request->user(),
                $request->boolean('confirm_no_invoice'),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('admin.domains.show', $domain)
            ->with('success', $domain->fqdn().' is now on '.$owner->email.' with live Cosmotown expiry. No invoice was created.');
    }

    public function edit(Domain $domain)
    {
        $extensions = DomainExtension::query()
            ->orderBy('extension')
            ->pluck('extension')
            ->all();

        if ($domain->extension && ! in_array($domain->extension, $extensions, true)) {
            $extensions[] = $domain->extension;
            sort($extensions);
        }

        return view('admin.domains.edit', compact('domain', 'extensions'));
    }

    public function update(UpdateAdminDomainRequest $request, Domain $domain, AdminDomainUpdateService $updates)
    {
        $validated = $request->validated();
        $validated['auto_renew'] = $request->boolean('auto_renew');

        try {
            $updates->update($domain, $validated);
        } catch (\Throwable $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update domain: '.$e->getMessage());
        }

        return redirect()->route('admin.domains.show', $domain)
            ->with('success', 'Domain updated successfully.');
    }

    public function destroy(Domain $domain)
    {
        $domainName = $domain->name;
        $domain->delete();

        return redirect()->route('admin.domains.index')
            ->with('success', "Domain '{$domainName}' has been deleted successfully.");
    }

    public function generateInvoice(Domain $domain)
    {
        try {
            // Check if domain has extension pricing
            if (! $domain->domainExtension) {
                return redirect()->route('admin.domains.show', $domain)
                    ->with('error', 'Domain extension not found. Cannot generate invoice.');
            }

            // Check if domain has an owner
            if (! $domain->user) {
                return redirect()->route('admin.domains.show', $domain)
                    ->with('error', 'Domain owner not found. Cannot generate invoice.');
            }

            // Get renewal price (wholesale for resellers, retail for customers)
            $pricing = $domain->domainExtension->getPricingForUser($domain->user, 1);
            if (! $pricing || ! $pricing->renewal_price) {
                return redirect()->route('admin.domains.show', $domain)
                    ->with('error', 'No pricing available for renewal. Please configure domain extension pricing.');
            }

            // Check if invoice already exists for this domain
            $existingOrder = $domain->renewalOrders()
                ->whereIn('status', ['pending', 'invoiced'])
                ->where('created_at', '>=', now()->subDays(7))
                ->first();

            if ($existingOrder) {
                return redirect()->route('admin.domains.show', $domain)
                    ->with('error', 'A renewal invoice already exists for this domain. Please cancel it first if you want to create a new one.');
            }

            // Create renewal order and invoice
            $renewalOrder = DomainRenewalOrder::create([
                'domain_id' => $domain->id,
                'user_id' => $domain->user_id,
                'years' => 1,
                'amount' => $pricing->renewal_price,
                'status' => 'pending',
                'expires_at' => now()->addDays(10),
            ]);

            $renewalService = app(DomainRenewalService::class);
            $invoice = $renewalService->createInvoice($renewalOrder);

            // Send notification
            app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);

            return redirect()->route('admin.domains.show', $domain)
                ->with('success', "Invoice {$invoice->invoice_number} generated successfully. Customer notified via email and SMS.");
        } catch (\Exception $e) {
            Log::error('Failed to generate domain invoice', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.domains.show', $domain)
                ->with('error', 'Failed to generate invoice: '.$e->getMessage());
        }
    }
}
