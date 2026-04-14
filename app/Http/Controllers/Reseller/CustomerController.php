<?php

namespace App\Http\Controllers\Reseller;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

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
        $customerCount = auth()->user()->customers()->count();

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
            'country' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'vat_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,suspended,inactive',
        ]);

        User::create([
            ...$validated,
            'reseller_id' => auth()->id(),
            'is_reseller' => false,
        ]);

        return redirect()->route('reseller.customers.index')
            ->with('success', 'Customer created successfully.');
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

        return view('reseller.customers.show', compact('customer'));
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
