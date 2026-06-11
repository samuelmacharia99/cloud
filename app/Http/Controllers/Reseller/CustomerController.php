<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Services\ResellerCustomerWelcomeService;
use App\Services\ServiceEnforcementInsightService;
use App\Services\UserCurrencyService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('reseller_id', auth()->id())->latest();

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

        $customers = $query->withCount('services', 'invoices')->paginate(15)->withQueryString();

        // Get package limits for display
        $resellerPackage = auth()->user()->resellerPackage;
        $customerCount = auth()->user()->getManagedCustomersCount();

        return view('reseller.customers.index', compact('customers', 'resellerPackage', 'customerCount'));
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

        return view('reseller.customers.show', compact('customer', 'enforcementAlerts'));
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

        $customerName = $customer->name;
        $customer->delete();

        return redirect()->route('reseller.customers.index')
            ->with('success', "Customer '{$customerName}' has been deleted successfully.");
    }

    public function impersonate(User $customer)
    {
        $this->checkOwnership($customer);

        // Store the reseller ID in session for later exit
        session(['impersonating_reseller' => auth()->id(), 'impersonating_user_id' => $customer->id]);

        // Log out the current reseller and log in as the customer
        auth()->logout();
        auth()->loginUsingId($customer->id);

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing the dashboard as {$customer->name}.");
    }

    public function exitImpersonation()
    {
        if (! session('impersonating_reseller')) {
            return redirect()->route('dashboard');
        }

        $resellerId = session('impersonating_reseller');

        $reseller = User::find($resellerId);
        if (! $reseller || ! $reseller->is_reseller) {
            session()->forget(['impersonating_reseller', 'impersonating_user_id']);
            auth()->logout();
            abort(403, 'Invalid impersonation session');
        }

        // Clear impersonation session data
        session()->forget(['impersonating_reseller', 'impersonating_user_id']);

        // Log out and log back in as reseller
        auth()->logout();
        auth()->loginUsingId($resellerId);

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
