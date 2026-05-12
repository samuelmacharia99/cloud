<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Service;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\ResellerPackage;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // If reseller has a package but no expiry date, set it to 1 month from now
        if ($user->resellerPackage && !$user->package_expires_at) {
            $user->update([
                'package_expires_at' => now()->addMonth(),
            ]);
        }

        $services = Service::where('reseller_id', $user->id)
            ->with('user', 'product')
            ->get();

        $customerIds = $services->pluck('user_id')->unique();
        $customers = User::whereIn('id', $customerIds)->get();
        $packages = ResellerPackage::where('active', true)->orderBy('price')->get();

        // Get domains associated with this reseller and their customers
        $domains = Domain::where(function ($q) use ($customerIds, $user) {
            $q->whereIn('user_id', $customerIds)->orWhere('user_id', $user->id);
        })->with('user')->latest()->get();

        // Get enabled domain extensions for the add domain form
        $extensions = DomainExtension::where('enabled', true)
            ->orderBy('extension')->pluck('extension');

        // Build owner options: reseller first, then customers
        $ownerOptions = collect()
            ->push(['id' => $user->id, 'label' => $user->name . ' (' . $user->email . ') — Reseller'])
            ->merge($customers->map(fn($c) => ['id' => $c->id, 'label' => $c->name . ' (' . $c->email . ')']));

        // Get reseller's own subscription invoices
        $resellerInvoices = Invoice::where('user_id', $user->id)
            ->resellerSubscription()
            ->latest()
            ->get();

        return view('admin.resellers.show', compact('user', 'services', 'customerIds', 'customers', 'packages', 'domains', 'extensions', 'ownerOptions', 'resellerInvoices'));
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
            'package_expires_at'    => now()->addMonth(),
        ]);

        $package = ResellerPackage::find($validated['reseller_package_id']);
        return back()->with('success', "Package '{$package->name}' assigned to {$user->name}.");
    }

    public function upgradePackage(Request $request, User $user)
    {
        abort_if(!$user->is_reseller, 404);

        $validated = $request->validate([
            'reseller_package_id' => 'required|exists:reseller_packages,id',
        ]);

        $newPackage = ResellerPackage::find($validated['reseller_package_id']);

        // Update reseller's package
        $user->update([
            'reseller_package_id'   => $newPackage->id,
            'package_subscribed_at' => now(),
            'package_expires_at'    => now()->addMonth(),
        ]);

        // Generate invoice for the new plan
        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'type'           => 'reseller_subscription',
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'status'         => 'unpaid',
            'due_date'       => now()->addDays(7),
            'subtotal'       => $newPackage->price,
            'tax'            => 0,
            'total'          => $newPackage->price,
            'notes'          => "Reseller Package Upgrade: {$newPackage->name}",
        ]);

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', "Package upgraded to {$newPackage->name}. Invoice #{$invoice->invoice_number} generated.");
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

        Log::info('Admin adding domain to reseller', ['reseller_id' => $user->id]);

        $validated = $request->validate([
            'owner_id' => 'required|exists:users,id',
            'domain_name' => 'required|string|max:253',
            'extension' => 'required|exists:domain_extensions,extension',
            'status' => 'required|in:active,pending,expired,suspended',
            'registered_at' => 'nullable|date',
            'expires_at' => 'required|date',
            'next_invoice_date' => 'nullable|date',
            'nameserver_1' => 'nullable|string|max:255',
            'nameserver_2' => 'nullable|string|max:255',
            'auto_renew' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Build allowed owner IDs: reseller themselves or their customers
        $allowedIds = Service::where('reseller_id', $user->id)
            ->distinct()
            ->pluck('user_id')
            ->push($user->id)
            ->unique();

        abort_if(!$allowedIds->contains($validated['owner_id']), 403, 'This owner is not managed by this reseller.');

        // Parse domain name: strip extension suffix if present in input
        $domainInput = $validated['domain_name'];
        $ext = $validated['extension'];
        $bare = ltrim($ext, '.');

        if (str_ends_with($domainInput, '.' . $bare)) {
            $name = substr($domainInput, 0, -strlen('.' . $bare));
        } elseif (str_contains($domainInput, '.')) {
            $name = explode('.', $domainInput, 2)[0];
        } else {
            $name = $domainInput;
        }

        try {
            DB::transaction(function () use ($validated, $user, $name, $ext) {
                Domain::create([
                    'user_id' => $validated['owner_id'],
                    'reseller_id' => $user->id,
                    'name' => $name,
                    'extension' => $ext,
                    'status' => $validated['status'],
                    'type' => 'registration',
                    'registered_at' => $validated['registered_at'],
                    'expires_at' => $validated['expires_at'],
                    'next_invoice_date' => $validated['next_invoice_date'],
                    'nameserver_1' => $validated['nameserver_1'],
                    'nameserver_2' => $validated['nameserver_2'],
                    'auto_renew' => $validated['auto_renew'] ?? true,
                    'notes' => $validated['notes'],
                ]);

                Log::info('Domain created successfully', [
                    'reseller_id' => $user->id,
                    'owner_id' => $validated['owner_id'],
                    'domain' => $name . '.' . $ext,
                ]);
            });

            return back()->with('success', "Domain {$name}.{$ext} added successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to create domain', [
                'reseller_id' => $user->id,
                'owner_id' => $validated['owner_id'],
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to add domain. Please try again.')->withInput();
        }
    }
}
