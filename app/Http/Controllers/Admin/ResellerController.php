<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Service;
use App\Models\Domain;
use App\Models\ResellerPackage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ResellerController extends Controller
{
    public function index(Request $request)
    {
        $resellers = User::where('is_reseller', true)
            ->withCount(['services as managed_services_count' => function ($query) {
                $query->whereColumn('reseller_id', 'users.id');
            }])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Calculate total services managed by all resellers
        $totalServices = Service::whereIn('reseller_id',
            User::where('is_reseller', true)->pluck('id')
        )->count();

        // Calculate unique customers served by all resellers
        $totalCustomers = User::whereIn('id',
            Service::whereIn('reseller_id',
                User::where('is_reseller', true)->pluck('id')
            )->distinct()->pluck('user_id')
        )->count();

        return view('admin.resellers.index', compact('resellers', 'totalServices', 'totalCustomers'));
    }

    public function show(User $user)
    {
        abort_if(!$user->is_reseller, 404);

        $user->load('resellerPackage');

        $services = Service::where('reseller_id', $user->id)
            ->with('user', 'product')
            ->get();

        $customerIds = $services->pluck('user_id')->unique();
        $customers = User::whereIn('id', $customerIds)->get();
        $packages = ResellerPackage::where('active', true)->orderBy('price')->get();

        // Get domains associated with this reseller's customers
        $domains = Domain::whereIn('user_id', $customerIds)
            ->with('user')
            ->latest()
            ->get();

        return view('admin.resellers.show', compact('user', 'services', 'customerIds', 'customers', 'packages', 'domains'));
    }

    public function promote(User $user)
    {
        $this->authorize('promote', $user);

        $user->update(['is_reseller' => true]);
        return back()->with('success', 'User promoted to reseller.');
    }

    public function demote(User $user)
    {
        $this->authorize('demote', $user);

        $user->update(['is_reseller' => false]);
        return back()->with('success', 'Reseller status removed.');
    }

    public function create()
    {
        $packages = ResellerPackage::where('active', true)->orderBy('price')->get();
        return view('admin.resellers.create', compact('packages'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'email'               => 'required|email|unique:users,email',
            'password'            => 'required|min:8|confirmed',
            'phone'               => 'nullable|string|max:30',
            'company'             => 'nullable|string|max:255',
            'country'             => 'nullable|string|max:100',
            'reseller_package_id' => 'nullable|exists:reseller_packages,id',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $user = User::create(array_merge($validated, [
            'is_reseller'           => true,
            'status'                => 'active',
            'package_subscribed_at' => $validated['reseller_package_id'] ? now() : null,
        ]));

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', "Reseller '{$user->name}' created successfully.");
    }

    public function assignPackage(Request $request, User $user)
    {
        abort_if(!$user->is_reseller, 404);

        $validated = $request->validate([
            'reseller_package_id' => 'required|exists:reseller_packages,id',
        ]);

        $user->update([
            'reseller_package_id'   => $validated['reseller_package_id'],
            'package_subscribed_at' => now(),
        ]);

        $package = ResellerPackage::find($validated['reseller_package_id']);
        return back()->with('success', "Package '{$package->name}' assigned to {$user->name}.");
    }

    public function impersonate(User $user)
    {
        abort_if(!$user->is_reseller, 404);

        // Store the admin ID in session for later exit
        session(['impersonating' => auth()->id(), 'impersonating_user_id' => $user->id]);

        // Log out the current admin and log in as the reseller
        auth()->logout();
        auth()->loginUsingId($user->id);

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing the dashboard as {$user->name}.");
    }

    public function addDomain(Request $request, User $user)
    {
        abort_if(!$user->is_reseller, 404);

        $validated = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'domain_name' => 'required|string|max:255',
            'extension' => 'required|string|max:20',
            'registered_at' => 'required|date',
            'expires_at' => 'required|date|after:registered_at',
            'next_invoice_date' => 'required|date|after_or_equal:registered_at',
        ]);

        // Verify the customer is managed by this reseller
        $customer = User::find($validated['customer_id']);
        $customerIds = Service::where('reseller_id', $user->id)
            ->distinct()
            ->pluck('user_id');

        abort_if(!$customerIds->contains($customer->id), 403, 'This customer is not managed by this reseller.');

        Domain::create([
            'user_id' => $validated['customer_id'],
            'name' => $validated['domain_name'],
            'extension' => $validated['extension'],
            'registered_at' => $validated['registered_at'],
            'expires_at' => $validated['expires_at'],
            'next_invoice_date' => $validated['next_invoice_date'],
            'status' => 'active',
        ]);

        return back()->with('success', "Domain {$validated['domain_name']}{$validated['extension']} added successfully.");
    }
}
