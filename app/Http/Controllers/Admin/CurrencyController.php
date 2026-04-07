<?php

namespace App\Http\Controllers\Admin;

use App\Models\Currency;
use App\Services\CurrencyConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurrencyController
{
    /**
     * Show currency management page
     */
    public function index(Request $request): View
    {
        $currencies = Currency::orderBy('order')->get();
        $baseCurrency = Currency::getBaseCurrency();
        $conversionService = new CurrencyConversionService();
        $ratesStale = $conversionService->areRatesStale();

        return view('admin.settings.currencies.index', compact(
            'currencies',
            'baseCurrency',
            'ratesStale'
        ));
    }

    /**
     * Update currency settings
     */
    public function update(Currency $currency, Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'symbol' => 'required|string|max:10',
                'is_active' => 'boolean',
                'order' => 'required|integer|min:1',
            ]);

            $currency->update($request->only(['name', 'symbol', 'is_active', 'order']));

            return back()->with('success', "Currency '{$currency->code}' updated successfully");
        } catch (\Exception $e) {
            \Log::error("Failed to update currency {$currency->code}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to update currency']);
        }
    }

    /**
     * Manually refresh exchange rates
     */
    public function refreshRates(): RedirectResponse
    {
        try {
            $service = new CurrencyConversionService();
            $rates = $service->forceUpdateRates();

            return back()->with('success', "Exchange rates updated for " . count($rates) . " currencies");
        } catch (\Exception $e) {
            \Log::error('Failed to refresh exchange rates: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to refresh exchange rates: ' . $e->getMessage()]);
        }
    }

    /**
     * Test currency conversion
     */
    public function testConversion(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'from_currency' => 'required|exists:currencies,code',
                'to_currency' => 'required|exists:currencies,code',
            ]);

            $service = new CurrencyConversionService();
            $converted = $service->convert(
                $request->amount,
                $request->from_currency,
                $request->to_currency
            );

            return response()->json([
                'success' => true,
                'amount' => $request->amount,
                'from_currency' => $request->from_currency,
                'to_currency' => $request->to_currency,
                'converted_amount' => round($converted, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Add a new currency
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'code' => 'required|string|size:3|unique:currencies,code',
                'name' => 'required|string|max:255',
                'symbol' => 'required|string|max:10',
            ]);

            Currency::create([
                'code' => strtoupper($request->code),
                'name' => $request->name,
                'symbol' => $request->symbol,
                'exchange_rate' => 1.0,
                'is_active' => true,
                'order' => Currency::max('order') + 1,
            ]);

            return back()->with('success', "Currency '{$request->code}' added successfully");
        } catch (\Exception $e) {
            \Log::error('Failed to add currency: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to add currency']);
        }
    }

    /**
     * Delete a currency
     */
    public function destroy(Currency $currency): RedirectResponse
    {
        try {
            if ($currency->code === 'KES') {
                return back()->withErrors(['error' => 'Cannot delete the base currency (Kenya Shilling)']);
            }

            $code = $currency->code;
            $currency->delete();

            return back()->with('success', "Currency '{$code}' deleted successfully");
        } catch (\Exception $e) {
            \Log::error("Failed to delete currency {$currency->code}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to delete currency']);
        }
    }
}
