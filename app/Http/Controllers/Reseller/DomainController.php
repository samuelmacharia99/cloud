<?php

namespace App\Http\Controllers\Reseller;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
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

        // Get all domains owned by the reseller
        $domains = Domain::where('user_id', $resellerId)
            ->with('domainExtension')
            ->orderByDesc('created_at')
            ->paginate(12);

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
        $resellerId = auth()->id();

        // Get wholesale pricing from admin
        $wholesalePricing = $extension->pricing()
            ->where('tier', 'wholesale')
            ->where('period_years', $period)
            ->first();

        // Get reseller's custom pricing (if set)
        $resellerPricing = $extension->resellerPricing()
            ->where('reseller_id', $resellerId)
            ->where('period_years', $period)
            ->first();

        // Use reseller pricing if available and enabled, otherwise use wholesale
        $retailPrice = null;
        if ($resellerPricing && $resellerPricing->enabled) {
            $retailPrice = $resellerPricing->retail_price;
        } else {
            // If no reseller pricing, use wholesale (or you could mark as unavailable)
            $retailPrice = $wholesalePricing?->price;
        }

        return response()->json([
            'wholesale_price' => $wholesalePricing?->price ?? 0,
            'retail_price' => $retailPrice ?? 0,
            'available' => !is_null($retailPrice),
        ]);
    }
}
