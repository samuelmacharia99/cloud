<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('is_admin', false)->latest();

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
            $query->where('status', $request->status);
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

        $customers = $query->withCount('services', 'invoices')->paginate(15)->withQueryString();

        return view('admin.customers.index', compact('customers'));
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
            'country' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,suspended,inactive',
        ]);

        User::create($validated);

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer created successfully.');
    }

    public function show(User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        $customer->load(
            'services.product',
            'invoices',
            'payments',
            'tickets',
            'domains'
        );

        $products = \App\Models\Product::with('directAdminPackage.node')
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

        return view('admin.customers.show', compact('customer', 'products', 'productsForJs'));
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
            'email' => 'required|email|unique:users,email,' . $customer->id,
            'password' => 'nullable|min:8|confirmed',
            'phone' => 'nullable|string',
            'company' => 'nullable|string',
            'country' => 'nullable|string',
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

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer updated successfully.');
    }

    public function impersonate(User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

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
        if (!session('impersonating')) {
            return redirect()->route('admin.customers.index');
        }

        $adminId = session('impersonating');

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
        \Log::info('addDomain() called', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'request_data' => $request->all(),
        ]);

        if ($customer->is_admin) {
            \Log::warning('addDomain() aborted - customer is admin', ['customer_id' => $customer->id]);
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

        \Log::info('addDomain() validation passed', [
            'customer_id' => $customer->id,
            'validated_data' => $validated,
        ]);

        try {
            \DB::transaction(function () use ($validated, $customer) {
                // Parse domain name (e.g., example.co.ke → name=example, extension=.co.ke)
                $domainName = $validated['domain_name'];
                if (strpos($domainName, '.') !== false) {
                    $parts = explode('.', $domainName, 2);
                    $name = $parts[0];
                    $extension = '.' . $parts[1];
                } else {
                    $name = $domainName;
                    $extension = '.com';
                }

                \Log::info('addDomain() domain parsed', [
                    'domain_name' => $domainName,
                    'name' => $name,
                    'extension' => $extension,
                ]);

                // Create domain
                \Log::info('addDomain() creating domain record', [
                    'user_id' => $customer->id,
                    'name' => $name,
                    'extension' => $extension,
                    'status' => $validated['status'],
                    'expires_at' => $validated['expires_at'],
                ]);

                $domain = \App\Models\Domain::create([
                    'user_id' => $customer->id,
                    'name' => $name,
                    'extension' => $extension,
                    'registered_at' => $validated['registered_at'],
                    'expires_at' => $validated['expires_at'],
                    'status' => $validated['status'],
                    'nameserver_1' => $validated['nameserver_1'],
                    'nameserver_2' => $validated['nameserver_2'],
                    'auto_renew' => $validated['auto_renew'] ?? false,
                    'notes' => $validated['notes'],
                ]);

                \Log::info('addDomain() domain created successfully', [
                    'domain_id' => $domain->id,
                    'customer_id' => $customer->id,
                    'domain_name' => "{$domain->name}{$domain->extension}",
                ]);

                // Create invoice if next_due_date is provided (10 days prior)
                if (!empty($validated['next_due_date'])) {
                    $price = 10.00; // Default domain renewal price
                    $taxEnabled = \App\Models\Setting::getValue('tax_enabled') == 'true';
                    $taxRate = (float) \App\Models\Setting::getValue('tax_rate', 0);

                    $subtotal = $price;
                    $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
                    $total = $subtotal + $tax;

                    $invoiceDueDate = \Carbon\Carbon::parse($validated['next_due_date'])->subDays(10);

                    \Log::info('addDomain() creating invoice', [
                        'customer_id' => $customer->id,
                        'domain_id' => $domain->id,
                        'next_due_date' => $validated['next_due_date'],
                        'invoice_due_date' => $invoiceDueDate->toDateString(),
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $total,
                    ]);

                    $invoice = \App\Models\Invoice::create([
                        'user_id' => $customer->id,
                        'invoice_number' => $this->generateInvoiceNumber(),
                        'status' => 'unpaid',
                        'due_date' => $invoiceDueDate,
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $total,
                    ]);

                    \Log::info('addDomain() invoice created', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ]);

                    \App\Models\InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => "Domain renewal: {$domainName}",
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    \Log::info('addDomain() invoice item created', [
                        'invoice_id' => $invoice->id,
                        'domain_name' => $domainName,
                    ]);
                } else {
                    \Log::info('addDomain() no invoice created - next_due_date not provided', [
                        'domain_id' => $domain->id,
                    ]);
                }
            });

            \Log::info('addDomain() completed successfully', [
                'customer_id' => $customer->id,
                'domain_name' => $validated['domain_name'],
            ]);

            return redirect()->route('admin.customers.show', $customer)
                ->with('success', "Domain {$validated['domain_name']} added successfully.");
        } catch (\Exception $e) {
            \Log::error('addDomain() failed with exception', [
                'customer_id' => $customer->id,
                'domain_name' => $validated['domain_name'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to add domain: ' . $e->getMessage());
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
        \Log::info('addService() called', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'request_data' => array_diff_key($request->all(), [
                'password' => '',
                'direct_admin_password' => '',
            ]),
        ]);

        if ($customer->is_admin) {
            \Log::warning('addService() aborted - customer is admin', ['customer_id' => $customer->id]);
            abort(404);
        }

        $product = \App\Models\Product::findOrFail($request->input('product_id'));
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
        ];

        if ($isSharedHosting) {
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

        \Log::info('addService() validation passed', [
            'customer_id' => $customer->id,
            'service_name' => $validated['name'],
            'product_id' => $validated['product_id'],
            'product_type' => $product->type,
            'billing_cycle' => $validated['billing_cycle'],
            'is_shared_hosting' => $isSharedHosting,
        ]);

        try {
            $createdService = \DB::transaction(function () use ($validated, $customer, $product, $isSharedHosting) {
                $serviceMeta = [];

                if ($isSharedHosting) {
                    $package = $product->directAdminPackage;

                    $serviceMeta = [
                        'username' => $validated['direct_admin_username'],
                        'password' => $validated['direct_admin_password'],
                        'domain' => strtolower($validated['direct_admin_domain']),
                        'package' => $package?->package_key,
                        'package_name' => $package?->name,
                        'node_id' => $package?->node_id,
                        'node_name' => $package?->node?->name,
                    ];
                } else {
                    if (!empty($validated['username'])) {
                        $serviceMeta['username'] = $validated['username'];
                    }
                    if (!empty($validated['password'])) {
                        $serviceMeta['password'] = $validated['password'];
                    }
                    if (!empty($validated['ip_address'])) {
                        $serviceMeta['ip_address'] = $validated['ip_address'];
                    }
                }

                \Log::info('addService() creating service', [
                    'user_id' => $customer->id,
                    'product_id' => $product->id,
                    'service_name' => $validated['name'],
                    'status' => $validated['status'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'commenced_at' => $validated['commenced_at'] ?? null,
                    'next_due_date' => $validated['next_due_date'],
                    'meta_keys' => array_keys($serviceMeta),
                ]);

                $service = \App\Models\Service::create([
                    'user_id' => $customer->id,
                    'product_id' => $validated['product_id'],
                    'node_id' => $isSharedHosting ? ($product->directAdminPackage?->node_id) : null,
                    'name' => $validated['name'],
                    'status' => $validated['status'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'commenced_at' => $validated['commenced_at'] ?? null,
                    'next_due_date' => $validated['next_due_date'],
                    'suspend_date' => $validated['suspend_date'] ?? null,
                    'provisioning_driver_key' => $isSharedHosting
                        ? 'directadmin'
                        : $product->provisioning_driver_key,
                    'external_reference' => $isSharedHosting ? $validated['direct_admin_username'] : null,
                    'service_meta' => !empty($serviceMeta) ? $serviceMeta : null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                \Log::info('addService() service created successfully', [
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'service_name' => $service->name,
                    'product_name' => $product->name,
                ]);

                if (!empty($validated['generate_invoice'])) {
                    $invoice = $this->createServiceInvoice($customer, $product, $service, $validated);
                    $service->update(['invoice_id' => $invoice->id]);

                    \Log::info('addService() service updated with invoice_id', [
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
            if ($isSharedHosting && $product->directAdminPackage && $product->directAdminPackage->node_id) {
                $provisionResult = $this->provisionDirectAdminAccount($createdService, $validated);

                if ($provisionResult['success']) {
                    $message .= ' DirectAdmin account provisioned on ' . $product->directAdminPackage->node->name . '.';
                } else {
                    $message .= ' DirectAdmin provisioning skipped: ' . $provisionResult['message'];
                }
            }

            \Log::info('addService() completed successfully', [
                'customer_id' => $customer->id,
                'service_name' => $validated['name'],
            ]);

            return redirect()->route('admin.customers.show', $customer)
                ->with('success', $message);
        } catch (\Exception $e) {
            \Log::error('addService() failed with exception', [
                'customer_id' => $customer->id,
                'service_name' => $validated['name'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            return back()
                ->with('error', 'Failed to add service: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Build the invoice + line item for a manually-added service.
     */
    private function createServiceInvoice(User $customer, \App\Models\Product $product, \App\Models\Service $service, array $validated): \App\Models\Invoice
    {
        $price = $this->getServicePrice($product, $validated['billing_cycle']);
        $taxEnabled = \App\Models\Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) \App\Models\Setting::getValue('tax_rate', 0);

        $subtotal = $price;
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        $invoiceDueDate = \Carbon\Carbon::parse($validated['next_due_date'])->subDays(10);

        $invoice = \App\Models\Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'status' => 'unpaid',
            'due_date' => $invoiceDueDate,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ]);

        \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => $price,
            'amount' => $price,
        ]);

        return $invoice;
    }

    /**
     * Best-effort provisioning of a DirectAdmin account for a manually added
     * shared-hosting service. Returns a result array; never throws.
     */
    private function provisionDirectAdminAccount(\App\Models\Service $service, array $validated): array
    {
        try {
            $package = $service->product->directAdminPackage;
            $node = $package?->node;

            if (!$node) {
                return ['success' => false, 'message' => 'no DirectAdmin node attached to package'];
            }

            $da = new \App\Services\Provisioning\DirectAdminService($node);

            if (!$da->isConfigured()) {
                return ['success' => false, 'message' => 'DirectAdmin node is not configured (missing API URL or password)'];
            }

            $result = $da->createHostingAccount(
                $service,
                $validated['direct_admin_username'],
                $validated['direct_admin_password'],
                strtolower($validated['direct_admin_domain']),
                $package->package_key
            );

            if ($result['success']) {
                $service->update([
                    'credentials' => json_encode($result['credentials']),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error('provisionDirectAdminAccount() failed', [
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
        $base = \Illuminate\Support\Str::of($customer->name ?: $customer->email)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->__toString();

        if ($base === '' || !ctype_alpha($base[0])) {
            $base = 'u' . $base;
        }

        // DA allows up to 16 chars; reserve 3 for a uniqueness suffix.
        $base = substr($base, 0, 13);

        // Find a non-colliding username.
        $candidate = $base;
        $suffix = 0;
        while (\App\Models\Service::where('external_reference', $candidate)->exists()) {
            $suffix++;
            $candidate = $base . $suffix;
            if (strlen($candidate) > 16) {
                $candidate = substr($base, 0, 16 - strlen((string) $suffix)) . $suffix;
            }
        }

        return response()->json(['username' => $candidate]);
    }

    /**
     * Get product price based on billing cycle
     */
    private function getServicePrice(\App\Models\Product $product, string $billingCycle): float
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

        $validated = $request->validate([
            'reseller_package_id' => 'nullable|exists:reseller_packages,id',
        ]);

        $customer->update([
            'is_reseller' => true,
            'reseller_package_id' => $validated['reseller_package_id'] ?? null,
            'package_subscribed_at' => now(),
        ]);

        return redirect()->route('admin.customers.index')
            ->with('success', "Customer '{$customer->name}' has been converted to a reseller successfully.");
    }

    /**
     * Transfer a customer's services and domains to another reseller
     */
    public function transferToReseller(Request $request, User $customer)
    {
        if ($customer->is_admin) {
            abort(404);
        }

        $validated = $request->validate([
            'target_reseller_id' => 'required|exists:users,id',
        ]);

        $targetReseller = User::findOrFail($validated['target_reseller_id']);

        // Ensure target is a reseller
        if (!$targetReseller->is_reseller) {
            return back()->with('error', 'Target user is not a reseller.');
        }

        // Ensure they're not the same user
        if ($customer->id === $targetReseller->id) {
            return back()->with('error', 'Cannot transfer to the same reseller.');
        }

        try {
            \DB::transaction(function () use ($customer, $targetReseller) {
                // Transfer all services
                \App\Models\Service::where('user_id', $customer->id)
                    ->update(['user_id' => $targetReseller->id]);

                // Transfer all domains
                \App\Models\Domain::where('user_id', $customer->id)
                    ->update(['user_id' => $targetReseller->id]);

                // Transfer all invoices
                \App\Models\Invoice::where('user_id', $customer->id)
                    ->update(['user_id' => $targetReseller->id]);

                // Transfer all payments
                \App\Models\Payment::where('user_id', $customer->id)
                    ->update(['user_id' => $targetReseller->id]);
            });

            return redirect()->route('admin.customers.index')
                ->with('success', "All services, domains, and invoices for '{$customer->name}' have been transferred to '{$targetReseller->name}' successfully.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to transfer customer: ' . $e->getMessage());
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
            $invoice = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $customer) {
                // Calculate totals
                $subtotal = 0;
                foreach ($validated['items'] as $item) {
                    $subtotal += floatval($item['quantity']) * floatval($item['unit_price']);
                }

                $taxRate = floatval($validated['tax_rate'] ?? 0);
                $taxAmount = $subtotal * ($taxRate / 100);
                $total = $subtotal + $taxAmount;

                // Generate invoice number
                $prefix = \App\Models\Setting::getValue('invoice_prefix', 'INV');
                $year = now()->format('Y');
                $count = \App\Models\Invoice::whereYear('created_at', $year)->count() + 1;
                $invoiceNumber = "{$prefix}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);

                // Create invoice
                $invoice = \App\Models\Invoice::create([
                    'user_id' => $customer->id,
                    'invoice_number' => $invoiceNumber,
                    'status' => $validated['status'],
                    'subtotal' => $subtotal,
                    'tax' => $taxAmount,
                    'total' => $total,
                    'due_date' => $validated['due_date'] ?? now()->addDays(7),
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Create line items (custom items with no product_id or service_id)
                foreach ($validated['items'] as $item) {
                    $itemAmount = floatval($item['quantity']) * floatval($item['unit_price']);
                    \App\Models\InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'amount' => $itemAmount,
                    ]);
                }

                return $invoice;
            });

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('success', 'Custom invoice created successfully.');
        } catch (\Exception $e) {
            return back()
                ->with('error', 'Failed to create invoice: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = \App\Models\Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = \App\Models\Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}
