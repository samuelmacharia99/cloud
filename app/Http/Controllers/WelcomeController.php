<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Currency;
use App\Models\Setting;
use Illuminate\Http\Request;

class WelcomeController extends Controller
{
    public function index()
    {
        // Fetch hosting packages (shared_hosting type)
        $packages = Product::where('type', 'shared_hosting')
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('welcome', [
            'packages' => $packages,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }
}
