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
            'tickets'
        );

        return view('admin.customers.show', compact('customer'));
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
}
