<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Mail\AdminServerOrderMail;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\EmailDeliveryService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\NotificationService;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ResellerEnforcementService;
use App\Services\ServiceEnforcementInsightService;
use App\Services\ServiceStatusSyncService;
use App\Services\TaxService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $services = $this->filteredIndexQuery($request)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $mismatchCount = Service::query()
            ->where('live_status_mismatch', true)
            ->whereHas('product', fn ($q) => $q->where('type', '!=', 'domain'))
            ->count();

        return view('admin.services.index', compact('services', 'mismatchCount'));
    }

    public function refreshLiveStatusBulk(Request $request, ServiceStatusSyncService $syncService)
    {
        $this->authorize('viewAny', Service::class);

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 15;

        $services = $this->filteredIndexQuery($request)
            ->whereHas('product', fn ($q) => $q->whereIn('type', ['shared_hosting', 'container_hosting']))
            ->forPage($page, $perPage)
            ->get();

        if ($services->isEmpty()) {
            return back()->with('warning', 'No shared hosting or container services matched your filters.');
        }

        $summary = $syncService->syncMany($services, $request->boolean('heal'));

        return back()->with(
            'success',
            "Live status refreshed for {$summary['checked']} service(s): {$summary['mismatches']} mismatch(es), {$summary['errors']} probe error(s)."
        );
    }

    public function create()
    {
        $this->authorize('create', Service::class);

        $customers = User::where('is_admin', false)->orderBy('name')->get(['id', 'name', 'email']);
        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'monthly_price', 'yearly_price', 'billing_cycle']);

        return view('admin.services.create', compact('customers', 'products'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Service::class);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'next_due_date' => 'required|date',
            'custom_domain' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'generate_invoice' => 'boolean',
            'provision_now' => 'boolean',
        ]);

        $product = Product::findOrFail($request->product_id);
        $user = User::findOrFail($request->user_id);

        try {
            \DB::transaction(function () use ($request, $product, $user) {
                // Create service
                $service = Service::create([
                    'user_id' => $request->user_id,
                    'product_id' => $request->product_id,
                    'name' => $request->name,
                    'billing_cycle' => $request->billing_cycle,
                    'next_due_date' => $request->next_due_date,
                    'status' => 'pending',
                    'provisioning_driver_key' => $product->provisioning_driver_key,
                    'notes' => $request->notes,
                ]);

                // If domain product, create domain record
                if ($product->type === 'domain') {
                    $domain = $request->custom_domain ?? $service->name;
                    // Extract extension and name
                    if (strpos($domain, '.') !== false) {
                        [$name, $ext] = explode('.', $domain, 2);
                        $ext = '.'.$ext;
                    } else {
                        $name = $domain;
                        $ext = '.com';
                    }

                    Domain::create([
                        'user_id' => $user->id,
                        'name' => $name,
                        'extension' => $ext,
                        'status' => 'pending',
                    ]);
                }

                // Generate invoice if requested
                if ($request->boolean('generate_invoice')) {
                    $price = $this->getServicePrice($product, $request->billing_cycle);
                    $taxBreakdown = TaxService::calculateForUser($price, $user);

                    $invoice = Invoice::create([
                        'user_id' => $user->id,
                        'invoice_number' => $this->generateInvoiceNumber(),
                        'status' => 'unpaid',
                        'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
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
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    $service->update(['invoice_id' => $invoice->id]);
                }

                // Provision immediately if requested
                if ($request->boolean('provision_now')) {
                    $provisioningService = app(ProvisioningService::class);
                    $provisioningService->provision($service);
                }
            });

            return redirect()->route('admin.services.show', $service)->with('success', 'Service created successfully.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Failed to create service: '.$e->getMessage());
        }
    }

    public function show(Service $service, ServiceStatusSyncService $syncService)
    {
        $service->load(['user', 'product', 'invoice', 'node', 'containerDeployment.node']);

        $liveStatus = null;
        if ($service->supportsLiveStatusProbe()) {
            try {
                $liveStatus = $syncService->sync($service);
                $service->refresh();
            } catch (\Throwable $e) {
                \Log::warning('Live status sync failed on service show', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($service->isSharedHosting() && $service->node) {
            try {
                app(ServicePackageUsageService::class)->syncFromDirectAdmin($service);
                $service->refresh();
            } catch (\Throwable $e) {
                \Log::warning('DirectAdmin account sync failed on service show', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $sameTypeProducts = Product::where('type', $service->product->type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'monthly_price', 'yearly_price', 'provisioning_driver_key']);

        $currencyCode = Setting::getValue('currency', 'KES');
        $enforcementInsight = app(ServiceEnforcementInsightService::class)->forService($service);

        $hostingUpgradeService = app(CustomerHostingUpgradeService::class);
        $upgradeOptions = $service->isSharedHosting()
            ? $hostingUpgradeService->upgradeOptionsForService($service)
            : collect();
        $recommendedUpgrade = $upgradeOptions->isNotEmpty()
            ? $hostingUpgradeService->recommendedUpgrade(
                $service,
                $service->user,
                $enforcementInsight['primary_metric'] ?? null,
            )
            : null;

        $daLivePackage = $service->service_meta['directadmin_account']['package'] ?? null;
        $hasStaleOverlimitFlags = ! empty($service->service_meta['package_overlimit_metrics'])
            && $service->status->value === 'active';

        return view('admin.services.show', compact(
            'service',
            'sameTypeProducts',
            'currencyCode',
            'liveStatus',
            'enforcementInsight',
            'upgradeOptions',
            'recommendedUpgrade',
            'daLivePackage',
            'hasStaleOverlimitFlags',
        ));
    }

    public function provision(Service $service)
    {
        try {
            // Validate service prerequisites before provisioning
            $this->validateServiceForProvisioning($service);

            $provisioningService = app(ProvisioningService::class);
            $provisioningService->provision($service);

            return back()->with('success', 'Service provisioned successfully.');
        } catch (\Exception $e) {
            \Log::error("Admin provisioning attempt failed for service {$service->id}: {$e->getMessage()}");

            return back()->with('error', 'Provisioning failed: '.$e->getMessage());
        }
    }

    /**
     * Validate that a service has all required configuration before provisioning
     */
    private function validateServiceForProvisioning(Service $service): void
    {
        if (! $service->product) {
            throw new \Exception('Service has no product assigned.');
        }

        $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

        // DirectAdmin services must have a node assigned
        if ($driver === 'directadmin') {
            if (! $service->node_id) {
                throw new \Exception('DirectAdmin services must be assigned to a DirectAdmin node before provisioning. Assign a node from the Configuration section.');
            }

            $node = $service->node;
            if (! $node || $node->type !== 'directadmin') {
                throw new \Exception('Service node must be a DirectAdmin type node.');
            }

            if (! $node->is_active) {
                throw new \Exception("Assigned DirectAdmin node '{$node->name}' is not active. Activate it first.");
            }
        }

        // Container services must have a product with container template
        if ($driver === 'container') {
            if (! $service->product->container_template_id) {
                throw new \Exception('Container services must have a product linked to a container template.');
            }
        }
    }

    public function suspend(Request $request, Service $service)
    {
        $validated = $request->validate([
            'suspension_reason' => 'nullable|string|max:500',
        ]);

        try {
            $provisioningService = app(ProvisioningService::class);
            $provisioningService->suspend(
                $service,
                ResellerEnforcementService::REASON_MANUAL,
                filled($validated['suspension_reason'] ?? null)
                    ? $validated['suspension_reason']
                    : 'Suspended by administrator',
            );

            return back()->with('success', 'Service suspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Suspension failed: '.$e->getMessage());
        }
    }

    public function unsuspend(Service $service)
    {
        try {
            $provisioningService = app(ProvisioningService::class);
            $provisioningService->unsuspend($service);

            return back()->with('success', 'Service unsuspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Unsuspension failed: '.$e->getMessage());
        }
    }

    public function terminate(Service $service)
    {
        try {
            $provisioningService = app(ProvisioningService::class);
            $provisioningService->terminate($service);

            return back()->with('success', 'Service terminated successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Termination failed: '.$e->getMessage());
        }
    }

    public function cancel(Service $service)
    {
        if (in_array($service->status->value, ['cancelled', 'terminated'])) {
            return back()->with('error', 'Service is already cancelled or terminated.');
        }

        DB::transaction(function () use ($service) {
            $service->update([
                'status' => ServiceStatus::Cancelled,
                'terminate_date' => now(),
            ]);

            if ($service->invoice_id) {
                $invoice = $service->invoice;
                if ($invoice && in_array($invoice->status->value, ['unpaid', 'draft', 'overdue'])) {
                    $invoice->update(['status' => InvoiceStatus::Cancelled]);
                }
            }
        });

        return back()->with('success', 'Service "'.$service->name.'" has been cancelled.');
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,pending,provisioning,suspended,terminated,failed,cancelled',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'custom_price' => 'nullable|numeric|min:0',
            'next_due_date' => 'required|date',
            'commenced_at' => 'nullable|date',
            'suspend_date' => 'nullable|date',
            'terminate_date' => 'nullable|date',
            'product_id' => 'nullable|exists:products,id',
            'node_id' => 'nullable|exists:nodes,id',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'primary_domain' => 'nullable|string|max:253|regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
            'notes' => 'nullable|string|max:2000',
            'return_to' => 'nullable|in:customer',
        ]);

        $returnToCustomer = ($validated['return_to'] ?? null) === 'customer';
        unset($validated['return_to']);

        $targetProduct = null;
        $applyHostingPackageChange = false;

        // When product changes, sync the provisioning driver key from the new product
        if (! empty($validated['product_id']) && (int) $validated['product_id'] !== $service->product_id) {
            $targetProduct = Product::with('directAdminPackage')->find($validated['product_id']);
            if ($targetProduct && $targetProduct->type === $service->product->type) {
                $validated['provisioning_driver_key'] = $targetProduct->provisioning_driver_key;

                $hasHostingAccount = $service->external_reference
                    || filled($service->service_meta['username'] ?? null);

                if ($service->isSharedHosting() && $hasHostingAccount && $targetProduct->directAdminPackage) {
                    $applyHostingPackageChange = true;
                    unset($validated['product_id']);
                } elseif ($targetProduct->directAdminPackage) {
                    $meta = is_array($service->service_meta) ? $service->service_meta : ($service->service_meta ?? []);
                    $meta['package'] = $targetProduct->directAdminPackage->package_key;
                    $meta['package_name'] = $targetProduct->directAdminPackage->name;
                    $validated['service_meta'] = $meta;
                }
            } else {
                unset($validated['product_id']); // prevent cross-type reassignment
            }
        }

        \Log::info('Service update request', [
            'service_id' => $service->id,
            'username_submitted' => $validated['username'] ?? null,
            'password_submitted' => ! empty($validated['password']),
            'product_id_submitted' => $validated['product_id'] ?? null,
            'product_type' => $service->product->type,
            'service_provisioning_driver_key' => $service->provisioning_driver_key,
            'product_provisioning_driver_key' => $service->product->provisioning_driver_key,
        ]);

        // Handle username/password for DirectAdmin products (stored in service_meta)
        $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

        \Log::info('Service update - driver resolution', [
            'service_id' => $service->id,
            'resolved_driver' => $driver,
            'has_username' => ! empty($validated['username']),
            'has_password' => ! empty($validated['password']),
        ]);

        if ($driver === 'directadmin' && (! empty($validated['username']) || ! empty($validated['password']))) {
            \Log::info('Service update - saving DirectAdmin credentials', [
                'service_id' => $service->id,
                'username' => $validated['username'],
            ]);

            $meta = is_array($service->service_meta) ? $service->service_meta : ($service->service_meta ?? []);

            if (! empty($validated['username'])) {
                $username = (string) $validated['username'];
                $meta['username'] = $username;
                $resolved = Service::resolveExternalReferenceForAssignment($username, $service->id);
                if ($service->external_reference !== $resolved) {
                    $validated['external_reference'] = $resolved;
                }
            }
            if (! empty($validated['password'])) {
                $meta['password'] = $validated['password'];
            }
            if (! empty($validated['primary_domain'])) {
                $meta['domain'] = strtolower($validated['primary_domain']);
            }

            $validated['service_meta'] = $meta;
        }
        // Handle username/password for other products (stored in credentials JSON)
        elseif (! empty($validated['username']) || ! empty($validated['password'])) {
            \Log::info('Service update - saving non-DirectAdmin credentials', [
                'service_id' => $service->id,
                'driver' => $driver,
                'username' => $validated['username'],
            ]);

            $credentials = is_string($service->credentials)
                ? json_decode($service->credentials, true) ?? []
                : ($service->credentials ?? []);

            if (! empty($validated['username'])) {
                $credentials['username'] = $validated['username'];
            }
            if (! empty($validated['password'])) {
                $credentials['password'] = $validated['password'];
            }

            $validated['credentials'] = json_encode($credentials);
        } else {
            \Log::info('Service update - no credentials to save', [
                'service_id' => $service->id,
            ]);
        }

        unset($validated['username'], $validated['password'], $validated['primary_domain']);
        $service->update($validated);

        if ($applyHostingPackageChange && $targetProduct) {
            try {
                $upgradeService = app(CustomerHostingUpgradeService::class);
                $upgradeService->assertValidUpgradeTarget($service, $targetProduct);
                $upgradeService->applyUpgrade($service->fresh(), $targetProduct);
            } catch (\InvalidArgumentException $e) {
                $message = $e->getMessage();

                if ($returnToCustomer) {
                    return redirect()
                        ->route('admin.customers.show', ['customer' => $service->user_id, 'tab' => 'services'])
                        ->with('error', $message);
                }

                return back()->with('error', $message);
            } catch (\Throwable $e) {
                Log::warning('Service updated but hosting package change failed', [
                    'service_id' => $service->id,
                    'target_product_id' => $targetProduct->id,
                    'error' => $e->getMessage(),
                ]);

                $message = 'Service details saved, but the hosting package could not be applied on the server: '.$e->getMessage();

                if ($returnToCustomer) {
                    return redirect()
                        ->route('admin.customers.show', ['customer' => $service->user_id, 'tab' => 'services'])
                        ->with('error', $message);
                }

                return back()->with('error', $message);
            }
        }

        $successMessage = $applyHostingPackageChange
            ? 'Service updated and hosting package changed successfully.'
            : 'Service updated successfully.';

        if ($returnToCustomer) {
            return redirect()
                ->route('admin.customers.show', ['customer' => $service->user_id, 'tab' => 'services'])
                ->with('success', $successMessage);
        }

        return back()->with('success', $successMessage);
    }

    public function upgradeHosting(Request $request, Service $service)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'billing_action' => 'nullable|in:apply_only,create_invoice',
        ]);

        $targetProduct = Product::with('directAdminPackage')->findOrFail($validated['product_id']);
        $upgradeService = app(CustomerHostingUpgradeService::class);

        try {
            $upgradeService->assertValidUpgradeTarget($service, $targetProduct);

            if (($validated['billing_action'] ?? 'apply_only') === 'create_invoice') {
                $service->loadMissing('user');
                $invoice = $upgradeService->createUpgradeInvoice($service, $service->user, $targetProduct);

                AdminActivityService::log(
                    'service.hosting_upgrade_invoice',
                    "Created hosting upgrade invoice {$invoice->invoice_number} for service #{$service->id}",
                    $service,
                    ['target_product_id' => $targetProduct->id],
                );

                return redirect()->route('admin.invoices.show', $invoice)
                    ->with('success', "Upgrade invoice {$invoice->invoice_number} created. Apply the plan now or wait for payment.");
            }

            $upgradeService->applyUpgrade($service->fresh(), $targetProduct);

            AdminActivityService::log(
                'service.hosting_upgrade',
                "Upgraded service #{$service->id} to {$targetProduct->name} on DirectAdmin",
                $service,
                ['target_product_id' => $targetProduct->id],
            );

            return back()->with('success', "Hosting plan upgraded to {$targetProduct->name} on DirectAdmin.");
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('Admin hosting upgrade failed', [
                'service_id' => $service->id,
                'target_product_id' => $targetProduct->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Hosting upgrade failed: '.$e->getMessage());
        }
    }

    public function reconcileHostingPackage(Service $service)
    {
        if (! $service->isSharedHosting()) {
            return back()->with('error', 'Only shared hosting services can be reconciled with DirectAdmin.');
        }

        try {
            $result = app(CustomerHostingUpgradeService::class)->reconcilePackageState($service->fresh());

            $message = $result['cleared_stale_flags']
                ? 'DirectAdmin usage refreshed and stale over-limit flags cleared.'
                : 'DirectAdmin usage refreshed.';

            if ($result['directadmin_package'] && $result['platform_product']
                && strcasecmp((string) $result['directadmin_package'], (string) $result['platform_product']) !== 0) {
                $message .= ' Warning: DirectAdmin reports package "'.$result['directadmin_package']
                    .'" but the platform product is "'.$result['platform_product'].'". Use Upgrade Hosting to align them.';
            }

            return back()->with($result['cleared_stale_flags'] ? 'success' : 'info', $message);
        } catch (\Throwable $e) {
            Log::warning('Hosting package reconcile failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not refresh hosting package state: '.$e->getMessage());
        }
    }

    public function destroy(Service $service)
    {
        if ($this->serviceRequiresInfrastructureCleanup($service)) {
            $alreadyInactive = in_array($service->status->value, ['terminated', 'cancelled'], true);

            try {
                app(ProvisioningService::class)->terminate($service->fresh());
            } catch (\Throwable $e) {
                Log::warning('Admin service delete: infrastructure cleanup failed', [
                    'service_id' => $service->id,
                    'status' => $service->status->value,
                    'error' => $e->getMessage(),
                ]);

                if (! $alreadyInactive) {
                    return back()->with(
                        'error',
                        'Could not deprovision service infrastructure. Use Terminate first or fix the host connection, then delete.'
                    );
                }
            }
        }

        $service->fresh()->delete();

        return redirect()->route('admin.services.index')
            ->with('success', "Service #{$service->id} deleted.");
    }

    private function serviceRequiresInfrastructureCleanup(Service $service): bool
    {
        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;

        return in_array($driver, ['container', 'directadmin'], true);
    }

    public function refreshStatus(Service $service, ServiceStatusSyncService $syncService)
    {
        $this->authorize('refreshStatus', $service);

        if (! $service->supportsLiveStatusProbe()) {
            return back()->with('warning', 'Live status checks are only available for DirectAdmin and container hosting services.');
        }

        try {
            $result = $syncService->sync($service, applyHealing: true);
            $service->refresh();

            $message = "Live status: {$result->label}.";

            if ($service->live_status_mismatch) {
                $message .= ' Platform status ('.ucfirst($service->status->value).') does not match infrastructure.';
            }

            return back()->with($service->live_status_mismatch ? 'warning' : 'success', $message);
        } catch (\Throwable $e) {
            return back()->with('error', 'Live status refresh failed: '.$e->getMessage());
        }
    }

    private function filteredIndexQuery(Request $request): Builder
    {
        $query = Service::with(['user', 'product'])
            ->whereHas('product', function ($q) {
                $q->where('type', '!=', 'domain');
            });

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('id', 'like', "%{$request->search}%")
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        if ($request->filled('customer')) {
            $query->where('user_id', $request->customer);
        }

        if ($request->boolean('mismatch')) {
            $query->where('live_status_mismatch', true);
        }

        return $query;
    }

    /**
     * Test DirectAdmin connection for a service
     */
    public function testDirectAdminConnection(Service $service)
    {
        $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

        if ($driver !== 'directadmin') {
            return response()->json([
                'success' => false,
                'message' => 'This service is not configured for DirectAdmin',
            ], 400);
        }

        if (! $service->node_id) {
            return response()->json([
                'success' => false,
                'message' => 'No DirectAdmin node assigned to this service',
                'hint' => 'Assign a DirectAdmin server in the Configuration section',
            ], 400);
        }

        if (! ($service->service_meta['username'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'No DirectAdmin username set for this service',
                'hint' => 'Enter the DirectAdmin username in the Configuration section',
            ], 400);
        }

        try {
            $daService = new DirectAdminService($service->node);
            $result = $daService->testConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test connection failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function resendCredentials(Service $service)
    {
        $notifications = app(NotificationService::class);

        if ($service->isSharedHosting()) {
            if (! $service->getHostingCredentials()) {
                return back()->with('error', 'No DirectAdmin credentials found for this service.');
            }

            try {
                $notifications->notifySharedHostingCredentials($service->fresh(['user', 'product']));

                return back()->with('success', 'DirectAdmin credentials have been resent to the customer.');
            } catch (\Exception $e) {
                return back()->with('error', 'Failed to send credentials: '.$e->getMessage());
            }
        }

        // Only allow for VPS and Dedicated Server products
        if (! Product::isServerType($service->product->type)) {
            return back()->with('error', 'Credentials can only be resent for shared hosting, VPS, and dedicated server services.');
        }

        // Check if service has credentials
        if (! $service->credentials) {
            return back()->with('error', 'No credentials found for this service.');
        }

        try {
            $service->load(['user', 'product']);
            $notifications->notifyServerCredentials($service);

            $adminEmail = Setting::getValue('admin_email');
            if ($adminEmail) {
                app(EmailDeliveryService::class)->sendPlatformMailable(
                    $adminEmail,
                    new AdminServerOrderMail($service),
                    'Server credentials resent — '.$service->name,
                    NotificationEvent::AdminNewOrder,
                );
            }

            return back()->with('success', 'Credentials have been resent to the customer and admin.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send credentials: '.$e->getMessage());
        }
    }

    /**
     * Get service price based on billing cycle
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
