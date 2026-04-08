<?php

namespace App\Http\Controllers\Admin;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainController extends Controller
{
    public function index(Request $request)
    {
        $query = Domain::with('user', 'domainExtension');

        // Search by domain name
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        // Filter by extension
        if ($request->filled('extension')) {
            $query->where('extension', $request->extension);
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by registrar
        if ($request->filled('registrar')) {
            $query->where('registrar', $request->registrar);
        }

        // Filter by owner (user search)
        if ($request->filled('owner')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->owner}%")
                  ->orWhere('email', 'like', "%{$request->owner}%");
            });
        }

        // Filter by expiry date range
        if ($request->filled('expires_from')) {
            $query->whereDate('expires_at', '>=', $request->expires_from);
        }
        if ($request->filled('expires_to')) {
            $query->whereDate('expires_at', '<=', $request->expires_to);
        }

        // Filter by registration date range
        if ($request->filled('registered_from')) {
            $query->whereDate('registered_at', '>=', $request->registered_from);
        }
        if ($request->filled('registered_to')) {
            $query->whereDate('registered_at', '<=', $request->registered_to);
        }

        // Filter by days until expiry
        if ($request->filled('expiry_warning')) {
            $days = (int)$request->expiry_warning;
            $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
        }

        $domains = $query->latest()->paginate(20)->withQueryString();

        // Get distinct values for filter dropdowns
        $extensions = DomainExtension::where('enabled', true)->orderBy('extension')->pluck('extension');
        $statuses = ['active', 'expired', 'suspended'];
        $registrars = Domain::distinct()->pluck('registrar')->filter()->sort();

        return view('admin.domains.index', compact('domains', 'extensions', 'statuses', 'registrars'));
    }

    public function pricing(Request $request)
    {
        $extensions = DomainExtension::with('pricing')->orderBy('extension')->get();
        $periods = [1, 2, 3, 5, 10];

        // If showing pricing for a specific extension
        $selectedExtension = null;
        if ($request->filled('extension_id')) {
            $selectedExtension = DomainExtension::findOrFail($request->extension_id);
        }

        return view('admin.domains.pricing', compact('extensions', 'periods', 'selectedExtension'));
    }

    public function storePricing(Request $request)
    {
        $validated = $request->validate([
            'domain_extension_id' => 'required|exists:domain_extensions,id',
            'period_years' => 'required|in:1,2,3,5,10',
            'retail_price' => 'required|numeric|min:0',
            'retail_setup_fee' => 'nullable|numeric|min:0',
            'wholesale_price' => 'required|numeric|min:0',
            'wholesale_setup_fee' => 'nullable|numeric|min:0',
        ]);

        // Save retail pricing
        DomainPricing::updateOrCreate(
            [
                'domain_extension_id' => $validated['domain_extension_id'],
                'period_years' => $validated['period_years'],
                'tier' => 'retail',
            ],
            [
                'price' => $validated['retail_price'],
                'setup_fee' => $validated['retail_setup_fee'] ?? 0,
                'enabled' => true,
            ]
        );

        // Save wholesale pricing
        DomainPricing::updateOrCreate(
            [
                'domain_extension_id' => $validated['domain_extension_id'],
                'period_years' => $validated['period_years'],
                'tier' => 'wholesale',
            ],
            [
                'price' => $validated['wholesale_price'],
                'setup_fee' => $validated['wholesale_setup_fee'] ?? 0,
                'enabled' => true,
            ]
        );

        return redirect()->route('admin.domains.pricing', ['extension_id' => $validated['domain_extension_id']])
            ->with('success', 'Pricing updated successfully.');
    }

    public function storeExtension(Request $request)
    {
        $validated = $request->validate([
            'extension' => 'required|string|unique:domain_extensions,extension',
            'description' => 'nullable|string',
            'registrar' => 'required|string',
            'dns_management' => 'nullable|boolean',
            'auto_renewal' => 'nullable|boolean',
            'transfer_price' => 'nullable|numeric|min:0',
        ]);

        $validated['dns_management'] = $request->has('dns_management');
        $validated['auto_renewal'] = $request->has('auto_renewal');
        $validated['enabled'] = true;
        $validated['transfer_price'] = $validated['transfer_price'] ?? 0;

        DomainExtension::create($validated);

        return redirect()->route('admin.domains.pricing')
            ->with('success', 'Domain extension added successfully.');
    }

    public function show(Domain $domain)
    {
        $domain->load('user', 'domainExtension', 'dnsZones');

        return view('admin.domains.show', compact('domain'));
    }

    public function edit(Domain $domain)
    {
        $extensions = DomainExtension::where('enabled', true)->orderBy('extension')->pluck('extension');

        return view('admin.domains.edit', compact('domain', 'extensions'));
    }

    public function update(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'extension' => 'required|exists:domain_extensions,extension',
            'registrar' => 'required|string',
            'status' => 'required|in:active,expired,suspended',
            'registered_at' => 'nullable|date',
            'expires_at' => 'required|date',
            'auto_renew' => 'nullable|boolean',
            'nameserver_1' => 'nullable|string',
            'nameserver_2' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $validated['auto_renew'] = $request->has('auto_renew');

        $domain->update($validated);

        return redirect()->route('admin.domains.show', $domain)
            ->with('success', 'Domain updated successfully.');
    }
}
