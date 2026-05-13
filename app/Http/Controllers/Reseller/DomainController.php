<?php

namespace App\Http\Controllers\Reseller;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\Service;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainController extends Controller
{
    /**
     * List all domains owned by the reseller
     */
    public function index(Request $request)
    {
        $resellerId = auth()->id();

        // Get customer IDs managed by this reseller (via services)
        $customerIds = Service::where('reseller_id', $resellerId)
            ->distinct()
            ->pluck('user_id');

        // Get all domains: those owned by the reseller or their managed customers
        // Also include domains where reseller_id = $resellerId (manually added domains)
        $domains = Domain::where(function ($q) use ($resellerId, $customerIds) {
            $q->where('user_id', $resellerId)
              ->orWhereIn('user_id', $customerIds)
              ->orWhere('reseller_id', $resellerId);
        })
            ->with('domainExtension', 'user')
            ->orderByDesc('created_at')
            ->paginate(12);

        // Debug info
        \Log::info('Reseller domains page', [
            'reseller_id' => $resellerId,
            'customer_ids' => $customerIds->toArray(),
            'domains_count' => $domains->total(),
            'domains' => $domains->map(fn($d) => $d->name . $d->extension)->toArray(),
        ]);

        // Get enabled domain extensions with wholesale and reseller pricing
        $extensions = DomainExtension::with([
            'pricing' => fn($q) => $q->where('tier', 'wholesale'),
            'resellerPricing' => fn($q) => $q->where('reseller_id', $resellerId),
        ])
        ->where('enabled', true)
        ->orderBy('extension')
        ->get();

        // Default period for pricing display
        $selectedPeriod = $request->get('period', 1);

        return view('reseller.domains.index', [
            'domains' => $domains,
            'extensions' => $extensions,
            'selectedPeriod' => $selectedPeriod,
            'periods' => [1, 2, 3, 5, 10],
        ]);
    }

    /**
     * Get wholesale pricing for a domain extension
     */
    public function getPricing(DomainExtension $extension, Request $request)
    {
        $period = $request->get('period', 1);

        // Get wholesale pricing from admin (resellers always pay wholesale)
        $wholesalePricing = $extension->pricing()
            ->where('tier', 'wholesale')
            ->where('period_years', $period)
            ->first();

        $price = $wholesalePricing?->price ?? 0;

        return response()->json([
            'price' => $price,
            'currency' => 'KES',
            'available' => $price > 0,
        ]);
    }
}
