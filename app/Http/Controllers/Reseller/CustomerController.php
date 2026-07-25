<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Services\AdminActivityService;
use App\Services\ResellerCustomerWelcomeService;
use App\Services\ResellerHostedAccountDirectoryService;
use App\Services\ServiceEnforcementInsightService;
use App\Services\UserCurrencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function index(Request $request, ResellerHostedAccountDirectoryService $directory)
    {
        $reseller = auth()->user();
        $directoryResult = $directory->paginatedForReseller($reseller, $request);

        $resellerPackage = $reseller->resellerPackage;
        $customerCount = $reseller->getResellerUserCountForLimits();
        $hostedUserCountSource = $reseller->getResellerUserCountBreakdown()['source'];

        $catalogListings = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->orderBy('name')
            ->get(['id', 'name', 'direct_admin_package_name', 'monthly_price', 'yearly_price']);

        $managedCustomers = User::query()
            ->where('reseller_id', $reseller->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('reseller.customers.index', [
            'customers' => $directoryResult['rows'],
            'directoryStats' => $directoryResult['stats'],
            'usesDirectAdminDirectory' => $directoryResult['uses_directadmin'],
            'resellerPackage' => $resellerPackage,
            'customerCount' => $customerCount,
            'hostedUserCountSource' => $hostedUserCountSource,
            'catalogListings' => $catalogListings,
            'managedCustomers' => $managedCustomers,
        ]);
    }

    public function create()
    {
        // Check package limits
        if (auth()->user()->isAtUserLimit()) {
            return redirect()->back()->with('error', 'You have reached your customer limit. Upgrade your package to add more customers.');
        }

        return view('reseller.customers.create');
    }

    public function store(Request $request)
    {
        // Check package limits before creating
        if (auth()->user()->isAtUserLimit()) {
            return redirect()->back()->with('error', 'You have reached your customer limit. Upgrade your package to add more customers.');
        }

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

        $sendWelcomeEmail = $request->boolean('send_welcome_email');
        $plainPassword = $validated['password'];
        unset($validated['send_welcome_email']);

        $customer = User::create([
            ...$validated,
            'reseller_id' => auth()->id(),
            'is_reseller' => false,
        ]);

        app(UserCurrencyService::class)->syncFromCountry($customer, true);

        $flash = 'Customer created successfully.';

        if ($sendWelcomeEmail) {
            try {
                app(ResellerCustomerWelcomeService::class)->send(auth()->user(), $customer, $plainPassword);
                $flash .= ' Welcome email sent.';
            } catch (\Throwable $e) {
                $flash .= ' Welcome email could not be sent: '.$e->getMessage();
            }
        }

        return redirect()->route('reseller.customers.index')
            ->with('success', $flash);
    }

    public function show(User $customer)
    {
        $this->checkOwnership($customer);

        $customer->load(
            'services.product',
            'invoices',
            'payments',
            'domains'
        );

        $enforcementAlerts = app(ServiceEnforcementInsightService::class)
            ->alertsForCustomerServices($customer->services);

        $catalogProducts = ResellerProduct::query()
            ->where('reseller_id', auth()->id())
            ->where('is_active', true)
            ->with('adminProduct')
            ->orderBy('name')
            ->get();

        $catalogProductsForJs = $catalogProducts->map(function (ResellerProduct $listing) {
            return [
                'id' => $listing->id,
                'name' => $listing->name,
                'type' => $listing->type ?? $listing->adminProduct?->type,
                'monthly_price' => $listing->monthly_price,
                'yearly_price' => $listing->yearly_price,
                'uses_direct_admin_package' => $listing->usesDirectAdminPackage(),
                'direct_admin_package_name' => $listing->direct_admin_package_name,
            ];
        })->values()->toArray();

        $catalogByProductId = $catalogProducts
            ->filter(fn (ResellerProduct $item) => $item->product_id !== null)
            ->keyBy('product_id');

        $servicesForJs = $customer->services->map(function ($service) use ($catalogProducts, $catalogByProductId) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $resellerProductId = $meta['reseller_product_id'] ?? null;

            if (! $resellerProductId && $service->product_id) {
                $resellerProductId = $catalogByProductId->get($service->product_id)?->id;
            }

            if (! $resellerProductId && ! empty($meta['package_name'])) {
                $resellerProductId = $catalogProducts
                    ->first(fn (ResellerProduct $item) => $item->direct_admin_package_name === $meta['package_name'])
                    ?->id;
            }

            $driver = $service->provisioning_driver_key ?? $service->product?->provisioning_driver_key;

            return [
                'id' => $service->id,
                'name' => $service->name,
                'reseller_product_id' => $resellerProductId,
                'product_type' => $service->product?->type,
                'billing_cycle' => $service->billing_cycle ?? 'monthly',
                'custom_price' => $service->custom_price,
                'next_due_date' => $service->next_due_date?->format('Y-m-d') ?? '',
                'commenced_at' => $service->commenced_at?->format('Y-m-d') ?? '',
                'status' => $service->status->value,
                'is_directadmin' => $driver === 'directadmin',
                'username' => $meta['username'] ?? $service->external_reference ?? '',
                'domain' => $meta['domain'] ?? '',
                'has_hosting_account' => filled($service->external_reference) || filled($meta['username'] ?? null),
            ];
        })->values()->toArray();

        return view('reseller.customers.show', compact(
            'customer',
            'enforcementAlerts',
            'catalogProductsForJs',
            'servicesForJs',
        ));
    }

    public function edit(User $customer)
    {
        $this->checkOwnership($customer);

        return view('reseller.customers.edit', compact('customer'));
    }

    public function update(Request $request, User $customer)
    {
        $this->checkOwnership($customer);

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

        return redirect()->route('reseller.customers.show', $customer)
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy(User $customer)
    {
        $this->checkOwnership($customer);

        $liveServices = Service::query()
            ->where('user_id', $customer->id)
            ->whereNotIn('status', [
                ServiceStatus::Terminated->value,
                ServiceStatus::Cancelled->value,
                ServiceStatus::Failed->value,
            ])
            ->count();

        if ($liveServices > 0) {
            return redirect()->back()->with(
                'error',
                "Cannot delete {$customer->name}: they still have {$liveServices} service(s) that are not terminated. Terminate those services first, then delete the customer."
            );
        }

        $liveDomains = Domain::query()
            ->where('user_id', $customer->id)
            ->whereIn('status', ['active', 'pending', 'transferring'])
            ->count();

        if ($liveDomains > 0) {
            return redirect()->back()->with(
                'error',
                "Cannot delete {$customer->name}: they still have {$liveDomains} active or pending domain(s). Remove or transfer those domains first."
            );
        }

        $customerName = $customer->name;
        $customer->delete();

        return redirect()->route('reseller.customers.index')
            ->with('success', "Customer '{$customerName}' has been deleted successfully.");
    }

    public function impersonate(User $customer)
    {
        $this->checkOwnership($customer);

        $reseller = auth()->user();

        AdminActivityService::log(
            'reseller.customer.impersonate',
            "Reseller {$reseller->name} started impersonating customer {$customer->name}",
            $customer,
            ['reseller_id' => $reseller->id],
        );

        Log::info('Reseller started customer impersonation', [
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
        ]);

        session([
            'impersonating_reseller' => $reseller->id,
            'impersonating_user_id' => $customer->id,
        ]);

        auth()->logout();
        session()->regenerate();
        auth()->loginUsingId($customer->id);
        session()->regenerate();

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing the dashboard as {$customer->name}.");
    }

    public function exitImpersonation()
    {
        if (! session('impersonating_reseller')) {
            return redirect()->route('dashboard');
        }

        $resellerId = (int) session('impersonating_reseller');
        $customerId = session('impersonating_user_id');

        $reseller = User::find($resellerId);
        if (! $reseller || ! $reseller->is_reseller) {
            session()->forget(['impersonating_reseller', 'impersonating_user_id']);
            auth()->logout();
            abort(403, 'Invalid impersonation session');
        }

        session()->forget(['impersonating_reseller', 'impersonating_user_id']);

        auth()->logout();
        session()->regenerate();
        auth()->loginUsingId($resellerId);
        session()->regenerate();

        Log::info('Reseller exited customer impersonation', [
            'reseller_id' => $resellerId,
            'customer_id' => $customerId,
        ]);

        return redirect()->route('reseller.customers.index')
            ->with('success', 'Exited customer view.');
    }

    /**
     * Check if customer belongs to the authenticated reseller
     */
    private function checkOwnership(User $customer): void
    {
        if ($customer->reseller_id !== auth()->id()) {
            abort(404);
        }
    }
}
