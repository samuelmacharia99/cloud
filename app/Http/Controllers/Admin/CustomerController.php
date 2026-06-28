<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\DirectAdminPackage;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Services\AdminAccountWelcomeService;
use App\Services\AdminActivityService;
use App\Services\CreditService;
use App\Services\CustomerResellerTransferService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\Provisioning\DirectAdminSetupService;
use App\Services\ResellerDirectAdminService;
use App\Services\TaxService;
use App\Services\RegistrationContextService;
use App\Services\UserCurrencyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('is_admin', false)->where('is_reseller', false)->latest();

        // Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('company', 'like', "%{$request->search}%");
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'unverified') {
                $query->whereNull('email_verified_at');
            } else {
                $query->where('status', $request->status);
            }
        }

        // Account type filter
        if ($request->filled('type')) {
            if ($request->type === 'company') {
                $query->whereNotNull('company')->where('company', '!=', '');
            } elseif ($request->type === 'individual') {
                $query->where(function ($q) {
                    $q->whereNull('company')->orWhere('company', '');
                });
            }
        }

        // Owner filter (platform vs reseller-managed)
        if ($request->filled('owner') && $request->owner !== 'all') {
            if ($request->owner === 'platform') {
                $query->whereNull('reseller_id');
            } elseif ($request->owner === 'reseller') {
                $query->whereNotNull('reseller_id');
            }
        }

        if ($request->filled('reseller_id')) {
            $query->where('reseller_id', $request->reseller_id);
        }

        $customers = $query
            ->with('reseller:id,name,email')
            ->withCount('services', 'invoices')
            ->paginate(15)
            ->withQueryString();

        $resellers = User::query()
            ->where('is_reseller', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.customers.index', [
            'customers' => $customers,
            'resellers' => $resellers,
            'platformRegistrationUrl' => app(RegistrationContextService::class)->platformRegistrationUrl(),
        ]);
    }

    public function create()
    {
        return view('admin.customers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'phone' => 'nullable|string',
            'company' => 'nullable|string',
            'country' => ['required', 'string', 'size:2', new ValidCountryCode],
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,suspended,inactive',
            'send_welcome_email' => 'sometimes|boolean',
        ]);

        $plainPassword = $validated['password'];
        $sendWelcomeEmail = $request->boolean('send_welcome_email');
        unset($validated['send_welcome_email']);

        $user = User::create($validated);
        app(UserCurrencyService::class)->syncFromCountry($user, true);

        $flash = 'Customer created successfully.';

        if ($sendWelcomeEmail) {
            try {
                app(AdminAccountWelcomeService::class)->send($user, $plainPassword, 'customer');
                $flash .= ' Welcome email sent.';
            } catch (\Throwable $e) {
                $flash .= ' Welcome email could not be sent: '.$e->getMessage();
            }
        }

        return redirect()->route('admin.customers.index')
            ->with('success', $flash);
    }

    public function show(User $customer)
    {
        if ($customer->is_reseller) {
            return redirect()->route('admin.resellers.show', $customer)
                ->with('info', 'This user is a reseller. Showing reseller profile.');
        }

        if ($customer->is_admin) {
            abort(404);
        }

        $customer->load(
            'reseller:id,name,email',
            'services.product',
            'invoices',
            'payments',
            'tickets',
            'domains'
        );

        $products = Product::with('directAdminPackage.node')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Prepare products for Alpine.js — include type so the modal can switch
        // its credential fields when a shared_hosting product is selected.
        $productsForJs = $products->map(function ($product) {
            $package = $product->directAdminPackage;
            $node = $package?->node;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'monthly_price' => $product->monthly_price,
                'yearly_price' => $product->yearly_price,
                'direct_admin_package' => $package ? [
                    'id' => $package->id,
                    'name' => $package->name,
                    'package_key' => $package->package_key,
                    'disk_quota' => $package->disk_quota,
                    'bandwidth_quota' => $package->bandwidth_quota,
                    'node' => $node ? [
                        'id' => $node->id,
                        'name' => $node->name,
                        'hostname' => $node->hostname,
                    ] : null,
                ] : null,
            ];
        })->values()->toArray();

        $servicesForJs = $customer->services->map(function ($service) {
            return [
                'id' => $service->id,
                'name' => $service->name,
                'product_id' => $service->product_id,
                'product_name' => $service->product?->name,
                'product_type' => $service->product?->type,
                'billing_cycle' => $service->billing_cycle ?? 'monthly',
                'custom_price' => $service->custom_price,
                'next_due_date' => $service->next_due_date?->format('Y-m-d') ?? '',
                'commenced_at' => $service->commenced_at?->format('Y-m-d') ?? '',
                'status' => $service->status->value,
            ];
        })->values()->toArray();

        // Get active DirectAdmin nodes for server selection in Add Service modal
        $daNodes = Node::where('type', 'directadmin')
            ->where('is_active', true)
            ->where('status', 'online')
            ->orderBy('name')
            ->get(['id', 'name', 'hostname', 'status']);

        $customerCredits = Credit::forUser($customer)
            ->latest()
            ->get();

        $creditAvailableBalance = CreditService::getAvailableBalance($customer);

        $customerCurrency = app(UserCurrencyService::class)->model($customer);
        $customerCurrencyCode = $customerCurrency->code;
        $customerCurrencySymbol = $customerCurrency->symbol ?? $customerCurrencyCode;
        $customerExchangeRate = $customerCurrencyCode === config('currency.base', 'KES')
            ? 1.0
            : (float) $customerCurrency->exchange_rate;

        return view('admin.customers.show', compact(
            'customer',
            'products',
            'productsForJs',
            'servicesForJs',
            'daNodes',
            'customerCredits',
            'creditAvailableBalance',
            'customerCurrencyCode',
            'customerCurrencySymbol',
            'customerExchangeRate',
        ));
    }

    public function addCredit(Request $request, User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        if ($customer->is_reseller) {
            return redirect()->route('admin.resellers.show', $customer)
                ->with('error', 'Use the reseller profile to manage this account.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'source' => 'required|in:admin,promotion,refund',
            'notes' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $credit = CreditService::createManualCredit(
            $customer,
            (float) $validated['amount'],
            $validated['notes'] ?? 'Manual credit',
            $validated['expires_at'] ? Carbon::parse($validated['expires_at']) : null,
        );

        $credit->update(['source' => $validated['source']]);

        AdminActivityService::log(
            'credit.issue',
            'Issued KES '.number_format($validated['amount'], 2)." credit to {$customer->name}",
            $credit,
        );

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
            ->with('success', 'KES '.number_format($validated['amount'], 2).' credit added successfully.');
    }

    public function removeCredit(Request $request, User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        if ($customer->is_reseller) {
            return redirect()->route('admin.resellers.show', $customer)
                ->with('error', 'Use the reseller profile to manage this account.');
        }

        $validated = $request->validate([
            'remove_amount' => 'required|numeric|min:0.01',
            'remove_notes' => 'required|string|min:5|max:500',
        ]);

        $available = CreditService::getAvailableBalance($customer);
        if ((float) $validated['remove_amount'] > $available) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
                ->withErrors([
                    'remove_amount' => 'Cannot remove more than the available balance (KES '.number_format($available, 2).').',
                ])
                ->withInput();
        }

        try {
            CreditService::deductFromUser(
                $customer,
                (float) $validated['remove_amount'],
                $validated['remove_notes'],
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
                ->with('error', $exception->getMessage())
                ->withInput();
        }

        AdminActivityService::log(
            'credit.deduct',
            'Removed KES '.number_format($validated['remove_amount'], 2)." credit from {$customer->name}",
            $customer,
        );

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
            ->with('success', 'KES '.number_format($validated['remove_amount'], 2).' credit removed successfully.');
    }

    public function revokeCredit(Request $request, User $customer, Credit $credit)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        if ($customer->is_reseller) {
            return redirect()->route('admin.resellers.show', $customer)
                ->with('error', 'Use the reseller profile to manage this account.');
        }

        if ($credit->user_id !== $customer->id) {
            abort(404);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'notes' => 'required|string|min:5|max:500',
        ]);

        $available = $credit->getAvailableBalance();
        if ($available <= 0) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
                ->with('error', 'This credit has no available balance to remove.');
        }

        $amount = isset($validated['amount']) ? (float) $validated['amount'] : $available;
        if ($amount > $available) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
                ->with('error', 'Cannot remove more than the available balance on this credit.');
        }

        try {
            CreditService::deductFromCredit($credit, $amount, $validated['notes']);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
                ->with('error', $exception->getMessage());
        }

        AdminActivityService::log(
            'credit.deduct',
            'Removed KES '.number_format($amount, 2)." from credit #{$credit->id} for {$customer->name}",
            $credit,
        );

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits'])
            ->with('success', 'KES '.number_format($amount, 2).' credit removed successfully.');
    }

    public function edit(User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        return view('admin.customers.edit', compact('customer'));
    }

    public function update(Request $request, User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$customer->id,
            'password' => 'nullable|min:8|confirmed',
            'phone' => 'nullable|string',
            'company' => 'nullable|string',
            'country' => ['required', 'string', 'size:2', new ValidCountryCode],
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,suspended,inactive',
        ]);

        // Only hash password if provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $customer->update($validated);

        if ($customer->wasChanged('country')) {
            app(UserCurrencyService::class)->syncFromCountry($customer->fresh(), true);
        }

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer updated successfully.');
    }

    public function impersonate(User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        AdminActivityService::log(
            'customer.impersonate',
            'Started impersonating customer '.$customer->name,
            $customer,
        );

        // Store the admin ID in session for later exit
        session(['impersonating' => auth()->id(), 'impersonating_user_id' => $customer->id]);

        // Log out the current admin and log in as the customer
        auth()->logout();
        auth()->loginUsingId($customer->id);

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing the dashboard as {$customer->name}.");
    }

    public function destroy(User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        $customerName = $customer->name;
        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', "Customer '{$customerName}' has been deleted successfully.");
    }

    public function exitImpersonation()
    {
        if (! session('impersonating')) {
            return redirect()->route('admin.customers.index');
        }

        $adminId = session('impersonating');

        // Verify that the stored ID belongs to an actual admin before restoring
        $admin = User::find($adminId);
        if (! $admin || ! $admin->is_admin) {
            // Potentially tampered session — clear everything and log out safely
            session()->forget(['impersonating', 'impersonating_user_id']);
            auth()->logout();
            abort(403, 'Invalid impersonation session');
        }

        // Clear impersonation session data
        session()->forget(['impersonating', 'impersonating_user_id']);

        // Log out and log back in as admin
        auth()->logout();
        auth()->loginUsingId($adminId);

        return redirect()->route('admin.customers.index')
            ->with('success', 'Exited customer view.');
    }

    /**
     * Manually add a domain to a customer
     */
    public function addDomain(Request $request, User $customer)
    {
        Log::info('addDomain() called', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'domain_name' => $request->input('name'),
            'extension' => $request->input('extension'),
            'status' => $request->input('status'),
        ]);

        if ($customer->is_admin) {
            Log::warning('addDomain() aborted - customer is admin', ['customer_id' => $customer->id]);
            abort(404);
        }

        $validated = $request->validate([
            'domain_name' => 'required|string|max:253',
            'registered_at' => 'nullable|date',
            'expires_at' => 'required|date',
            'next_due_date' => 'nullable|date',
            'status' => 'required|in:active,pending,expired,suspended',
            'nameserver_1' => 'nullable|string|max:255',
            'nameserver_2' => 'nullable|string|max:255',
            'auto_renew' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        Log::info('addDomain() validation passed', [
            'customer_id' => $customer->id,
            'validated_data' => $validated,
        ]);

        try {
            DB::transaction(function () use ($validated, $customer) {
                // Parse domain name (e.g., example.co.ke → name=example, extension=.co.ke)
                $domainName = $validated['domain_name'];
                if (strpos($domainName, '.') !== false) {
                    $parts = explode('.', $domainName, 2);
                    $name = $parts[0];
                    $extension = '.'.$parts[1];
                } else {
                    $name = $domainName;
                    $extension = '.com';
                }

                Log::info('addDomain() domain parsed', [
                    'domain_name' => $domainName,
                    'name' => $name,
                    'extension' => $extension,
                ]);

                // Create domain
                Log::info('addDomain() creating domain record', [
                    'user_id' => $customer->id,
                    'name' => $name,
                    'extension' => $extension,
                    'status' => $validated['status'],
                    'expires_at' => $validated['expires_at'],
                ]);

                $schedule = app(InvoiceGenerationScheduleService::class);
                $expiresAt = Carbon::parse($validated['expires_at']);

                $domain = Domain::create([
                    'user_id' => $customer->id,
                    'reseller_id' => $customer->reseller_id,
                    'name' => $name,
                    'extension' => $extension,
                    'registered_at' => $validated['registered_at'],
                    'expires_at' => $expiresAt,
                    'next_invoice_date' => $schedule->domainNextInvoiceDate(
                        new Domain(['expires_at' => $expiresAt])
                    ),
                    'status' => $validated['status'],
                    'nameserver_1' => $validated['nameserver_1'],
                    'nameserver_2' => $validated['nameserver_2'],
                    'auto_renew' => $validated['auto_renew'] ?? false,
                    'notes' => $validated['notes'],
                ]);

                Log::info('addDomain() domain created successfully', [
                    'domain_id' => $domain->id,
                    'customer_id' => $customer->id,
                    'domain_name' => "{$domain->name}{$domain->extension}",
                ]);

                // Create invoice if next_due_date is provided (manual first invoice)
                if (! empty($validated['next_due_date'])) {
                    $price = 10.00; // Default domain renewal price
                    $taxBreakdown = TaxService::calculateForUser($price, $customer);

                    $invoiceDueDate = $schedule->domainRenewalAnchorDate($domain);

                    Log::info('addDomain() creating invoice', [
                        'customer_id' => $customer->id,
                        'domain_id' => $domain->id,
                        'next_due_date' => $validated['next_due_date'],
                        'invoice_due_date' => $invoiceDueDate->toDateString(),
                        'subtotal' => $taxBreakdown['subtotal'],
                        'tax' => $taxBreakdown['tax'],
                        'total' => $taxBreakdown['total'],
                    ]);

                    $invoice = Invoice::create([
                        'user_id' => $customer->id,
                        'invoice_number' => $this->generateInvoiceNumber(),
                        'status' => 'unpaid',
                        'due_date' => $invoiceDueDate,
                        'subtotal' => $taxBreakdown['subtotal'],
                        'tax' => $taxBreakdown['tax'],
                        'total' => $taxBreakdown['total'],
                    ]);

                    Log::info('addDomain() invoice created', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ]);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => "Domain renewal: {$domainName}",
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    Log::info('addDomain() invoice item created', [
                        'invoice_id' => $invoice->id,
                        'domain_name' => $domainName,
                    ]);
                } else {
                    Log::info('addDomain() no invoice created - next_due_date not provided', [
                        'domain_id' => $domain->id,
                    ]);
                }
            });

            Log::info('addDomain() completed successfully', [
                'customer_id' => $customer->id,
                'domain_name' => $validated['domain_name'],
            ]);

            return redirect()->route('admin.customers.show', $customer)
                ->with('success', "Domain {$validated['domain_name']} added successfully.");
        } catch (\Exception $e) {
            Log::error('addDomain() failed with exception', [
                'customer_id' => $customer->id,
                'domain_name' => $validated['domain_name'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to add domain: '.$e->getMessage());
        }
    }

    /**
     * Manually add a service to a customer.
     *
     * Shared hosting (DirectAdmin) products require DA-specific credentials
     * (username + password + primary domain) and skip the generic
     * username/password/ip fields that VPS / Container / other hosting types use.
     */
    public function addService(Request $request, User $customer)
    {
        Log::info('addService() called', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'request_data' => array_diff_key($request->all(), [
                'password' => '',
                'direct_admin_password' => '',
            ]),
        ]);

        if ($customer->is_admin) {
            Log::warning('addService() aborted - customer is admin', ['customer_id' => $customer->id]);
            abort(404);
        }

        $product = Product::findOrFail($request->input('product_id'));
        $isSharedHosting = $product->type === 'shared_hosting';

        $rules = [
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'status' => 'required|in:active,pending,provisioning,suspended,terminated,failed,cancelled',
            'commenced_at' => 'nullable|date|before_or_equal:next_due_date',
            'next_due_date' => 'required|date',
            'suspend_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'generate_invoice' => 'boolean',
            'custom_price' => 'nullable|numeric|min:0',
        ];

        if ($isSharedHosting) {
            $rules['node_id'] = 'required|exists:nodes,id';
            $rules['da_package_key'] = 'required|string';
            $rules['direct_admin_username'] = [
                'required',
                'string',
                'min:3',
                'max:16',
                'regex:/^[a-z][a-z0-9]*$/',
            ];
            $rules['direct_admin_password'] = ['required', 'string', 'min:8', 'max:64'];
            $rules['direct_admin_domain'] = ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'];
        } else {
            $rules['username'] = 'nullable|string|max:255';
            $rules['password'] = 'nullable|string|max:255';
            $rules['ip_address'] = 'nullable|string|max:45';
        }

        $messages = [
            'direct_admin_username.regex' => 'DirectAdmin username must start with a lowercase letter and contain only lowercase letters and digits.',
            'direct_admin_username.max' => 'DirectAdmin username cannot exceed 16 characters.',
            'direct_admin_domain.regex' => 'Enter a valid primary domain (e.g. example.com).',
            'commenced_at.before_or_equal' => 'Commenced date cannot be after the next due date.',
        ];

        $validated = $request->validate($rules, $messages);

        Log::info('addService() validation passed', [
            'customer_id' => $customer->id,
            'service_name' => $validated['name'],
            'product_id' => $validated['product_id'],
            'product_type' => $product->type,
            'billing_cycle' => $validated['billing_cycle'],
            'is_shared_hosting' => $isSharedHosting,
        ]);

        try {
            // Pre-load package for shared hosting to avoid N+1 in transaction
            $selectedPackage = null;
            if ($isSharedHosting) {
                $selectedPackage = DirectAdminPackage::where('node_id', $validated['node_id'])
                    ->where('package_key', $validated['da_package_key'])
                    ->with('node')
                    ->firstOrFail();
            }

            $priceResolution = $this->resolveManualServicePriceKes($customer, $product, $validated);

            $createdService = DB::transaction(function () use ($validated, $customer, $product, $isSharedHosting, $selectedPackage, $priceResolution) {
                $serviceMeta = [];
                $nodeId = null;

                if ($isSharedHosting) {
                    $package = $selectedPackage;
                    $nodeId = $package->node_id;

                    $serviceMeta = [
                        'username' => $validated['direct_admin_username'],
                        'password' => $validated['direct_admin_password'],
                        'domain' => strtolower($validated['direct_admin_domain']),
                        'package' => $package->package_key,
                        'package_name' => $package->name,
                        'node_id' => $package->node_id,
                        'node_name' => $package->node->name,
                    ];
                } else {
                    if (! empty($validated['username'])) {
                        $serviceMeta['username'] = $validated['username'];
                    }
                    if (! empty($validated['password'])) {
                        $serviceMeta['password'] = $validated['password'];
                    }
                    if (! empty($validated['ip_address'])) {
                        $serviceMeta['ip_address'] = $validated['ip_address'];
                    }
                }

                Log::info('addService() creating service', [
                    'user_id' => $customer->id,
                    'product_id' => $product->id,
                    'service_name' => $validated['name'],
                    'status' => $validated['status'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'commenced_at' => $validated['commenced_at'] ?? null,
                    'next_due_date' => $validated['next_due_date'],
                    'meta_keys' => array_keys($serviceMeta),
                ]);

                $service = Service::create([
                    'user_id' => $customer->id,
                    'product_id' => $validated['product_id'],
                    'node_id' => $nodeId,
                    'name' => $validated['name'],
                    'status' => $validated['status'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'custom_price' => $priceResolution['custom_price_kes'],
                    'commenced_at' => $validated['commenced_at'] ?? null,
                    'next_due_date' => $validated['next_due_date'],
                    'suspend_date' => $validated['suspend_date'] ?? null,
                    'provisioning_driver_key' => $isSharedHosting
                        ? 'directadmin'
                        : $product->provisioning_driver_key,
                    'external_reference' => null,
                    'service_meta' => ! empty($serviceMeta) ? $serviceMeta : null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                Log::info('addService() service created successfully', [
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'service_name' => $service->name,
                    'product_name' => $product->name,
                ]);

                if (! empty($validated['generate_invoice'])) {
                    $invoice = $this->createServiceInvoice($customer, $product, $service, $priceResolution['amount_kes']);
                    $service->update(['invoice_id' => $invoice->id]);

                    Log::info('addService() service updated with invoice_id', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                    ]);
                }

                return $service;
            });

            // Optional: attempt provisioning to DirectAdmin if package + node are
            // wired up. Failures are logged but don't roll back — the admin asked
            // to record the service manually, and the credentials are saved either
            // way so the admin can sync later.
            $message = "Service {$validated['name']} added successfully.";
            if ($isSharedHosting && $selectedPackage && $selectedPackage->node_id) {
                $provisionResult = $this->provisionDirectAdminAccount($createdService, $validated);

                if ($provisionResult['success']) {
                    $message .= ' DirectAdmin account provisioned on '.$selectedPackage->node->name.'.';
                } else {
                    $message .= ' DirectAdmin provisioning skipped: '.$provisionResult['message'];
                }
            }

            Log::info('addService() completed successfully', [
                'customer_id' => $customer->id,
                'service_name' => $validated['name'],
            ]);

            return redirect()->route('admin.customers.show', $customer)
                ->with('success', $message);
        } catch (\Exception $e) {
            Log::error('addService() failed with exception', [
                'customer_id' => $customer->id,
                'service_name' => $validated['name'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            return back()
                ->with('error', 'Failed to add service: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Build the invoice + line item for a manually-added service.
     */
    private function createServiceInvoice(User $customer, Product $product, Service $service, float $priceKes): Invoice
    {
        $taxBreakdown = TaxService::calculateForUser($priceKes, $customer);

        $invoiceDueDate = app(InvoiceGenerationScheduleService::class)->serviceInvoiceDueDate($service);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'status' => 'unpaid',
            'due_date' => $invoiceDueDate,
            'subtotal' => $taxBreakdown['subtotal'],
            'tax' => $taxBreakdown['tax'],
            'total' => $taxBreakdown['total'],
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => $priceKes,
            'amount' => $priceKes,
        ]);

        return $invoice;
    }

    /**
     * @return array{amount_kes: float, custom_price_kes: ?float}
     */
    private function resolveManualServicePriceKes(User $customer, Product $product, array $validated): array
    {
        if (filled($validated['custom_price'] ?? null)) {
            $currency = app(UserCurrencyService::class)->codeFor($customer);
            $kesAmount = $this->displayAmountToKes((float) $validated['custom_price'], $currency);

            return [
                'amount_kes' => $kesAmount,
                'custom_price_kes' => $kesAmount,
            ];
        }

        return [
            'amount_kes' => $this->getServicePrice($product, $validated['billing_cycle']),
            'custom_price_kes' => null,
        ];
    }

    private function displayAmountToKes(float $amount, string $currency): float
    {
        $base = config('currency.base', 'KES');

        if ($currency === $base) {
            return round($amount, 2);
        }

        return round(Currency::convert($amount, $currency, $base), 2);
    }

    /**
     * Best-effort provisioning of a DirectAdmin account for a manually added
     * shared-hosting service. Returns a result array; never throws.
     */
    private function provisionDirectAdminAccount(Service $service, array $validated): array
    {
        try {
            $node = $service->node;
            if (! $node) {
                return ['success' => false, 'message' => 'no DirectAdmin node assigned to service'];
            }

            $resellerDirectAdmin = app(ResellerDirectAdminService::class);
            $reseller = $resellerDirectAdmin->resolveResellerForService($service);

            if ($reseller && ! $resellerDirectAdmin->canAutoProvision($reseller)) {
                return [
                    'success' => false,
                    'message' => 'Reseller DirectAdmin is not fully configured — link username, server, and login key on the reseller Node tab first.',
                ];
            }

            $da = $resellerDirectAdmin->directAdminForService($service);

            if (! $da || ! $da->isConfigured()) {
                return ['success' => false, 'message' => 'DirectAdmin node is not configured (missing API URL or password)'];
            }

            $packageName = $service->service_meta['package_name']
                ?? DirectAdminPackage::where('node_id', $node->id)
                    ->where('package_key', $service->service_meta['package'] ?? '')
                    ->value('name')
                ?? $service->product?->directAdminPackage?->name;

            if (! $packageName) {
                return ['success' => false, 'message' => 'no DirectAdmin package resolved for this service'];
            }

            $meta = $service->service_meta ?? [];
            $ownerReseller = $resellerDirectAdmin->impersonationUsernameForService($service);

            app(DirectAdminSetupService::class)->ensurePackageLimitsOnServer(
                $da,
                $service,
                filled($ownerReseller) ? (string) $ownerReseller : null,
            );

            $result = $da->createHostingAccount(
                $service,
                $validated['direct_admin_username'],
                $validated['direct_admin_password'],
                strtolower($validated['direct_admin_domain']),
                $packageName,
                filled($ownerReseller) ? (string) $ownerReseller : null,
            );

            if ($result['success']) {
                $service->update([
                    'status' => 'active',
                    'external_reference' => $validated['direct_admin_username'],
                    'credentials' => json_encode($result['credentials']),
                    'service_meta' => array_merge($meta, [
                        'domain' => strtolower($validated['direct_admin_domain']),
                        'provisioned_at' => now()->toIso8601String(),
                        'directadmin_reseller' => filled($ownerReseller) ? (string) $ownerReseller : ($meta['directadmin_reseller'] ?? null),
                    ]),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('provisionDirectAdminAccount() failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Generate a strong DirectAdmin-compatible password.
     *
     * Returns JSON for the Add Service modal's "Generate" button.
     * The character set avoids ambiguous characters (0/O, 1/l/I) and the
     * symbols DA's built-in password validator rejects.
     */
    public function generatePassword(Request $request)
    {
        $length = (int) $request->input('length', 16);
        $length = max(12, min(32, $length));

        $sets = [
            'lower' => 'abcdefghjkmnpqrstuvwxyz',
            'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ',
            'digit' => '23456789',
            'symbol' => '!@#$%^&*-_=+',
        ];

        // Guarantee at least one of each class so the result always satisfies
        // common password complexity rules.
        $password = '';
        foreach ($sets as $chars) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $all = implode('', $sets);
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle so the guaranteed-class characters aren't always at the front.
        $password = str_shuffle($password);

        return response()->json(['password' => $password]);
    }

    /**
     * Generate a DirectAdmin-compatible username from a customer name or email.
     */
    public function generateUsername(Request $request, User $customer)
    {
        $base = Str::of($customer->name ?: $customer->email)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->__toString();

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'u'.$base;
        }

        // DA allows up to 16 chars; reserve 3 for a uniqueness suffix.
        $base = substr($base, 0, 13);

        // Find a non-colliding username.
        $candidate = $base;
        $suffix = 0;
        while (Service::where('external_reference', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.$suffix;
            if (strlen($candidate) > 16) {
                $candidate = substr($base, 0, 16 - strlen((string) $suffix)).$suffix;
            }
        }

        return response()->json(['username' => $candidate]);
    }

    /**
     * Get product price based on billing cycle
     */
    private function getServicePrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }

    /**
     * Convert a customer to a reseller
     */
    public function convertToReseller(Request $request, User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        if ($customer->is_reseller) {
            return redirect()->route('admin.customers.index')
                ->with('info', "Customer '{$customer->name}' is already a reseller.");
        }

        $validated = $request->validate([
            'reseller_package_id' => 'nullable|exists:reseller_packages,id',
        ]);

        // is_reseller is intentionally not mass-assignable on User; set explicitly.
        $customer->is_reseller = true;
        $customer->reseller_package_id = $validated['reseller_package_id'] ?? null;
        $customer->package_subscribed_at = now();
        $customer->save();

        return redirect()->route('admin.customers.index')
            ->with('success', "Customer '{$customer->name}' has been converted to a reseller successfully.");
    }

    /**
     * Preview customer transfer to a reseller (JSON for admin UI).
     */
    public function transferPreview(Request $request, User $customer, CustomerResellerTransferService $transferService)
    {
        if ($customer->is_admin || $customer->is_reseller) {
            abort(404);
        }

        $request->validate([
            'target_reseller_id' => 'required',
        ]);

        $targetReseller = null;
        if ($request->query('target_reseller_id') !== 'platform') {
            $request->validate(['target_reseller_id' => 'exists:users,id']);
            $targetReseller = User::findOrFail($request->query('target_reseller_id'));
        }

        try {
            return response()->json($transferService->preview($customer, $targetReseller));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Reassign a managed customer to another reseller (ownership stays with the customer).
     */
    public function transferToReseller(Request $request, User $customer, CustomerResellerTransferService $transferService)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        if ($customer->is_reseller) {
            return back()->with('error', 'Reseller accounts cannot be transferred with this action.');
        }

        $validated = $request->validate([
            'target_reseller_id' => 'required',
        ]);

        $targetReseller = null;
        if ($validated['target_reseller_id'] !== 'platform') {
            $request->validate(['target_reseller_id' => 'exists:users,id']);
            $targetReseller = User::findOrFail($validated['target_reseller_id']);
        }

        try {
            $result = $transferService->transfer($customer, $targetReseller);

            $fromLabel = $result['from_reseller'] ?? 'Platform';
            $flash = "'{$customer->name}' is now managed by {$result['to_reseller']} (previously {$fromLabel}).";

            if ($result['cancelled_invoices'] > 0) {
                $flash .= " {$result['cancelled_invoices']} open invoice(s) were cancelled.";
            }

            if ($result['email_sent']) {
                $flash .= ' Customer notified by email.';
            } elseif ($targetReseller !== null) {
                $flash .= ' Customer email could not be sent (check mail settings).';
            }

            if (! empty($result['da_warnings'])) {
                $flash .= ' Some DirectAdmin accounts could not be moved: '.implode(' ', $result['da_warnings']);
            }

            if (! empty($result['catalog_warnings'])) {
                $flash .= ' Catalog mapping warnings: '.implode(' ', $result['catalog_warnings']);
            }

            return redirect()->route('admin.customers.index')->with('success', $flash);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Customer reseller transfer failed', [
                'customer_id' => $customer->id,
                'target_reseller_id' => $targetReseller?->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to transfer customer: '.$e->getMessage());
        }
    }

    /**
     * Create a custom invoice for a customer with line items
     */
    public function createInvoice(Request $request, User $customer)
    {

        $validated = $request->validate([
            'status' => 'required|in:draft,unpaid',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            $customerCurrency = app(UserCurrencyService::class)->codeFor($customer);

            $invoice = DB::transaction(function () use ($validated, $customer, $customerCurrency) {
                // Calculate totals (amounts entered in the customer's billing currency)
                $subtotal = 0;
                $itemsInKes = [];

                foreach ($validated['items'] as $item) {
                    $unitPriceKes = $this->displayAmountToKes((float) $item['unit_price'], $customerCurrency);
                    $lineTotalKes = $unitPriceKes * (float) $item['quantity'];
                    $subtotal += $lineTotalKes;
                    $itemsInKes[] = [
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPriceKes,
                        'amount' => $lineTotalKes,
                    ];
                }

                $taxRate = floatval($validated['tax_rate'] ?? 0);
                $taxBreakdown = TaxService::calculateWithRateForUser($subtotal, $taxRate, $customer);
                $taxAmount = $taxBreakdown['tax'];
                $subtotal = $taxBreakdown['subtotal'];
                $total = $taxBreakdown['total'];

                // Generate invoice number
                $prefix = Setting::getValue('invoice_prefix', 'INV');
                $year = now()->format('Y');
                $count = Invoice::whereYear('created_at', $year)->count() + 1;
                $invoiceNumber = "{$prefix}-{$year}-".str_pad($count, 5, '0', STR_PAD_LEFT);

                // Create invoice
                $invoice = Invoice::create([
                    'user_id' => $customer->id,
                    'invoice_number' => $invoiceNumber,
                    'status' => $validated['status'],
                    'subtotal' => $subtotal,
                    'tax' => $taxAmount,
                    'total' => $total,
                    'due_date' => $validated['due_date'] ?? now()->addDays(7),
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($itemsInKes as $item) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'amount' => $item['amount'],
                    ]);
                }

                return $invoice;
            });

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('success', 'Custom invoice created successfully.');
        } catch (\Exception $e) {
            return back()
                ->with('error', 'Failed to create invoice: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-".str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
