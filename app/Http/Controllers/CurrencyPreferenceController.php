<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Services\UserCurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrencyPreferenceController extends Controller
{
    public function update(Request $request, UserCurrencyService $currencies): RedirectResponse
    {
        $validated = $request->validate([
            'currency' => 'required|string|size:3|exists:currencies,code',
        ]);

        $code = strtoupper($validated['currency']);

        if (! Currency::where('code', $code)->where('is_active', true)->exists()) {
            return back()->with('error', 'That currency is not available.');
        }

        $currencies->setPreference($request->user(), $code);

        return back()->with('success', 'Currency updated to '.$code.'.');
    }
}
