<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerPackageSubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerController extends Controller
{
    public function index(Request $request)
    {
        $resellers = User::where('is_reseller', true)
            ->withCount(['services as managed_services_count' => function ($query) {
                $query->whereColumn('reseller_id', 'users.id');
            }])
            ->withCount(['managedDomains as managed_domains_count'])
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
        abort_if(! $user->is_reseller, 404);

        $user->load('resellerPackage');

        $services = Service::where('reseller_id', $user->id)
            ->with('user', 'product')
            ->get();

        $customerIds = $services->pluck('user_id')->unique();
        $customers = User::whereIn('id', $customerIds)->get();
        $packages = ResellerPackage::where('active', true)->orderBy('price')->get();

        // Get domains associated with this reseller and their customers
        // Also include domains where reseller_id = $user->id (manually added domains)
        $domains = Domain::where(function ($q) use ($customerIds, $user) {
            $q->whereIn('user_id', $customerIds)
                ->orWhere('user_id', $user->id)
                ->orWhere('reseller_id', $user->id);
        })->with('user')->latest()->get();

        // Get enabled domain extensions for the add domain form
        $extensions = DomainExtension::where('enabled', true)
            ->orderBy('extension')->pluck('extension');

        // Build owner options: reseller first, then customers
        $ownerOptions = collect()
            ->push(['id' => $user->id, 'label' => $user->name.' ('.$user->email.') — Reseller'])
            ->merge($customers->map(fn ($c) => ['id' => $c->id, 'label' => $c->name.' ('.$c->email.')']));

        // Get all invoices for this reseller (includes domain renewals, subscriptions, etc.)
        $resellerInvoices = Invoice::where('user_id', $user->id)
            ->latest()
            ->get();

        $serverProducts = Product::whereIn('type', ['vps', 'dedicated_server'])
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('admin.resellers.show', compact('user', 'services', 'customerIds', 'customers', 'packages', 'domains', 'extensions', 'ownerOptions', 'resellerInvoices', 'serverProducts'));
    }

    public function promote(User $user)
    {
        $this->authorize('promote', $user);

        // is_reseller is intentionally not mass-assignable on User; set explicitly.
        $user->is_reseller = true;
        $user->save();

        return back()->with('success', 'User promoted to reseller.');
    }

    public function demote(User $user)
    {
        $this->authorize('demote', $user);

        // is_reseller is intentionally not mass-assignable on User; set explicitly.
        $user->is_reseller = false;
        $user->save();

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
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'reseller_package_id' => 'nullable|exists:reseller_packages,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = User::create(array_merge($validated, [
            'status' => 'active',
            'package_subscribed_at' => $validated['reseller_package_id'] ? now() : null,
        ]));

        // is_reseller is intentionally not mass-assignable on User; set explicitly.
        $user->is_reseller = true;
        $user->save();

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', "Reseller '{$user->name}' created successfully.");
    }

    public function edit(User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $packages = ResellerPackage::where('active', true)->orderBy('price')->get();

        return view('admin.resellers.edit', compact('user', 'packages'));
    }

    public function update(Request $request, User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'password' => 'nullable|min:8|confirmed',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'reseller_package_id' => 'nullable|exists:reseller_packages,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Only update password if provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', "Reseller '{$user->name}' updated successfully.");
    }

    public function assignPackage(Request $request, User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $validated = $request->validate([
            'reseller_package_id' => 'required|exists:reseller_packages,id',
        ]);

        $user->update([
            'reseller_package_id' => $validated['reseller_package_id'],
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ]);

        $package = ResellerPackage::find($validated['reseller_package_id']);

        return back()->with('success', "Package '{$package->name}' assigned to {$user->name}.");
    }

    public function upgradePackage(Request $request, User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $validated = $request->validate([
            'reseller_package_id' => 'required|exists:reseller_packages,id',
        ]);

        $newPackage = ResellerPackage::find($validated['reseller_package_id']);
        $subscriptionService = app(ResellerPackageSubscriptionService::class);

        $existingInvoice = $subscriptionService->pendingSubscriptionInvoice($user, $newPackage);
        $invoice = $existingInvoice ?: $subscriptionService->createSubscriptionInvoice($user, $newPackage);

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', "Upgrade invoice #{$invoice->invoice_number} generated. The new plan activates after payment.");
    }

    public function updateBilling(Request $request, User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $validated = $request->validate([
            'next_invoice_date' => 'required|date',
        ]);

        $user->update([
            'package_expires_at' => Carbon::parse($validated['next_invoice_date'])->addDays(5),
        ]);

        return redirect()->route('admin.resellers.show', $user)
            ->with('success', 'Billing dates updated successfully.');
    }

    public function impersonate(User $user)
    {
        abort_if(! $user->is_reseller, 404);

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
        abort_if(! $user->is_reseller, 404);

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

        abort_if(! $allowedIds->contains($validated['owner_id']), 403, 'This owner is not managed by this reseller.');

        // Parse domain name: strip extension suffix if present in input
        $domainInput = $validated['domain_name'];
        $ext = $validated['extension'];
        $bare = ltrim($ext, '.');

        if (str_ends_with($domainInput, '.'.$bare)) {
            $name = substr($domainInput, 0, -strlen('.'.$bare));
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
                    'domain' => $name.'.'.$ext,
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

    public function addService(Request $request, User $user)
    {
        abort_if(! $user->is_reseller, 404);

        $validated = $request->validate([
            'owner_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'status' => 'required|in:active,pending,provisioning,suspended,terminated,failed,cancelled',
            'commenced_at' => 'nullable|date|before_or_equal:next_due_date',
            'next_due_date' => 'required|date',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'notes' => 'nullable|string|max:1000',
            'generate_invoice' => 'boolean',
        ]);

        // Owner must be the reseller themselves or one of their managed customers
        $allowedIds = Service::where('reseller_id', $user->id)
            ->distinct()->pluck('user_id')
            ->push($user->id)->unique();

        abort_if(! $allowedIds->contains((int) $validated['owner_id']), 403, 'This owner is not managed by this reseller.');

        $product = Product::findOrFail($validated['product_id']);
        abort_if(! in_array($product->type, ['vps', 'dedicated_server']), 422, 'Only VPS and Dedicated Server products can be added to resellers.');
        abort_if(! $product->is_active, 422, 'Product is not available.');

        try {
            $createdService = DB::transaction(function () use ($validated, $user, $product) {
                $serviceMeta = [];
                if (! empty($validated['username'])) {
                    $serviceMeta['username'] = $validated['username'];
                }
                if (! empty($validated['password'])) {
                    $serviceMeta['password'] = $validated['password'];
                }
                if (! empty($validated['ip_address'])) {
                    $serviceMeta['ip_address'] = $validated['ip_address'];
                }

                $service = Service::create([
                    'user_id' => $validated['owner_id'],
                    'reseller_id' => $user->id,
                    'product_id' => $validated['product_id'],
                    'name' => $validated['name'],
                    'status' => $validated['status'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'commenced_at' => $validated['commenced_at'] ?? null,
                    'next_due_date' => $validated['next_due_date'],
                    'provisioning_driver_key' => $product->provisioning_driver_key,
                    'service_meta' => ! empty($serviceMeta) ? $serviceMeta : null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                if (! empty($validated['generate_invoice'])) {
                    $invoice = $this->createResellerServiceInvoice($user, $product, $service, $validated);
                    $service->update(['invoice_id' => $invoice->id]);
                }

                return $service;
            });

            Log::info('Admin added service to reseller', [
                'reseller_id' => $user->id,
                'service_id' => $createdService->id,
                'product_id' => $product->id,
            ]);

            return back()->with('success', "Service '{$validated['name']}' added to {$user->name} successfully.");
        } catch (\Exception $e) {
            Log::error('Admin addService to reseller failed', [
                'reseller_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to add service: '.$e->getMessage())->withInput();
        }
    }

    private function createResellerServiceInvoice(User $reseller, Product $product, Service $service, array $validated): Invoice
    {
        $monthlyBase = (float) ($product->wholesale_monthly_price ?? $product->monthly_price ?? 0);
        $price = match ($validated['billing_cycle']) {
            'monthly' => $monthlyBase,
            'quarterly' => $monthlyBase * 3,
            'semi-annual' => $monthlyBase * 6,
            'annual' => (float) ($product->wholesale_yearly_price ?? ($monthlyBase * 12)),
            default => 0,
        };

        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
        $total = $price + $tax;

        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;
        $number = $prefix.'-'.$date.'-'.str_pad($count, 5, '0', STR_PAD_LEFT);

        $dueDate = Carbon::parse($validated['next_due_date'])->subDays(10);

        $invoice = Invoice::create([
            'user_id' => $reseller->id,
            'invoice_number' => $number,
            'status' => 'unpaid',
            'due_date' => $dueDate,
            'subtotal' => $price,
            'tax' => $tax,
            'total' => $total,
            'notes' => 'Reseller Service: '.$service->name,
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

        return $invoice;
    }
}
