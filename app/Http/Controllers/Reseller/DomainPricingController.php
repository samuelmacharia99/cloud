<?php

namespace App\Http\Controllers\Reseller;

use App\Models\DomainExtension;
use App\Models\ResellerDomainPricing;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainPricingController extends Controller
{
    public function index()
    {
        $extensions = DomainExtension::with([
            'pricing' => fn($q) => $q->where('tier', 'wholesale'),
            'resellerPricing' => fn($q) => $q->where('reseller_id', auth()->id()),
        ])
        ->where('enabled', true)
        ->orderBy('extension')
        ->get();

        $periods = [1, 2, 3, 5, 10];

        return view('reseller.domains.pricing', compact('extensions', 'periods'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'domain_extension_id' => 'required|exists:domain_extensions,id',
            'period_years' => 'required|integer|in:1,2,3,5,10',
            'retail_price' => 'required|numeric|min:0',
            'enabled' => 'boolean',
        ]);

        $validated['reseller_id'] = auth()->id();
        $validated['enabled'] = $validated['enabled'] ?? true;

        ResellerDomainPricing::updateOrCreate(
            [
                'reseller_id' => auth()->id(),
                'domain_extension_id' => $validated['domain_extension_id'],
                'period_years' => $validated['period_years'],
            ],
            [
                'retail_price' => $validated['retail_price'],
                'enabled' => $validated['enabled'],
            ]
        );

        return back()->with('success', 'Domain pricing updated successfully.');
    }
}
